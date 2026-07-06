<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\ValueObject;

/**
 * Normalized order for Redis pool sync (LM + IREV).
 *
 * Ingress must set availabilityUtc + dailyTzOffset.
 * orderId: numeric string for LM ("42"), "irev:{presetId}:{partnerUuid}" for IREV.
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

    public static function irevOrderId(int $presetId, string $partnerUuid): string
    {
        return sprintf('irev:%d:%s', $presetId, $partnerUuid);
    }

    public function isIrev(): bool
    {
        return str_starts_with($this->orderId, 'irev:');
    }
}
