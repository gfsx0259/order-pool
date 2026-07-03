<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Cycle\Database\DatabaseProviderInterface;
use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\OrderPool\Schedule\AvailabilitySchedule;
use Enthusiast\OrderPool\Schedule\AvailabilityScheduleUtc;
use Enthusiast\OrderPool\Schedule\LocalDay;
use Enthusiast\WorkerTemplate\RedisClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * LM order pool sync: writes orders + sold counters into Redis.
 *
 * IREV virtual orders are handled separately by {@see IrevSnapshotSync}.
 */
final readonly class LmOrderPoolSync
{
    private const int SOLD_COUNTER_TTL_SECONDS = 172800; // 48h

    public function __construct(
        private DatabaseProviderInterface $db,
        private RedisClientInterface $redis,
        private KeySchema $keys,
        private LmOrderSync $lmOrderSync,
        private ?LoggerInterface $logger = null,
    ) {}

    public function upsert(LmOrderSnapshot $order): void
    {
        $this->lmOrderSync->upsert(
            orderId: $order->orderId,
            presetId: $order->presetId,
            partnerId: $order->partnerId,
            rate: $order->rate,
            availabilityUtc: $order->availabilityUtc,
            capacity: $order->capacity,
            dailyTzOffset: $order->dailyTzOffset,
        );

        $this->restoreSoldCounter($order);
    }

    public function remove(int $orderId, int $presetId): void
    {
        $this->lmOrderSync->remove($orderId, $presetId);
    }

    /**
     * Restore LM `sold` counters for all active orders with daily limits (worker startup).
     */
    public function restoreAllSoldCountersFromDatabase(): void
    {
        try {
            $rows = $this->db->database()->query(
                'SELECT
                    o.id,
                    o.daily_received_count,
                    o.daily_received_local_day,
                    o.availability_schedule AS order_schedule,
                    u.availability_schedule AS user_schedule
                 FROM orders o
                 INNER JOIN users u ON u.id = o.partner_id
                 WHERE o.status = \'in_progress\'
                   AND o.daily_limit IS NOT NULL
                   AND o.received_count < o.limit_total',
            )->fetchAll();

            $setCount = 0;
            $clearedCount = 0;

            foreach ($rows as $row) {
                $orderId = (int) $row['id'];
                $effectiveSchedule = LocalDay::parseScheduleJson($row['order_schedule'])
                    ?? LocalDay::parseScheduleJson($row['user_schedule']);
                $tzOffset = LocalDay::tzOffsetFromSchedule($effectiveSchedule);
                $currentLocalDay = LocalDay::resolve($tzOffset);
                $soldKey = $this->keys->orderSoldKey((string) $orderId, $currentLocalDay);

                $dbLocalDay = $row['daily_received_local_day'] !== null
                    ? (int) $row['daily_received_local_day']
                    : null;
                $dbCount = (int) $row['daily_received_count'];

                if ($dbLocalDay === $currentLocalDay && $dbCount > 0) {
                    $this->redis->set($soldKey, (string) $dbCount, self::SOLD_COUNTER_TTL_SECONDS);
                    $setCount++;
                    continue;
                }

                $this->redis->del($soldKey);
                $clearedCount++;
            }

            $this->logger?->info('LM sold counters synced from database', [
                'orders' => count($rows),
                'set' => $setCount,
                'cleared' => $clearedCount,
            ]);
        } catch (Throwable $e) {
            $this->logger?->error('LM sold counter sync failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync all active LM orders for a preset into Redis (replaces HTTP /preset/sync body).
     */
    public function syncPresetFromDatabase(int $presetId): void
    {
        $rows = $this->db->database()->query(
            'SELECT
                o.id,
                o.preset_id,
                o.partner_id,
                o.daily_limit,
                o.daily_received_count,
                o.daily_received_local_day,
                o.availability_schedule AS order_schedule,
                u.availability_schedule AS user_schedule,
                p.price
             FROM orders o
             INNER JOIN users u ON u.id = o.partner_id
             INNER JOIN presets p ON p.id = o.preset_id
             WHERE o.preset_id = ?
               AND o.status = \'in_progress\'
               AND o.received_count < o.limit_total',
            [$presetId],
        )->fetchAll();

        foreach ($rows as $row) {
            $this->upsert($this->mapRowToSnapshot($row));
        }

        $this->logger?->info('LM preset synced to Redis', [
            'preset_id' => $presetId,
            'orders' => count($rows),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToSnapshot(array $row): LmOrderSnapshot
    {
        $effectiveSchedule = LocalDay::parseScheduleJson($row['order_schedule'])
            ?? LocalDay::parseScheduleJson($row['user_schedule']);
        $schedule = AvailabilitySchedule::fromArray($effectiveSchedule);
        $availabilityUtc = AvailabilityScheduleUtc::toUtcWindows($schedule);
        $tzOffset = LocalDay::tzOffsetFromSchedule($effectiveSchedule);

        return new LmOrderSnapshot(
            orderId: (int) $row['id'],
            presetId: (int) $row['preset_id'],
            partnerId: (string) $row['partner_id'],
            rate: (int) $row['price'],
            availabilityUtc: $availabilityUtc,
            capacity: $row['daily_limit'] !== null ? (int) $row['daily_limit'] : null,
            dailyReceivedCount: $row['daily_received_count'] !== null ? (int) $row['daily_received_count'] : null,
            dailyReceivedLocalDay: $row['daily_received_local_day'] !== null ? (int) $row['daily_received_local_day'] : null,
            dailyTzOffset: $tzOffset,
        );
    }

    private function restoreSoldCounter(LmOrderSnapshot $order): void
    {
        if ($order->capacity === null) {
            return;
        }

        $currentLocalDay = LocalDay::resolve($order->dailyTzOffset);
        $soldKey = $this->keys->orderSoldKey((string) $order->orderId, $currentLocalDay);

        if ($order->dailyReceivedLocalDay !== $currentLocalDay) {
            return;
        }

        $count = $order->dailyReceivedCount ?? 0;
        if ($count <= 0) {
            return;
        }

        $this->redis->set($soldKey, (string) $count, self::SOLD_COUNTER_TTL_SECONDS);
    }
}
