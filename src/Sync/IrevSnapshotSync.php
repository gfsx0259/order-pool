<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use DateTimeImmutable;
use DateTimeZone;
use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\OrderPool\Schedule\LocalDay;
use Enthusiast\OrderPool\Snapshot\SnapshotDocument;
use Enthusiast\OrderPool\Snapshot\IrevOrderSlot;
use Enthusiast\WorkerTemplate\RedisClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies IREV snapshot document into Redis order pool.
 *
 * - Filters CPL only (payment_model == 'cpl')
 * - Upserts virtual orders `irev:{partner_uuid}` into `preset:{id}:orders_pool`
 * - capacity in order hash = remaining from snapshot; sold counter reset on each sync
 */
final readonly class IrevSnapshotSync
{
    public function __construct(
        private RedisClientInterface $redis,
        private KeySchema $keys,
        private LocalDay $localDay,
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
     * Converts iRev snapshot schedule+tz (local, today) into LM-compatible `availability_utc`.
     *
     * Output format: "D:START-END" (or two segments for night-wrap) where D = today's
     * ISO-8601 day (1..7, Mon..Sun in UTC), START/END = minutes-of-day in UTC.
     *
     * Refreshed on every snapshot sync (~15 min); not replicated across the whole week.
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

        $startUtc = ($startLocal - $offsetMin) % 1440;
        if ($startUtc < 0) {
            $startUtc += 1440;
        }
        $endUtc = ($endLocal - $offsetMin) % 1440;
        if ($endUtc < 0) {
            $endUtc += 1440;
        }

        $today = (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('N');

        if ($startUtc <= $endUtc) {
            return sprintf('%d:%d-%d', $today, $startUtc, $endUtc);
        }

        $next = $today === 7 ? 1 : $today + 1;

        return sprintf('%d:%d-%d,%d:%d-%d', $today, $startUtc, 1440, $next, 0, $endUtc);
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

        foreach ($slots as $slot) {
            if ($slot->paymentModel !== 'cpl') {
                continue;
            }
            $partnerUuid = $slot->partnerUuid;
            if ($partnerUuid === '') {
                continue;
            }

            $orderId = 'irev:' . $partnerUuid;
            $availabilityUtc = $this->buildAvailabilityUtc($slot->schedule, $slot->scheduleTz);
            $dailyTzOffset = $this->localDay->tzOffsetFromIrevScheduleTz($slot->scheduleTz);
            $localDay = $this->localDay->resolve($dailyTzOffset);

            $dataKey = $this->keys->orderDataKey($orderId);
            $this->redis->hMSet($dataKey, [
                'source' => 'irev',
                'partner_id' => $partnerUuid,
                'partner_name' => $slot->partnerName,
                'rate' => $slot->rate !== null ? (string) $slot->rate : '',
                'availability_utc' => $availabilityUtc,
                'capacity' => $slot->remaining !== null ? (string) $slot->remaining : '',
                'schedule' => $slot->schedule,
                'schedule_tz' => $slot->scheduleTz,
                'daily_tz_offset' => (string) $dailyTzOffset,
            ]);

            // Snapshot remaining is authoritative — reset local sold since last sync.
            $this->redis->del($this->keys->orderSoldKey($orderId, $localDay));

            $this->redis->rawCommand('SADD', $poolKey, $orderId);
            unset($existingSet[$orderId]);
        }

        foreach (array_keys($existingSet) as $staleOrderId) {
            if (!str_starts_with((string) $staleOrderId, 'irev:')) {
                continue;
            }
            $this->redis->rawCommand('SREM', $poolKey, $staleOrderId);
            $this->redis->del($this->keys->orderDataKey($staleOrderId));
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
