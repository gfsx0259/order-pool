<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Schedule;

final readonly class AvailabilitySchedule
{
    /**
     * @param list<array{days: list<int>, start: string, end: string}> $windows
     */
    public function __construct(
        public string $timezone,
        public array $windows,
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        return new self(
            timezone: $data['timezone'],
            windows: $data['windows'],
        );
    }

    public function isAlwaysAvailable(): bool
    {
        return $this->windows === [];
    }
}
