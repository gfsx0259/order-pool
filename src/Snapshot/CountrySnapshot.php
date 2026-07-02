<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Snapshot;

/**
 * One country in the capacity snapshot.
 */
final readonly class CountrySnapshot
{
    /**
     * @param list<IrevOrderSlot> $orders
     */
    public function __construct(
        public string $code,
        public array $orders,
    ) {}
}

