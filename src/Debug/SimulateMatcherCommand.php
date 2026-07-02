<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Debug;

use Enthusiast\OrderPool\Matching\DeficitMatcher;
use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\WorkerTemplate\RedisClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Debug helper: create demo orders in Redis and run N match iterations.
 *
 * Writes only into a separate Redis namespace prefix (default "dbg:").
 */
final class SimulateMatcherCommand extends Command
{
    protected static string $defaultName = 'order-pool:simulate';

    public function __construct(
        private readonly RedisClientInterface $redis,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('preset_id', InputArgument::REQUIRED, 'Preset id')
            ->addArgument('iterations', InputArgument::OPTIONAL, 'How many match iterations', 50)
            ->addArgument('prefix', InputArgument::OPTIONAL, 'Redis prefix namespace', 'dbg:');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $presetId = (int) $input->getArgument('preset_id');
        $iterations = (int) $input->getArgument('iterations');
        $prefix = (string) $input->getArgument('prefix');

        $keys = new KeySchema($prefix);
        $poolKey = $keys->presetOrderPoolKey($presetId);

        // reset pool
        $this->redis->del($poolKey);

        // LM orders (numeric ids, existing schema)
        $lm1 = '1001';
        $lm2 = '1002';
        $this->seedLmOrder($keys, $lm1, partnerId: 'p1', price: 200, dailyLimit: 20);
        $this->seedLmOrder($keys, $lm2, partnerId: 'p2', price: 180, dailyLimit: 20);

        // IREV virtual orders
        $irevA = 'irev:11111111-1111-1111-1111-111111111111';
        $irevB = 'irev:22222222-2222-2222-2222-222222222222';
        $this->seedIrevOrder($keys, $irevA, rate: 170, remaining: 30, schedule: '10:00-19:00', tz: '+0300');
        $this->seedIrevOrder($keys, $irevB, rate: 160, remaining: 30, schedule: '10:00-19:00', tz: '+0300');

        // add to pool
        $this->redis->rawCommand('SADD', $poolKey, $lm1, $lm2, $irevA, $irevB);

        $matcher = new DeficitMatcher($this->redis, $keys, rateExponent: 1.0);
        $counts = [];

        for ($i = 0; $i < $iterations; $i++) {
            $res = $matcher->match($presetId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            if ($res === null) {
                $counts['(nil)'] = ($counts['(nil)'] ?? 0) + 1;
                continue;
            }
            if (is_string($res)) {
                $counts[$res] = ($counts[$res] ?? 0) + 1;
                continue;
            }
            $kind = $res[0] ?? '?';
            if ($kind === 'lm') {
                $counts['lm:' . ($res[1] ?? '')] = ($counts['lm:' . ($res[1] ?? '')] ?? 0) + 1;
            } elseif ($kind === 'irev') {
                $counts['irev:' . ($res[1] ?? '')] = ($counts['irev:' . ($res[1] ?? '')] ?? 0) + 1;
            } else {
                $counts['?'] = ($counts['?'] ?? 0) + 1;
            }
        }

        $output->writeln('Result distribution:');
        foreach ($counts as $k => $v) {
            $output->writeln(sprintf('- %s: %d', $k, $v));
        }

        $output->writeln(sprintf('Pool key: %s', $poolKey));
        return Command::SUCCESS;
    }

    private function seedLmOrder(KeySchema $keys, string $orderId, string $partnerId, int $price, int $dailyLimit): void
    {
        $this->redis->hMSet($keys->orderDataKey($orderId), [
            'partner_id' => $partnerId,
            'final_price' => (string) $price,
            'availability_utc' => '',
            'daily_limit' => (string) $dailyLimit,
            'daily_tz_offset' => '0',
        ]);
    }

    private function seedIrevOrder(KeySchema $keys, string $orderId, int $rate, int $remaining, string $schedule, string $tz): void
    {
        $uuid = substr($orderId, 5);
        // Simplified: store a weekly availability_utc directly (Mon..Sun 10:00-19:00 UTC here).
        // In real sync this is computed from schedule+tz in PHP.
        $availability = '';
        if ($schedule !== '') {
            if (preg_match('/^(\\d{2}):(\\d{2})-(\\d{2}):(\\d{2})$/', $schedule, $m)) {
                $start = ((int)$m[1]) * 60 + (int)$m[2];
                $end = ((int)$m[3]) * 60 + (int)$m[4];
                // treat schedule as already UTC in this debug helper
                $segs = [];
                for ($d = 1; $d <= 7; $d++) {
                    $segs[] = sprintf('%d:%d-%d', $d, $start, $end);
                }
                $availability = implode(',', $segs);
            }
        }
        $this->redis->hMSet($keys->orderDataKey($orderId), [
            'source' => 'irev',
            'partner_uuid' => $uuid,
            'partner_name' => 'DBG',
            'payment_model' => 'cpl',
            'rate' => (string) $rate,
            'availability_utc' => $availability,
            'schedule' => $schedule,
            'schedule_tz' => $tz,
        ]);
        $this->redis->set($keys->irevRemainingKey($uuid), (string) $remaining);
    }
}

