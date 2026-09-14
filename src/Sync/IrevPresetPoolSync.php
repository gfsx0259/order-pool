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
        /** @var list<array{0: string, 1: string}> $existing */
        $existing = [];
        foreach (PaymentModel::cases() as $paymentModel) {
            $poolKey = $this->keys->presetOrderPoolKey($preset->presetId, $paymentModel);
            foreach ($this->redis->sMembers($poolKey) as $orderId) {
                if (str_starts_with((string) $orderId, 'irev:')) {
                    $existing[] = [$poolKey, (string) $orderId];
                }
            }
        }

        /** @var array<string, string> $upserted orderId => pool key */
        $upserted = [];
        foreach ($preset->orders as $order) {
            if ($order->partnerId === '') {
                continue;
            }

            $this->orderSync->upsert($order, resetSold: true);
            $upserted[$order->orderId] = $this->keys->presetOrderPoolKey($preset->presetId, $order->paymentModel);
        }

        foreach ($existing as [$poolKey, $orderId]) {
            if (($upserted[$orderId] ?? null) === $poolKey) {
                continue;
            }
            $this->redis->rawCommand('SREM', $poolKey, $orderId);
            if (!isset($upserted[$orderId])) {
                $this->redis->del($this->keys->orderDataKey($orderId));
            }
        }
    }
}
