<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Schedule;

use DateTimeImmutable;
use DateTimeZone;

final class LocalDay
{
    public function resolve(int $tzOffsetSeconds, ?int $timestamp = null): int
    {
        return intdiv(($timestamp ?? time()) + $tzOffsetSeconds, 86400);
    }

    public function tzOffset(?AvailabilitySchedule $schedule): int
    {
        if ($schedule === null) {
            return 0;
        }

        $tz = new DateTimeZone($schedule->timezone);

        return $tz->getOffset(new DateTimeImmutable('now', $tz));
    }

    /**
     * iRev snapshot `schedule_tz` format: "+HHMM" / "-HHMM" (e.g. "+0300").
     */
    public function tzOffsetFromIrevScheduleTz(string $scheduleTz): int
    {
        $scheduleTz = trim($scheduleTz);
        if ($scheduleTz === '' || !preg_match('/^([+-])(\d{2})(\d{2})$/', $scheduleTz, $m)) {
            return 0;
        }

        $sign = $m[1] === '-' ? -1 : 1;

        return $sign * (((int) $m[2]) * 3600 + (int) $m[3] * 60);
    }
}
