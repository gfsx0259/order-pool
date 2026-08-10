<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Matching;

use DateMalformedStringException;
use Enthusiast\OrderPool\Clock\Clock;
use Enthusiast\OrderPool\Enum\PaymentModel;
use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\WorkerTemplate\RedisClientInterface;
use RuntimeException;

/**
 * Weighted Deficit Round-Robin matcher.
 *
 * Competes LM real orders and IREV virtual orders in a preset payment-model pool.
 *
 * Result kinds (uniform 6-tuple):
 * - ['lm'|'irev', <refId>, <partnerId>, <rate>, <localDay>, <hasDailyLimit>]
 * - null (no eligible order in tried pools)
 * - 'POOL_NOT_FOUND' (no Redis pool key after primary + fallback)
 *
 * When $dryRun=true, sold counters use `order:{id}:sold_dry:{day}` instead of `sold`.
 */
final class DeficitMatcher
{
    private const int DAILY_COUNTER_TTL_SECONDS = 172800; // 48h
    private static ?string $scriptCache = null;

    public function __construct(
        private readonly RedisClientInterface $redis,
        private readonly KeySchema $keys,
        private readonly Clock $clock,
        private readonly float $rateExponent = 1.0,
    ) {}

    /**
     * Match against CPL and/or CPA pools.
     *
     * Default flow: try primary pool (from $preferCpl), then fallback to the other.
     * When $forcePaymentModel is set (inject / resend), only that pool is used.
     *
     * @return array<int, string>|null|string
     * @throws DateMalformedStringException
     */
    public function match(
        int $presetId,
        bool $dryRun = false,
        bool $preferCpl = true,
        ?PaymentModel $forcePaymentModel = null,
    ): array|string|null {
        if ($forcePaymentModel !== null) {
            return $this->matchForPaymentModel($presetId, $dryRun, $forcePaymentModel);
        }

        [$primary, $secondary] = $preferCpl
            ? [PaymentModel::CPL, PaymentModel::CPA]
            : [PaymentModel::CPA, PaymentModel::CPL];

        $primaryResult = $this->matchForPaymentModel($presetId, $dryRun, $primary);

        if (is_array($primaryResult)) {
            return $primaryResult;
        }

        $secondaryResult = $this->matchForPaymentModel($presetId, $dryRun, $secondary);

        if (is_array($secondaryResult)) {
            return $secondaryResult;
        }

        if ($primaryResult === 'POOL_NOT_FOUND' && $secondaryResult === 'POOL_NOT_FOUND') {
            return 'POOL_NOT_FOUND';
        }

        return null;
    }

    /**
     * @return array<int, string>|null|string
     * @throws DateMalformedStringException
     */
    private function matchForPaymentModel(
        int $presetId,
        bool $dryRun,
        PaymentModel $paymentModel,
    ): array|string|null {
        $utc = $this->clock->now();
        $utcTs = (int) $utc->format('U');
        // ISO-8601 day of week: 1..7 (Mon..Sun). Must match `availability_utc` format.
        $nowDayOfWeek = (int) $utc->format('N');
        $nowMin = ((int) $utc->format('G')) * 60 + (int) $utc->format('i');

        $result = $this->redis->eval(
            self::script(),
            [
                $this->keys->presetOrderPoolKey($presetId, $paymentModel),
                (string) $nowDayOfWeek,
                (string) $nowMin,
                (string) $utcTs,
                (string) self::DAILY_COUNTER_TTL_SECONDS,
                (string) $this->rateExponent,
                $this->keys->prefix(),
                $dryRun ? '1' : '0',
            ],
            1,
        );

        if ($result === false) {
            $error = $this->redis->getLastError();
            if ($error !== null && $error !== '') {
                $this->redis->clearLastError();
                throw new RuntimeException("Redis eval failed: {$error}");
            }

            return null;
        }

        return $result;
    }

    private static function script(): string
    {
        if (self::$scriptCache !== null) {
            return self::$scriptCache;
        }

        $path = __DIR__ . '/match_deficit.lua';

        $txt = @file_get_contents($path);

        if ($txt === false || $txt === '') {
            throw new RuntimeException("Cannot read lua script: {$path}");
        }

        self::$scriptCache = $txt;

        return $txt;
    }
}
