<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Schedule;

use DateTimeImmutable;
use DateTimeZone;

final class AvailabilityScheduleUtc
{
    private const string REFERENCE_MONDAY = '2024-01-01';

    public static function toUtcWindows(?AvailabilitySchedule $schedule): string
    {
        if ($schedule === null || $schedule->isAlwaysAvailable()) {
            return '';
        }

        $timezone = new DateTimeZone($schedule->timezone);
        $segments = [];

        foreach ($schedule->windows as $window) {
            foreach ($window['days'] as $dayOfWeek) {
                $referenceDate = self::referenceDateForDay($dayOfWeek);

                $startLocal = new DateTimeImmutable(
                    $referenceDate . ' ' . $window['start'] . ':00',
                    $timezone,
                );
                $endLocal = new DateTimeImmutable(
                    $referenceDate . ' ' . $window['end'] . ':00',
                    $timezone,
                );

                $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'));
                $endUtc = $endLocal->setTimezone(new DateTimeZone('UTC'));

                if ($startUtc->format('Y-m-d') !== $endUtc->format('Y-m-d')) {
                    throw new \InvalidArgumentException(
                        'availability window crosses UTC midnight is not supported in v1',
                    );
                }

                $utcDow = (int) $startUtc->format('N');
                $startMin = self::minutesFromMidnight($startUtc);
                $endMin = self::minutesFromMidnight($endUtc);

                $segments[] = sprintf('%d:%d-%d', $utcDow, $startMin, $endMin);
            }
        }

        return implode(',', $segments);
    }

    private static function referenceDateForDay(int $isoDayOfWeek): string
    {
        $monday = new DateTimeImmutable(self::REFERENCE_MONDAY);

        return $monday->modify('+' . ($isoDayOfWeek - 1) . ' days')->format('Y-m-d');
    }

    private static function minutesFromMidnight(DateTimeImmutable $dt): int
    {
        return ((int) $dt->format('G')) * 60 + (int) $dt->format('i');
    }
}
