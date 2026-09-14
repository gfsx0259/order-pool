<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Enthusiast\OrderPool\Enum\PaymentModel;
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
        /** @var array<string, list<string>> $pools orderId => pool keys it currently sits in */
        $pools = [];
        foreach (PaymentModel::cases() as $paymentModel) {
            $poolKey = $this->keys->presetOrderPoolKey($preset->presetId, $paymentModel);
            foreach ($this->redis->sMembers($poolKey) as $orderId) {
                if (str_starts_with((string) $orderId, 'irev:')) {
                    $pools[(string) $orderId][] = $poolKey;
                }
            }
        }

        foreach ($preset->orders as $order) {
            if ($order->partnerId === '') {
                continue;
            }

            $poolKey = $this->keys->presetOrderPoolKey($preset->presetId, $order->paymentModel);
            foreach ($pools[$order->orderId] ?? [] as $currentPool) {
                if ($currentPool !== $poolKey) {
                    $this->redis->rawCommand('SREM', $currentPool, $order->orderId);
                }
            }
            unset($pools[$order->orderId]);

            $this->orderSync->upsert($order, resetSold: true);
        }

        foreach ($pools as $orderId => $poolKeys) {
            foreach ($poolKeys as $poolKey) {
                $this->redis->rawCommand('SREM', $poolKey, $orderId);
            }
            $this->redis->del($this->keys->orderDataKey($orderId));
        }
    }
}
