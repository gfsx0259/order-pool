<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Preset;

/** Preset id + routing flags for worker-matcher. */
final readonly class ResolvedPreset
{
    public function __construct(
        public int $id,
        public bool $preferCpl = true,
    ) {}
}
