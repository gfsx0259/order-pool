<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Schedule;

/** Normalized availability fields for {@see \Enthusiast\OrderPool\ValueObject\Order}. */
final readonly class NormalizedAvailability
{
    public function __construct(
        public string $availabilityUtc,
        public int $dailyTzOffset,
    ) {}
}
