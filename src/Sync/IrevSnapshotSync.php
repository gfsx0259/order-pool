<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\OrderPool\Snapshot\SnapshotDocument;
use Enthusiast\OrderPool\Snapshot\IrevOrderSlot;
use Enthusiast\WorkerTemplate\RedisClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies IREV snapshot document into Redis order pool.
 *
 * - Filters CPL only (payment_model == 'cpl')
 * - Upserts virtual orders `irev:{partner_uuid}` into `preset:{id}:orders_pool`
 * - Stores remaining in dedicated key `irev:{uuid}:remaining`
 */
final readonly class IrevSnapshotSync
{
    public function __construct(
        private RedisClientInterface $redis,
        private KeySchema $keys,
        private ?LoggerInterface $logger = null,
    ) {}

    public function apply(SnapshotDocument $document): void
    {
        if ($document->presets === []) {
            $this->logger?->warning('IREV snapshot: empty presets');
            return;
        }

        foreach ($document->presets as $preset) {
            $this->applyPreset($preset->presetId, $preset->orders);
        }
    }

    /**
     * Converts iRev snapshot schedule+tz (local) into LM-compatible weekly `availability_utc`.
     *
     * Output format: "D:START-END,D:START-END,..." where D = 1..7 (ISO-8601, Mon..Sun),
     * START/END = minutes-of-day in UTC.
     *
     * For night-wrap windows (e.g. 22:00-06:00) produces two segments per day:
     * - D:START-1440 and nextDay:0-END
     */
    private function buildAvailabilityUtc(string $schedule, string $scheduleTz): string
    {
        $schedule = trim($schedule);
        if ($schedule === '') {
            return '';
        }
        if (!preg_match('/^(\\d{2}):(\\d{2})-(\\d{2}):(\\d{2})$/', $schedule, $m)) {
            return '';
        }
        $startLocal = ((int) $m[1]) * 60 + (int) $m[2];
        $endLocal = ((int) $m[3]) * 60 + (int) $m[4];

        $offsetMin = 0;
        $scheduleTz = trim($scheduleTz);
        if ($scheduleTz !== '' && preg_match('/^([+-])(\\d{2})(\\d{2})$/', $scheduleTz, $tzm)) {
            $sign = $tzm[1] === '-' ? -1 : 1;
            $offsetMin = $sign * (((int) $tzm[2]) * 60 + (int) $tzm[3]);
        }

        // Convert local minutes -> UTC minutes (same each day)
        $startUtc = ($startLocal - $offsetMin) % 1440;
        if ($startUtc < 0) {
            $startUtc += 1440;
        }
        $endUtc = ($endLocal - $offsetMin) % 1440;
        if ($endUtc < 0) {
            $endUtc += 1440;
        }

        $segments = [];
        // 1..7 (Mon..Sun)
        for ($d = 1; $d <= 7; $d++) {
            if ($startUtc <= $endUtc) {
                // normal
                $segments[] = sprintf('%d:%d-%d', $d, $startUtc, $endUtc);
            } else {
                // wrap
                $segments[] = sprintf('%d:%d-%d', $d, $startUtc, 1440);
                $next = $d === 7 ? 1 : $d + 1;
                $segments[] = sprintf('%d:%d-%d', $next, 0, $endUtc);
            }
        }
        return implode(',', $segments);
    }

    /**
     * @param list<IrevOrderSlot> $slots
     */
    private function applyPreset(int $presetId, array $slots): void
    {
        $poolKey = $this->keys->presetOrderPoolKey($presetId);

        /** @var list<string> $existing */
        $existing = $this->smembers($poolKey);
        $existingSet = array_fill_keys($existing, true);

        $newOrderIds = [];

        foreach ($slots as $slot) {
            $paymentModel = $slot->paymentModel;
            if ($paymentModel !== 'cpl') {
                continue;
            }
            $partnerUuid = $slot->partnerUuid;
            if ($partnerUuid === '') {
                continue;
            }

            $orderId = 'irev:' . $partnerUuid;
            $newOrderIds[] = $orderId;

            $rate = $slot->rate;
            $remaining = $slot->remaining;
            $schedule = $slot->schedule;
            $scheduleTz = $slot->scheduleTz;
            $availabilityUtc = $this->buildAvailabilityUtc($schedule, $scheduleTz);
            $partnerName = $slot->partnerName;

            $dataKey = $this->keys->orderDataKey($orderId);
            $this->redis->hMSet($dataKey, [
                'source' => 'irev',
                'partner_uuid' => $partnerUuid,
                'partner_name' => $partnerName,
                'payment_model' => 'cpl',
                'rate' => $rate !== null ? (string)$rate : '',
                // Make IREV window compatible with LM availability logic.
                'availability_utc' => $availabilityUtc,
                // Keep original schedule for debugging / auditing.
                'schedule' => $schedule,
                'schedule_tz' => $scheduleTz,
            ]);

            if ($remaining !== null) {
                $this->redis->set($this->keys->irevRemainingKey($partnerUuid), (string)$remaining);
            }

            // add to pool
            $this->redis->rawCommand('SADD', $poolKey, $orderId);
            unset($existingSet[$orderId]);
        }

        // Remove stale IREV orders from pool (keep LM orders if present).
        foreach (array_keys($existingSet) as $staleOrderId) {
            if (!str_starts_with($staleOrderId, 'irev:')) {
                continue;
            }
            $this->redis->rawCommand('SREM', $poolKey, $staleOrderId);
            $this->redis->del($this->keys->orderDataKey($staleOrderId));
            $uuid = substr($staleOrderId, 5);
            if ($uuid !== '') {
                $this->redis->del($this->keys->irevRemainingKey($uuid));
                // NOTE: lm_assigned keys are day-scoped; keep them (TTL recommended elsewhere).
            }
        }
    }

    /**
     * @return list<string>
     */
    private function smembers(string $key): array
    {
        $res = $this->redis->rawCommand('SMEMBERS', $key);
        if (!is_array($res)) {
            return [];
        }
        $out = [];
        foreach ($res as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }
}

