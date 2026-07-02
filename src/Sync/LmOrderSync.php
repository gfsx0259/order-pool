<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\WorkerTemplate\RedisClientInterface;

/**
 * Writes LM real orders into Redis in a single place (shared between API + workers).
 *
 * Stores data under `order:{id}:data` and adds order id into:
 * - `preset:{presetId}:orders_pool` (SET)  — unified pool for WD-RR
 */
final readonly class LmOrderSync
{
    public function __construct(
        private RedisClientInterface $redis,
        private KeySchema $keys,
    ) {}

    /**
     * Upserts one LM order.
     *
     * @param int $orderId LM order id (numeric)
     * @param int $presetId preset id
     * @param string $partnerId LM partner id
     * @param int $finalPrice order CPL price
     * @param string $availabilityUtc "D:START-END,..." (LM weekly windows)
     * @param int|null $dailyLimit order daily limit
     * @param int $dailyTzOffset seconds (for local day counters, legacy)
     */
    public function upsert(
        int $orderId,
        int $presetId,
        string $partnerId,
        int $finalPrice,
        string $availabilityUtc,
        ?int $dailyLimit,
        int $dailyTzOffset,
    ): void {
        $dataKey = $this->keys->orderDataKey((string) $orderId);
        $poolKey = $this->keys->presetOrderPoolKey($presetId);

        $this->redis->multi(\Redis::PIPELINE);

        // Backward compatible schema (worker-matcher Lua reads these fields)
        $this->redis->hMSet($dataKey, [
            'partner_id' => $partnerId,
            'preset_id' => (string) $presetId,
            'final_price' => (string) $finalPrice,
            'availability_utc' => $availabilityUtc,
            'daily_limit' => $dailyLimit !== null ? (string) $dailyLimit : '',
            'daily_tz_offset' => (string) $dailyTzOffset,
        ]);

        // Unified pool for WD-RR
        $this->redis->rawCommand('SADD', $poolKey, (string) $orderId);

        $this->redis->exec();
    }

    public function remove(int $orderId, int $presetId): void
    {
        $dataKey = $this->keys->orderDataKey((string) $orderId);
        $poolKey = $this->keys->presetOrderPoolKey($presetId);

        $this->redis->multi(\Redis::PIPELINE);
        $this->redis->del($dataKey);
        $this->redis->rawCommand('SREM', $poolKey, (string) $orderId);
        $this->redis->exec();
    }
}

