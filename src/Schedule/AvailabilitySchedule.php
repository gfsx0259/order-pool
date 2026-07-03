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

    public static function fromJson(mixed $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return self::fromArray($value);
        }

        if (!is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? self::fromArray($decoded) : null;
    }


    public function isAlwaysAvailable(): bool
    {
        return $this->windows === [];
    }

    private static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        return new self(
            timezone: $data['timezone'],
            windows: $data['windows'],
        );
    }
}
