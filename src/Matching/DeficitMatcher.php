<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Matching;

use DateMalformedStringException;
use Enthusiast\OrderPool\Clock\Clock;
use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\WorkerTemplate\RedisClientInterface;
use RuntimeException;

/**
 * Weighted Deficit Round-Robin matcher.
 *
 * Competes LM real orders and IREV virtual orders in the same preset pool.
 *
 * Result kinds (uniform 6-tuple):
 * - ['lm'|'irev', <refId>, <partnerId>, <rate>, <localDay>, <hasDailyLimit>]
 *   refId = LM order id (numeric string) or IREV partner uuid
 *   hasDailyLimit = '1' when order has an explicit daily_limit (Postgres daily_*), else '0'
 * - null (no eligible)
 * - 'POOL_NOT_FOUND' (missing pool key)
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
     * @return array<int, string>|null|string
     * @throws DateMalformedStringException
     */
    public function match(
        int $presetId,
        bool $dryRun = false,
    ): array|string|null {
        $utc = $this->clock->now();
        $utcTs = (int) $utc->format('U');
        // ISO-8601 day of week: 1..7 (Mon..Sun). Must match `availability_utc` format.
        $nowDayOfWeek = (int) $utc->format('N');
        $nowMin = ((int) $utc->format('G')) * 60 + (int) $utc->format('i');

        $result = $this->redis->eval(
            self::script(),
            [
                $this->keys->presetOrderPoolKey($presetId),
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

