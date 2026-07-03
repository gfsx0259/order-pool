<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\OrderPool\ValueObject\Preset;
use Enthusiast\WorkerTemplate\RedisClientInterface;

/** Applies one normalized IREV preset slice into Redis. */
final readonly class IrevPresetPoolSync
{
    public function __construct(
        private RedisClientInterface $redis,
        private KeySchema $keys,
        private OrderSync $orderSync,
    ) {}

    public function apply(Preset $preset): void
    {
        $poolKey = $this->keys->presetOrderPoolKey($preset->presetId);

        /** @var list<string> $existing */
        $existing = $this->smembers($poolKey);
        $existingSet = array_fill_keys($existing, true);

        foreach ($preset->orders as $order) {
            if ($order->partnerId === '') {
                continue;
            }

            $this->orderSync->upsert($order, resetSold: true);
            unset($existingSet[$order->orderId]);
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
