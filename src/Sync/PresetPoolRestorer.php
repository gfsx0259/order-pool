<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\WorkerTemplate\RedisClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Restores preset order pool in Redis with distributed lock (worker cache miss).
 *
 * LM orders are loaded from DB via {@see LmOrderPoolSync}.
 * IREV virtual orders are not restored here — they arrive via snapshot push.
 */
final readonly class PresetPoolRestorer
{
    private const int LOCK_TTL_SECONDS = 30;
    private const int MAX_WAIT_MICROSECONDS = 2_000_000;
    private const int WAIT_STEP_MICROSECONDS = 100_000;

    public function __construct(
        private RedisClientInterface $redis,
        private KeySchema $keys,
        private LmOrderPoolSync $lmOrderPoolSync,
        private ?LoggerInterface $logger = null,
    ) {}

    public function restore(int $presetId): bool
    {
        $lockKey = $this->keys->presetRestoreLockKey($presetId);

        $locked = $this->redis->set($lockKey, '1', ['nx', 'ex' => self::LOCK_TTL_SECONDS]);
        if (!$locked) {
            return $this->waitForPool($presetId);
        }

        try {
            $this->lmOrderPoolSync->syncPresetFromDatabase($presetId);

            $this->logger?->info('Preset pool restored from database', ['preset_id' => $presetId]);

            return true;
        } catch (Throwable $e) {
            $this->logger?->error('Failed to restore preset pool', [
                'preset_id' => $presetId,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $this->redis->del($lockKey);
        }
    }

    private function waitForPool(int $presetId): bool
    {
        $poolKey = $this->keys->presetOrderPoolKey($presetId);
        $waited = 0;

        while ($waited < self::MAX_WAIT_MICROSECONDS) {
            if ($this->redis->exists($poolKey)) {
                $this->logger?->debug('Preset pool restored by another worker', ['preset_id' => $presetId]);

                return true;
            }

            usleep(self::WAIT_STEP_MICROSECONDS);
            $waited += self::WAIT_STEP_MICROSECONDS;
        }

        $this->logger?->warning('Timeout waiting for preset pool restoration', ['preset_id' => $presetId]);

        return false;
    }
}
