<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\OrderPool\Schedule\OrderAvailabilityNormalizer;
use Enthusiast\OrderPool\ValueObject\Order;
use Enthusiast\WorkerTemplate\RedisClientInterface;

/**
 * Writes orders into Redis (LM + IREV share one HASH schema).
 *
 * - order:{id}:data HASH
 * - preset:{id}:orders_pool SET
 * - order:{id}:sold:{day} STRING
 */
final readonly class OrderSync
{
    private const int SOLD_COUNTER_TTL_SECONDS = 172800; // 48h

    public function __construct(
        private RedisClientInterface $redis,
        private KeySchema $keys,
        private OrderAvailabilityNormalizer $availabilityNormalizer,
    ) {}

    /**
     * @param bool $resetSold IREV snapshot: remaining is authoritative, clear local sold.
     */
    public function upsert(Order $order, bool $resetSold = false): void
    {
        $dataKey = $this->keys->orderDataKey($order->orderId);
        $poolKey = $this->keys->presetOrderPoolKey($order->presetId);

        $fields = [
            'source' => $order->isIrev() ? 'irev' : 'lm',
            'partner_id' => $order->partnerId,
            'rate' => (string) $order->rate,
            'availability_utc' => $order->availabilityUtc,
            'capacity' => $order->capacity !== null ? (string) $order->capacity : '',
            'daily_tz_offset' => (string) $order->dailyTzOffset,
        ];
        if (!$order->isIrev()) {
            $fields['preset_id'] = (string) $order->presetId;
        }

        $this->redis->multi(\Redis::PIPELINE);
        $this->redis->hMSet($dataKey, $fields);
        $this->redis->rawCommand('SADD', $poolKey, $order->orderId);
        $this->redis->exec();

        if ($resetSold) {
            $localDay = $this->availabilityNormalizer->resolveLocalDay($order->dailyTzOffset);
            $this->redis->del($this->keys->orderSoldKey($order->orderId, $localDay));
        }
    }

    public function remove(string $orderId, int $presetId): void
    {
        $dataKey = $this->keys->orderDataKey($orderId);
        $poolKey = $this->keys->presetOrderPoolKey($presetId);

        $this->redis->multi(\Redis::PIPELINE);
        $this->redis->del($dataKey);
        $this->redis->rawCommand('SREM', $poolKey, $orderId);
        $this->redis->exec();
    }

    public function restoreSoldCounter(Order $order): void
    {
        if ($order->capacity === null) {
            return;
        }

        $currentLocalDay = $this->availabilityNormalizer->resolveLocalDay($order->dailyTzOffset);
        if ($order->dailyReceivedLocalDay !== $currentLocalDay) {
            return;
        }

        $count = $order->dailyReceivedCount ?? 0;
        if ($count <= 0) {
            return;
        }

        $soldKey = $this->keys->orderSoldKey($order->orderId, $currentLocalDay);
        $this->redis->set($soldKey, (string) $count, self::SOLD_COUNTER_TTL_SECONDS);
    }
}
