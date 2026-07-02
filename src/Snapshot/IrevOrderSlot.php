<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Snapshot;

final readonly class IrevOrderSlot
{
    public function __construct(
        public string $partnerUuid,
        public string $partnerName,
        public string $paymentModel,
        public ?float $rate,
        public ?int $remaining,
        public string $schedule,
        public string $scheduleTz,
    ) {}
}

