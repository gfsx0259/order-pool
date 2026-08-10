<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Sync;

use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\OrderPool\Enum\PaymentModel;
use Enthusiast\WorkerTemplate\RedisClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Restores LM preset order pool in Redis on cache miss (distributed lock).
 *
 * IREV virtual orders are not restored — they arrive via snapshot push.
 */
final readonly class LmPoolRestorer
{
    private const int LOCK_TTL_SECONDS = 30;
    private const int MAX_WAIT_MICROSECONDS = 2_000_000;
    private const int WAIT_STEP_MICROSECONDS = 100_000;

    public function __construct(
        private RedisClientInterface $redis,
        private KeySchema $keys,
        private LmPresetPoolSync $lmPresetPoolSync,
        private LoggerInterface $logger,
    ) {}

    public function restore(int $presetId, PaymentModel|string $paymentModel = PaymentModel::CPL): bool
    {
        $lockKey = $this->keys->presetRestoreLockKey($presetId);

        $locked = $this->redis->set($lockKey, '1', ['nx', 'ex' => self::LOCK_TTL_SECONDS]);
        if (!$locked) {
            return $this->waitForPool($presetId, $paymentModel);
        }

        try {
            $this->lmPresetPoolSync->syncFromDatabase($presetId);

            $this->logger->info('LM preset pool restored from database', ['preset_id' => $presetId]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Failed to restore LM preset pool', [
                'preset_id' => $presetId,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $this->redis->del($lockKey);
        }
    }

    private function waitForPool(int $presetId, PaymentModel|string $paymentModel = PaymentModel::CPL): bool
    {
        $poolKey = $this->keys->presetOrderPoolKey($presetId, $paymentModel);
        $waited = 0;

        while ($waited < self::MAX_WAIT_MICROSECONDS) {
            if ($this->redis->exists($poolKey)) {
                $this->logger->debug('LM preset pool restored by another worker', ['preset_id' => $presetId]);

                return true;
            }

            usleep(self::WAIT_STEP_MICROSECONDS);
            $waited += self::WAIT_STEP_MICROSECONDS;
        }

        $this->logger->warning('Timeout waiting for LM preset pool restoration', ['preset_id' => $presetId]);

        return false;
    }
}
