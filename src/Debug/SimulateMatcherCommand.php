<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Debug;

use DateTimeImmutable;
use DateTimeZone;
use Enthusiast\OrderPool\Matching\DeficitMatcher;
use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\OrderPool\Schedule\OrderAvailabilityNormalizer;
use Enthusiast\OrderPool\ValueObject\Order;
use Enthusiast\WorkerTemplate\RedisClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run WD-RR simulation from a JSON scenario and write an HTML report.
 *
 * Example:
 *   php yii order-pool:simulate irev-200-vs-300.json
 */
final class SimulateMatcherCommand extends Command
{
    protected static string $defaultName = 'order-pool:simulate';

    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly OrderAvailabilityNormalizer $availabilityNormalizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Simulate WD-RR matching from a JSON scenario file')
            ->addArgument('scenario', InputArgument::REQUIRED, 'Scenario JSON file name or path (built-in: irev-200-vs-300.json)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scenarioPath = (string) $input->getArgument('scenario');
        $scenario = SimulateScenario::fromJsonFile($scenarioPath);

        if ($scenario->presetId <= 0) {
            throw new \InvalidArgumentException('scenario preset_id must be > 0');
        }
        if ($scenario->leads <= 0) {
            throw new \InvalidArgumentException('scenario leads must be > 0');
        }

        $keys = new KeySchema($scenario->prefix);
        $this->resetPool($keys, $scenario);

        $matcher = new DeficitMatcher($this->redis, $keys, rateExponent: $scenario->rateExponent);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $utcTs = (int) $now->format('U');

        /** @var array<string, string> $orderLabels */
        $orderLabels = [];
        foreach ($scenario->orders as $order) {
            $orderLabels[$order->id] = $order->displayLabel();
            if ($order->source === 'irev') {
                $expectedId = Order::irevOrderId($scenario->presetId, $order->partnerId);
                if ($order->id !== $expectedId) {
                    throw new \InvalidArgumentException(
                        "IREV order id must be $expectedId, got $order->id",
                    );
                }
            }
        }

        $rows = [];
        $winnerCounts = [];
        $stockCount = 0;

        for ($lead = 1; $lead <= $scenario->leads; $lead++) {
            $result = $matcher->match($scenario->presetId, dryRun: true);

            $historyLine = $this->fetchLastHistoryLine($keys->presetHistoryKey($scenario->presetId));
            $sold = $this->fetchSoldSnapshot($keys, $scenario->orders, $utcTs);

            if ($result === null) {
                $winnerCounts['STOCK'] = ($winnerCounts['STOCK'] ?? 0) + 1;
                $stockCount++;
                $rows[] = [
                    'lead' => $lead,
                    'winner' => 'STOCK',
                    'kind' => 'stock',
                    'line' => $historyLine,
                    'sold' => $sold,
                ];
                continue;
            }

            if (is_string($result)) {
                throw new \RuntimeException("Unexpected match result: {$result}");
            }

            [$kind, $refId, $partnerId] = array_pad($result, 3, null);
            $winnerKey = $this->resolveWinnerKey($kind, $refId, $partnerId, $scenario->orders, $scenario->presetId);
            $winnerLabel = $orderLabels[$winnerKey] ?? (string) $refId;
            $winnerCounts[$winnerLabel] = ($winnerCounts[$winnerLabel] ?? 0) + 1;

            $rows[] = [
                'lead' => $lead,
                'winner' => $winnerLabel,
                'kind' => is_string($kind) ? $kind : 'unknown',
                'line' => $historyLine,
                'sold' => $sold,
            ];
        }

        $matched = $scenario->leads - $stockCount;
        $html = $this->buildHtml($scenario, $rows, $winnerCounts, $matched, $stockCount);
        $dir = dirname($scenario->output);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($scenario->output, $html);

        $output->writeln(sprintf('Scenario: %s', $scenario->name));
        $output->writeln(sprintf('Leads: %d (matched: %d, stock: %d)', $scenario->leads, $matched, $stockCount));
        $output->writeln('Distribution:');
        foreach ($winnerCounts as $label => $count) {
            $pct = round(100 * $count / $scenario->leads, 1);
            $output->writeln(sprintf('  %s: %d (%.1f%%)', $label, $count, $pct));
        }
        $output->writeln(sprintf('Report: %s', $scenario->output));

        return Command::SUCCESS;
    }

    private function resetPool(KeySchema $keys, SimulateScenario $scenario): void
    {
        $poolKey = $keys->presetOrderPoolKey($scenario->presetId);
        $orderIds = [];

        foreach ($scenario->orders as $order) {
            $orderIds[] = $order->id;
            $this->seedOrder($keys, $order);
        }

        $this->redis->del($poolKey);
        $this->redis->del($keys->presetHistoryKey($scenario->presetId));

        if ($orderIds !== []) {
            $this->redis->rawCommand('SADD', $poolKey, ...$orderIds);
        }
    }

    private function seedOrder(KeySchema $keys, SimulateOrder $order): void
    {
        $availability = $this->availabilityNormalizer->fromIrev(
            $order->schedule,
            $order->scheduleTz,
            $order->dailyTzOffset !== 0 ? $order->dailyTzOffset : null,
        );

        $this->redis->hMSet($keys->orderDataKey($order->id), [
            'source' => $order->source,
            'partner_id' => $order->partnerId,
            'rate' => (string) $order->rate,
            'availability_utc' => $availability->availabilityUtc,
            'capacity' => (string) $order->capacity,
            'daily_tz_offset' => (string) $availability->dailyTzOffset,
        ]);

        $localDay = $this->availabilityNormalizer->resolveLocalDay($availability->dailyTzOffset);
        $this->redis->del($keys->orderSoldDryKey($order->id, $localDay));
        $this->redis->del($keys->orderSoldKey($order->id, $localDay));
    }

    /**
     * @param list<SimulateOrder> $orders
     *
     * @return array<string, int>
     */
    private function fetchSoldSnapshot(KeySchema $keys, array $orders, int $utcTs): array
    {
        $sold = [];
        foreach ($orders as $order) {
            $localDay = $this->availabilityNormalizer->resolveLocalDay($order->dailyTzOffset, $utcTs);
            $value = $this->redis->rawCommand('GET', $keys->orderSoldDryKey($order->id, $localDay));
            $sold[$order->displayLabel()] = is_string($value) || is_int($value) ? (int) $value : 0;
        }

        return $sold;
    }

    private function fetchLastHistoryLine(string $historyKey): string
    {
        $res = $this->redis->rawCommand('LINDEX', $historyKey, -1);

        return is_string($res) ? $res : '';
    }

    /**
     * @param list<SimulateOrder> $orders
     */
    private function resolveWinnerKey(mixed $kind, mixed $refId, mixed $partnerId, array $orders, int $presetId): string
    {
        if ($kind === 'lm') {
            return (string) $refId;
        }

        foreach ($orders as $order) {
            if ($order->partnerId === $partnerId) {
                return $order->id;
            }
        }

        return Order::irevOrderId($presetId, (string) $partnerId);
    }

    /**
     * @param list<array{lead: int, winner: string, kind: string, line: string, sold: array<string, int>}> $rows
     * @param array<string, int> $winnerCounts
     */
    private function buildHtml(
        SimulateScenario $scenario,
        array $rows,
        array $winnerCounts,
        int $matched,
        int $stockCount,
    ): string {
        $soldLabels = array_map(static fn (SimulateOrder $o) => $o->displayLabel(), $scenario->orders);

        $summaryRows = '';
        foreach ($winnerCounts as $label => $count) {
            $pctTotal = round(100 * $count / $scenario->leads, 1);
            $pctMatched = $matched > 0 && $label !== 'STOCK'
                ? round(100 * $count / $matched, 1)
                : null;
            $matchedCol = $pctMatched !== null ? sprintf('%.1f%% of matched', $pctMatched) : '—';
            $summaryRows .= sprintf(
                '<tr><td>%s</td><td>%d</td><td>%.1f%% of all</td><td>%s</td></tr>',
                htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $count,
                $pctTotal,
                $matchedCol,
            );
        }

        $detailHeader = '<th>#</th><th>Winner</th><th>Match line</th>';
        foreach ($soldLabels as $label) {
            $detailHeader .= '<th>' . htmlspecialchars($label . ' sold', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
        }

        $detailRows = '';
        foreach ($rows as $row) {
            $class = match ($row['kind']) {
                'stock' => 'stock',
                'irev' => 'irev',
                'lm' => 'lm',
                default => '',
            };
            $detailRows .= sprintf('<tr class="%s">', $class);
            $detailRows .= '<td>' . $row['lead'] . '</td>';
            $detailRows .= '<td><strong>' . htmlspecialchars($row['winner'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></td>';
            $detailRows .= '<td>' . htmlspecialchars($row['line'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
            foreach ($soldLabels as $label) {
                $detailRows .= '<td>' . ($row['sold'][$label] ?? 0) . '</td>';
            }
            $detailRows .= '</tr>';
        }

        $ordersDesc = '';
        foreach ($scenario->orders as $order) {
            $ordersDesc .= sprintf(
                '<li>%s — %s, %d$, cap %d</li>',
                htmlspecialchars($order->displayLabel(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                strtoupper($order->source),
                $order->rate,
                $order->capacity,
            );
        }

        $expected = $this->buildExpectedNote($scenario);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>WD-RR: {$this->e($scenario->name)}</title>
  <style>
    body { font-family: system-ui, sans-serif; margin: 24px; color: #222; }
    h1 { font-size: 1.4rem; }
    table { border-collapse: collapse; margin: 16px 0; width: 100%; }
    th, td { border: 1px solid #ccc; padding: 6px 10px; font-size: 13px; vertical-align: top; }
    th { background: #f5f5f5; text-align: left; }
    tr.stock td { background: #fff8e1; }
    tr.irev td { background: #f1f8e9; }
    tr.lm td { background: #e3f2fd; }
    .meta { color: #555; margin-bottom: 20px; }
    .mono { font-family: ui-monospace, monospace; font-size: 12px; }
    .note { background: #f9f9f9; border-left: 4px solid #90caf9; padding: 10px 14px; margin: 16px 0; }
  </style>
</head>
<body>
  <h1>WD-RR simulation: {$this->e($scenario->name)}</h1>
  <div class="meta">
    <div>Preset ID: {$scenario->presetId} · Leads: {$scenario->leads} · Matched: {$matched} · Stock: {$stockCount}</div>
    <div>Prefix: <span class="mono">{$this->e($scenario->prefix)}</span> · α = {$scenario->rateExponent}</div>
    <ul>{$ordersDesc}</ul>
  </div>
  {$expected}
  <h2>Summary</h2>
  <table>
    <thead><tr><th>Winner</th><th>Count</th><th>% of all leads</th><th>% of matched</th></tr></thead>
    <tbody>{$summaryRows}</tbody>
  </table>
  <h2>Lead-by-lead</h2>
  <table>
    <thead><tr>{$detailHeader}</tr></thead>
    <tbody>{$detailRows}</tbody>
  </table>
</body>
</html>
HTML;
    }

    private function buildExpectedNote(SimulateScenario $scenario): string
    {
        $totalCap = 0;
        $sumW = 0.0;
        foreach ($scenario->orders as $order) {
            $totalCap += $order->capacity;
            $sumW += $order->capacity * ($order->rate ** $scenario->rateExponent);
        }
        if ($sumW <= 0) {
            return '';
        }

        $parts = [];
        foreach ($scenario->orders as $order) {
            $w = $order->capacity * ($order->rate ** $scenario->rateExponent);
            $pct = round(100 * $w / $sumW, 1);
            $expected = $totalCap > 0 ? (int) round($totalCap * $w / $sumW) : 0;
            $parts[] = sprintf('%s ~%d%% (~%d of %d cap)', $order->displayLabel(), (int) $pct, $expected, $totalCap);
        }

        return '<div class="note"><strong>Expected</strong> (equal remaining cap): '
            . implode('; ', $parts)
            . sprintf('. Stock after ~%d leads if only these orders.', $totalCap)
            . '</div>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
