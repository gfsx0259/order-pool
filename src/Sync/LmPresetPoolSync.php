<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Cycle\Database\DatabaseProviderInterface;
use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\OrderPool\Schedule\AvailabilitySchedule;
use Enthusiast\OrderPool\Schedule\OrderAvailabilityNormalizer;
use Enthusiast\OrderPool\ValueObject\Order;
use Enthusiast\WorkerTemplate\RedisClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * LM preset pool sync: incremental upsert + bulk refresh from DB.
 *
 * IREV virtual orders: {@see IrevPresetPoolSync}.
 */
final readonly class LmPresetPoolSync
{
    private const int SOLD_COUNTER_TTL_SECONDS = 172800; // 48h

    public function __construct(
        private DatabaseProviderInterface $db,
        private RedisClientInterface $redis,
        private KeySchema $keys,
        private OrderSync $orderSync,
        private OrderAvailabilityNormalizer $availabilityNormalizer,
        private ?LoggerInterface $logger = null,
    ) {}

    /** Sync all active LM orders for a preset from DB into Redis. */
    public function syncFromDatabase(int $presetId): void
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

    public function upsert(Order $order): void
    {
        $this->orderSync->upsert($order);
        $this->orderSync->restoreSoldCounter($order);
    }

    public function remove(string $orderId, int $presetId): void
    {
        $this->orderSync->remove($orderId, $presetId);
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
                $orderId = (string) $row['id'];
                $schedule = AvailabilitySchedule::fromJson($row['order_schedule'])
                    ?? AvailabilitySchedule::fromJson($row['user_schedule']);
                $tzOffset = $this->availabilityNormalizer->fromLm($schedule)->dailyTzOffset;
                $currentLocalDay = $this->availabilityNormalizer->resolveLocalDay($tzOffset);
                $soldKey = $this->keys->orderSoldKey($orderId, $currentLocalDay);

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
     * @param array<string, mixed> $row
     */
    private function mapRowToSnapshot(array $row): Order
    {
        $schedule = AvailabilitySchedule::fromJson($row['order_schedule'])
            ?? AvailabilitySchedule::fromJson($row['user_schedule']);
        $availability = $this->availabilityNormalizer->fromLm($schedule);

        return new Order(
            orderId: (string) $row['id'],
            presetId: (int) $row['preset_id'],
            partnerId: (string) $row['partner_id'],
            rate: (int) $row['price'],
            availabilityUtc: $availability->availabilityUtc,
            capacity: $row['daily_limit'] !== null ? (int) $row['daily_limit'] : null,
            dailyReceivedCount: $row['daily_received_count'] !== null ? (int) $row['daily_received_count'] : null,
            dailyReceivedLocalDay: $row['daily_received_local_day'] !== null ? (int) $row['daily_received_local_day'] : null,
            dailyTzOffset: $availability->dailyTzOffset,
        );
    }
}
