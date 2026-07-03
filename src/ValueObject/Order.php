<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\ValueObject;

/**
 * Normalized order for Redis pool sync (LM + IREV).
 *
 * Ingress must set availabilityUtc + dailyTzOffset.
 * orderId: numeric string for LM ("42"), "irev:{uuid}" for IREV virtual orders.
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
    ) {}

    public function isIrev(): bool
    {
        return str_starts_with($this->orderId, 'irev:');
    }
}
