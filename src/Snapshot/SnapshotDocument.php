<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Snapshot;

/**
 * Normalized snapshot document for OrderPool input.
 *
 * This is the contract between API (/capacity/push) and order-pool.
 */
final readonly class SnapshotDocument
{
    /**
     * @param list<CountrySnapshot> $countries
     */
    public function __construct(
        public array $countries,
    ) {}
}

