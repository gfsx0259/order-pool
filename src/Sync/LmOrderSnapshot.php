<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

final readonly class LmOrderSnapshot
{
    public function __construct(
        public int $orderId,
        public int $presetId,
        public string $partnerId,
        public int $rate,
        public string $availabilityUtc,
        public ?int $capacity,
        public ?int $dailyReceivedCount = null,
        public ?int $dailyReceivedLocalDay = null,
        public int $dailyTzOffset = 0,
    ) {}
}
