<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * Wall clock with optional override via env `ORDER_POOL_NOW` (dev/test only).
 *
 * `now()` always returns the instant in UTC.
 * Example: `ORDER_POOL_NOW=2026-08-06T12:00:00+03:00`
 */
final class Clock
{
    private readonly ?DateTimeImmutable $fixed;

    public function __construct(?DateTimeImmutable $fixed = null)
    {
        $this->fixed = $fixed ?? $this->parseEnvOverride();
    }

    public function now(): DateTimeImmutable
    {
        $instant = $this->fixed ?? new DateTimeImmutable('now');

        return $instant
            ->setTimezone(new DateTimeZone('UTC'));
    }

    private function parseEnvOverride(): ?DateTimeImmutable
    {
        $raw = $_ENV['ORDER_POOL_NOW'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception $e) {
            throw new InvalidArgumentException(
                sprintf('Invalid ORDER_POOL_NOW="%s": %s', $raw, $e->getMessage()),
                0,
                $e,
            );
        }
    }
}
