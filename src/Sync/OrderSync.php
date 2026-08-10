<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\OrderPool\Schedule\OrderAvailabilityNormalizer;
use Enthusiast\OrderPool\ValueObject\Order;
use Enthusiast\OrderPool\Enum\PaymentModel;
use Enthusiast\WorkerTemplate\RedisClientInterface;

/**
 * Writes orders into Redis (LM + IREV share one HASH schema).
 *
 * - order:{id}:data HASH
 * - preset:{id}:orders_pool:{cpl|cpa} SET
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
        $poolKey = $this->keys->presetOrderPoolKey($order->presetId, $order->paymentModel);

        $fields = [
            'source' => $order->isIrev() ? 'irev' : 'lm',
            'partner_id' => $order->partnerId,
            'preset_id' => (string) $order->presetId,
            'rate' => (string) $order->rate,
            'availability_utc' => $order->availabilityUtc,
            'capacity' => $order->capacity !== null ? (string) $order->capacity : '',
            'has_daily_limit' => $order->hasDailyLimit ? '1' : '0',
            'daily_tz_offset' => (string) $order->dailyTzOffset,
            'date' => $order->date ?? '',
            'payment_model' => $order->paymentModel->value,
        ];

        $this->redis->multi(\Redis::PIPELINE);
        $this->redis->hMSet($dataKey, $fields);
        $this->redis->rawCommand('SADD', $poolKey, $order->orderId);
        $this->redis->exec();

        if ($resetSold) {
            $localDay = $this->availabilityNormalizer->resolveLocalDay($order->dailyTzOffset);
            $this->redis->del($this->keys->orderSoldKey($order->orderId, $localDay));
        }
    }

    public function remove(string $orderId, int $presetId, PaymentModel|string $paymentModel = PaymentModel::CPL): void
    {
        $model = $paymentModel instanceof PaymentModel
            ? $paymentModel
            : PaymentModel::normalize($paymentModel);

        $dataKey = $this->keys->orderDataKey($orderId);
        $poolKey = $this->keys->presetOrderPoolKey($presetId, $model);

        $this->redis->multi(\Redis::PIPELINE);
        $this->redis->del($dataKey);
        $this->redis->rawCommand('SREM', $poolKey, $orderId);
        // Legacy mixed pool + opposite branch (safe during migration).
        $this->redis->rawCommand('SREM', $this->keys->legacyPresetOrderPoolKey($presetId), $orderId);
        foreach (PaymentModel::cases() as $case) {
            if ($case !== $model) {
                $this->redis->rawCommand(
                    'SREM',
                    $this->keys->presetOrderPoolKey($presetId, $case),
                    $orderId,
                );
            }
        }
        $this->redis->exec();
    }

    public function restoreSoldCounter(Order $order): void
    {
        if ($order->capacity === null) {
            return;
        }

        $currentLocalDay = $this->availabilityNormalizer->resolveLocalDay($order->dailyTzOffset);
        $soldKey = $this->keys->orderSoldKey($order->orderId, $currentLocalDay);

        if ($order->hasDailyLimit) {
            if ($order->dailyReceivedLocalDay !== $currentLocalDay) {
                return;
            }
            $count = $order->dailyReceivedCount ?? 0;
        } else {
            // Total-cap weight: seed today's sold from lifetime received.
            $count = $order->receivedCount ?? 0;
        }

        if ($count <= 0) {
            return;
        }

        $this->redis->set($soldKey, (string) $count, self::SOLD_COUNTER_TTL_SECONDS);
    }
}
