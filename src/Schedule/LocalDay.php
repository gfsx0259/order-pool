<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Schedule;

use DateTimeImmutable;
use DateTimeZone;

final class LocalDay
{
    public static function resolve(int $tzOffsetSeconds, ?int $timestamp = null): int
    {
        return intdiv(($timestamp ?? time()) + $tzOffsetSeconds, 86400);
    }

    /**
     * @param array<string, mixed>|null $schedule
     */
    public static function tzOffsetFromSchedule(?array $schedule): int
    {
        $timezone = is_array($schedule) ? ($schedule['timezone'] ?? 'UTC') : 'UTC';
        $tz = new DateTimeZone(is_string($timezone) ? $timezone : 'UTC');

        return $tz->getOffset(new DateTimeImmutable('now', $tz));
    }

    /**
     * iRev snapshot `schedule_tz` format: "+HHMM" / "-HHMM" (e.g. "+0300").
     */
    public static function tzOffsetFromIrevScheduleTz(string $scheduleTz): int
    {
        $scheduleTz = trim($scheduleTz);
        if ($scheduleTz === '' || !preg_match('/^([+-])(\d{2})(\d{2})$/', $scheduleTz, $m)) {
            return 0;
        }

        $sign = $m[1] === '-' ? -1 : 1;

        return $sign * (((int) $m[2]) * 3600 + (int) $m[3] * 60);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function parseScheduleJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
