<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Enthusiast\OrderPool\ValueObject\Preset;
use Psr\Log\LoggerInterface;

/** Orchestrates IREV snapshot presets → per-preset Redis apply. */
final readonly class IrevSnapshotSync
{
    public function __construct(
        private IrevPresetPoolSync $irevPresetPoolSync,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<Preset> $presets
     */
    public function apply(array $presets): void
    {
        if ($presets === []) {
            $this->logger->warning('IREV snapshot: empty presets');
            return;
        }

        foreach ($presets as $preset) {
            $this->irevPresetPoolSync->apply($preset);
        }
    }
}
