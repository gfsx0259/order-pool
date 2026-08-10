<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\ValueObject;

use Enthusiast\OrderPool\Enum\PaymentModel;

/**
 * Normalized order for Redis pool sync (LM + IREV).
 *
 * Ingress must set availabilityUtc + dailyTzOffset.
 * orderId: numeric string for LM ("42"), "irev:{presetId}:{partnerUuid}" for IREV.
 *
 * `capacity` is the WDRR ceiling (remaining weight = capacity − sold):
 * - LM with daily_limit → daily_limit (hasDailyLimit=true)
 * - LM without daily_limit (dated / infinity) → limit_total (hasDailyLimit=false)
 * - IREV → snapshot remaining (resetSold on push)
 *
 * Pool membership: preset:{presetId}:orders_pool:{cpl|cpa}
 */
final readonly class Order
{
    public function __construct(
        public string $orderId,
        public int $presetId,
        public string $partnerId,
        public int $rate,
        public string $availabilityUtc,
        public ?int $capacity,
        public ?int $dailyReceivedCount = null,
        public ?int $dailyReceivedLocalDay = null,
        public int $dailyTzOffset = 0,
        /** Y-m-d calendar day, or null for infinity (always eligible while in_progress). */
        public ?string $date = null,
        /**
         * When true, sold is a daily counter and Postgres daily_* is updated.
         * When false, sold is seeded from lifetime received_count (total-cap weight).
         */
        public bool $hasDailyLimit = false,
        /** Lifetime received_count; seeds sold when !hasDailyLimit. */
        public ?int $receivedCount = null,
        public PaymentModel $paymentModel = PaymentModel::CPL,
    ) {}

    public static function irevOrderId(int $presetId, string $partnerUuid): string
    {
        return sprintf('irev:%d:%s', $presetId, $partnerUuid);
    }

    public function isIrev(): bool
    {
        return str_starts_with($this->orderId, 'irev:');
    }
}
