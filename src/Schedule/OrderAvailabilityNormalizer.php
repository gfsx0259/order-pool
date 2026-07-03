<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Schedule;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Schedule ingress + local day index from tz offset (sold counters, daily limits).
 */
final class OrderAvailabilityNormalizer
{
    private const string REFERENCE_MONDAY = '2024-01-01';

    public function resolveLocalDay(int $tzOffsetSeconds, ?int $timestamp = null): int
    {
        return intdiv(($timestamp ?? time()) + $tzOffsetSeconds, 86400);
    }

    public function fromLm(?AvailabilitySchedule $schedule): NormalizedAvailability
    {
        return new NormalizedAvailability(
            availabilityUtc: $this->resolveLmUtcWindows($schedule),
            dailyTzOffset: $this->lmTzOffset($schedule),
        );
    }

    /**
     * @param int|null $dailyTzOffset Override offset (e.g. debug scenarios); null → parse scheduleTz.
     */
    public function fromIrev(string $schedule, string $scheduleTz, ?int $dailyTzOffset = null): NormalizedAvailability
    {
        $offset = $dailyTzOffset ?? $this->irevTzOffset($scheduleTz);

        return new NormalizedAvailability(
            availabilityUtc: $this->resolveIrevUtcWindows($schedule, $offset),
            dailyTzOffset: $offset,
        );
    }

    private function lmTzOffset(?AvailabilitySchedule $schedule): int
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
    private function irevTzOffset(string $scheduleTz): int
    {
        $scheduleTz = trim($scheduleTz);
        if ($scheduleTz === '' || !preg_match('/^([+-])(\d{2})(\d{2})$/', $scheduleTz, $m)) {
            return 0;
        }

        $sign = $m[1] === '-' ? -1 : 1;

        return $sign * (((int) $m[2]) * 3600 + (int) $m[3] * 60);
    }

    private function resolveLmUtcWindows(?AvailabilitySchedule $schedule): string
    {
        if ($schedule === null || $schedule->isAlwaysAvailable()) {
            return '';
        }

        $timezone = new DateTimeZone($schedule->timezone);
        $segments = [];

        foreach ($schedule->windows as $window) {
            foreach ($window['days'] as $dayOfWeek) {
                $referenceDate = $this->referenceDateForDay($dayOfWeek);

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
                $startMin = $this->minutesFromMidnight($startUtc);
                $endMin = $this->minutesFromMidnight($endUtc);

                $segments[] = $this->segment($utcDow, $startMin, $endMin);
            }
        }

        return implode(',', $segments);
    }

    /**
     * iRev snapshot: single local window for today, fixed UTC offset (seconds).
     * Refreshed on every snapshot push (~15 min).
     */
    private function resolveIrevUtcWindows(string $schedule, int $tzOffsetSeconds): string
    {
        $schedule = trim($schedule);
        if ($schedule === '') {
            return '';
        }
        if (!preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $schedule, $m)) {
            return '';
        }

        $startLocal = ((int) $m[1]) * 60 + (int) $m[2];
        $endLocal = ((int) $m[3]) * 60 + (int) $m[4];
        $offsetMin = intdiv($tzOffsetSeconds, 60);

        $startUtc = ($startLocal - $offsetMin) % 1440;
        if ($startUtc < 0) {
            $startUtc += 1440;
        }
        $endUtc = ($endLocal - $offsetMin) % 1440;
        if ($endUtc < 0) {
            $endUtc += 1440;
        }

        $today = (int) (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('N');

        if ($startUtc <= $endUtc) {
            return $this->segment($today, $startUtc, $endUtc);
        }

        $next = $today === 7 ? 1 : $today + 1;

        return $this->segment($today, $startUtc, 1440) . ',' . $this->segment($next, 0, $endUtc);
    }

    private function segment(int $utcDow, int $startMin, int $endMin): string
    {
        return sprintf('%d:%d-%d', $utcDow, $startMin, $endMin);
    }

    private function referenceDateForDay(int $isoDayOfWeek): string
    {
        $monday = new DateTimeImmutable(self::REFERENCE_MONDAY);

        return $monday->modify('+' . ($isoDayOfWeek - 1) . ' days')->format('Y-m-d');
    }

    private function minutesFromMidnight(DateTimeImmutable $dt): int
    {
        return ((int) $dt->format('G')) * 60 + (int) $dt->format('i');
    }
}
