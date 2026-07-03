<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\ValueObject;

/** Preset order pool slice for Redis sync. */
final readonly class Preset
{
    /**
     * @param list<Order> $orders
     */
    public function __construct(
        public int $presetId,
        public array $orders,
    ) {}
}
