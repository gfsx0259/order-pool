<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\WorkerTemplate\RedisClientInterface;

/**
 * Writes LM real orders into Redis in a single place (shared between API + workers).
 *
 * Unified order schema (same as IREV virtual orders):
 * - order:{id}:data HASH: source, rate, availability_utc, capacity, partner_id, preset_id, daily_tz_offset
 * - order:{id}:sold:{day} STRING: locally routed leads count
 */
final readonly class LmOrderSync
{
    public function __construct(
        private RedisClientInterface $redis,
        private KeySchema $keys,
    ) {}

    /**
     * @param int|null $capacity Daily limit; empty/null means unlimited.
     */
    public function upsert(
        int $orderId,
        int $presetId,
        string $partnerId,
        int $rate,
        string $availabilityUtc,
        ?int $capacity,
        int $dailyTzOffset = 0,
    ): void {
        $orderIdStr = (string) $orderId;
        $dataKey = $this->keys->orderDataKey($orderIdStr);
        $poolKey = $this->keys->presetOrderPoolKey($presetId);

        $this->redis->multi(\Redis::PIPELINE);

        $this->redis->hMSet($dataKey, [
            'source' => 'lm',
            'partner_id' => $partnerId,
            'preset_id' => (string) $presetId,
            'rate' => (string) $rate,
            'availability_utc' => $availabilityUtc,
            'capacity' => $capacity !== null ? (string) $capacity : '',
            'daily_tz_offset' => (string) $dailyTzOffset,
        ]);

        $this->redis->rawCommand('SADD', $poolKey, $orderIdStr);

        $this->redis->exec();
    }

    public function remove(int $orderId, int $presetId): void
    {
        $orderIdStr = (string) $orderId;
        $dataKey = $this->keys->orderDataKey($orderIdStr);
        $poolKey = $this->keys->presetOrderPoolKey($presetId);

        $this->redis->multi(\Redis::PIPELINE);
        $this->redis->del($dataKey);
        $this->redis->rawCommand('SREM', $poolKey, $orderIdStr);
        $this->redis->exec();
    }
}
