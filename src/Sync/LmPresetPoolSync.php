<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Cycle\Database\DatabaseProviderInterface;
use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\OrderPool\Schedule\AvailabilitySchedule;
use Enthusiast\OrderPool\Schedule\OrderAvailabilityNormalizer;
use Enthusiast\OrderPool\ValueObject\Order;
use Enthusiast\OrderPool\ValueObject\OrderSoldSnapshot;
use Enthusiast\OrderPool\Enum\PaymentModel;
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
        private LoggerInterface $logger,
    ) {}

    /** Sync all active LM orders for a preset from DB into Redis. */
    public function syncFromDatabase(int $presetId): void
    {
        foreach (PaymentModel::cases() as $case) {
            $this->redis->del($this->keys->presetOrderPoolKey($presetId, $case));
        }
        $this->redis->del($this->keys->legacyPresetOrderPoolKey($presetId));

        $rows = $this->db->database()->query(
            'SELECT
                o.id,
                o.preset_id,
                o.partner_id,
                o.limit_total,
                o.received_count,
                o.daily_limit,
                o.daily_received_count,
                o.daily_received_local_day,
                o.payment_model,
                o.date,
                o.availability_schedule AS order_schedule,
                u.availability_schedule AS user_schedule,
                COALESCE(o.price, p.price) AS price
             FROM orders o
             INNER JOIN users u ON u.id = o.partner_id
             INNER JOIN presets p ON p.id = o.preset_id
             WHERE o.preset_id = ?
               AND o.status = \'in_progress\'
               AND o.received_count < o.limit_total',
            [$presetId],
        )->fetchAll();

        $synced = 0;
        foreach ($rows as $row) {
            $order = $this->mapRowToSnapshot($row);
            if (!$this->isEligibleForPool($order, $row)) {
                continue;
            }

            $this->upsert($order);
            $synced++;
        }

        $this->logger->info('LM preset synced to Redis', [
            'preset_id' => $presetId,
            'candidateOrdersCount' => count($rows),
            'syncedOrdersCount' => $synced,
        ]);
    }

    public function upsert(Order $order): void
    {
        $this->orderSync->upsert($order);
        $this->orderSync->restoreSoldCounter($order);
    }

    public function remove(string $orderId, int $presetId, PaymentModel|string $paymentModel = PaymentModel::CPL): void
    {
        $this->orderSync->remove($orderId, $presetId, $paymentModel);
    }

    /**
     * Restore LM `sold` counters for all active pool orders (worker startup).
     *
     * - daily_limit set → seed from daily_received_* for the current local day
     * - otherwise → seed from lifetime received_count (total-cap WDRR weight)
     */
    public function restoreAllSoldCountersFromDatabase(): void
    {
        try {
            $snapshots = $this->loadSoldSnapshots();
            $setCount = 0;
            $clearedCount = 0;

            foreach ($snapshots as $snapshot) {
                if ($this->writeSoldCounter($snapshot)) {
                    $setCount++;
                } else {
                    $clearedCount++;
                }
            }

            $this->logger->info('LM sold counters synced from database', [
                'orders' => count($snapshots),
                'set' => $setCount,
                'cleared' => $clearedCount,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('LM sold counter sync failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * One active LM order, or null if it is no longer in the pool
     * (`in_progress` and `received_count < limit_total`).
     */
    public function fetchSoldSnapshot(int $orderId): ?OrderSoldSnapshot
    {
        return $this->loadSoldSnapshots($orderId)[0] ?? null;
    }

    /**
     * Write `order:{id}:sold:{day}` from the snapshot (+ matcher pending not yet flushed).
     *
     * @return bool true if the key was SET, false if it was deleted
     */
    public function writeSoldCounter(
        OrderSoldSnapshot $snapshot,
        int $pendingReceived = 0,
        int $pendingDaily = 0,
        ?int $pendingDailyLocalDay = null,
    ): bool {
        $sold = $snapshot->redisSoldCount($pendingReceived, $pendingDaily, $pendingDailyLocalDay);
        $soldKey = $this->keys->orderSoldKey((string) $snapshot->orderId, $snapshot->currentLocalDay);

        if ($sold <= 0) {
            $this->redis->del($soldKey);

            return false;
        }

        $this->redis->set($soldKey, (string) $sold, self::SOLD_COUNTER_TTL_SECONDS);

        return true;
    }

    /**
     * @return list<OrderSoldSnapshot>
     */
    private function loadSoldSnapshots(?int $orderId = null): array
    {
        $sql = 'SELECT
                    o.id,
                    o.limit_total,
                    o.received_count,
                    o.daily_limit,
                    o.daily_received_count,
                    o.daily_received_local_day,
                    o.availability_schedule AS order_schedule,
                    u.availability_schedule AS user_schedule
                 FROM orders o
                 INNER JOIN users u ON u.id = o.partner_id
                 WHERE o.status = \'in_progress\'
                   AND o.received_count < o.limit_total';
        $params = [];

        if ($orderId !== null) {
            $sql .= ' AND o.id = ?';
            $params[] = $orderId;
        }

        $rows = $this->db->database()->query($sql, $params)->fetchAll();

        $snapshots = [];
        foreach ($rows as $row) {
            $snapshots[] = $this->mapRowToSoldSnapshot($row);
        }

        return $snapshots;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToSoldSnapshot(array $row): OrderSoldSnapshot
    {
        $schedule = AvailabilitySchedule::fromJson($row['order_schedule'])
            ?? AvailabilitySchedule::fromJson($row['user_schedule']);
        $tzOffset = $this->availabilityNormalizer->fromLm($schedule)->dailyTzOffset;

        return new OrderSoldSnapshot(
            orderId: (int) $row['id'],
            limitTotal: (int) $row['limit_total'],
            receivedCount: (int) $row['received_count'],
            dailyLimit: $row['daily_limit'] !== null ? (int) $row['daily_limit'] : null,
            dailyReceivedCount: $row['daily_received_count'] !== null ? (int) $row['daily_received_count'] : null,
            dailyReceivedLocalDay: $row['daily_received_local_day'] !== null ? (int) $row['daily_received_local_day'] : null,
            currentLocalDay: $this->availabilityNormalizer->resolveLocalDay($tzOffset),
            hasDailyLimit: $row['daily_limit'] !== null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isEligibleForPool(Order $order, array $row): bool
    {
        $schedule = AvailabilitySchedule::fromJson($row['order_schedule'])
            ?? AvailabilitySchedule::fromJson($row['user_schedule']);

        return $this->availabilityNormalizer->isActiveOnDate($order->date, $schedule);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToSnapshot(array $row): Order
    {
        $schedule = AvailabilitySchedule::fromJson($row['order_schedule'])
            ?? AvailabilitySchedule::fromJson($row['user_schedule']);
        $availability = $this->availabilityNormalizer->fromLm($schedule);

        $hasDailyLimit = $row['daily_limit'] !== null;

        return new Order(
            orderId: (string) $row['id'],
            presetId: (int) $row['preset_id'],
            partnerId: (string) $row['partner_id'],
            rate: (int) $row['price'],
            availabilityUtc: $availability->availabilityUtc,
            capacity: $hasDailyLimit ? (int) $row['daily_limit'] : (int) $row['limit_total'],
            dailyReceivedCount: $row['daily_received_count'] !== null ? (int) $row['daily_received_count'] : null,
            dailyReceivedLocalDay: $row['daily_received_local_day'] !== null ? (int) $row['daily_received_local_day'] : null,
            dailyTzOffset: $availability->dailyTzOffset,
            date: $row['date'] ?? null,
            hasDailyLimit: $hasDailyLimit,
            receivedCount: (int) $row['received_count'],
            paymentModel: PaymentModel::normalize((string) ($row['payment_model'] ?? PaymentModel::CPL->value)),
        );
    }
}
