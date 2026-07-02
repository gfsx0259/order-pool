<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Snapshot;

/**
 * One preset (LM) in the capacity snapshot.
 */
final readonly class PresetSnapshot
{
    /**
     * @param list<IrevOrderSlot> $orders
     */
    public function __construct(
        public int $presetId,
        public array $orders,
    ) {}
}

