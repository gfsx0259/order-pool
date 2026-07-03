<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Matching;

use DateTimeImmutable;
use DateTimeZone;
use Enthusiast\OrderPool\Redis\KeySchema;
use Enthusiast\WorkerTemplate\RedisClientInterface;

/**
 * Weighted Deficit Round-Robin matcher.
 *
 * Competes LM real orders and IREV virtual orders in the same preset pool.
 *
 * Result kinds (uniform 4-tuple):
 * - ['lm'|'irev', <refId>, <partnerId>, <rate>]
 *   refId = LM order id (numeric string) or IREV partner uuid
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
        private readonly float $rateExponent = 1.0,
    ) {}

    /**
     * @return array<int, string>|null|string
     */
    public function match(
        int $presetId,
        DateTimeImmutable $nowUtc,
        bool $dryRun = false,
        bool $debug = false,
        ?string $debugLabel = null,
    ): array|string|null {
        $poolKey = $this->keys->presetOrderPoolKey($presetId);

        $keys = [$poolKey];
        $numKeys = 1;
        if ($debug) {
            $keys[] = $this->keys->presetMatchHistoryKey($presetId);
            $numKeys = 2;
        }

        $tz = new DateTimeZone('UTC');

        $utc = $nowUtc->setTimezone($tz);
        $utcTs = (int) $utc->format('U');
        // ISO-8601 day of week: 1..7 (Mon..Sun). Must match `availability_utc` format.
        $nowDayOfWeek = (int) $utc->format('N');
        $nowMin = ((int) $utc->format('G')) * 60 + (int) $utc->format('i');

        $alpha = $this->rateExponent;

        $result = $this->redis->eval(
            self::script(),
            [
                ...$keys,
                (string) $nowDayOfWeek,
                (string) $nowMin,
                (string) $utcTs,
                (string) self::DAILY_COUNTER_TTL_SECONDS,
                (string) $alpha,
                $this->keys->prefix(),
                $dryRun ? '1' : '0',
                $debugLabel ?? '',
                '500',
            ],
            $numKeys,
        );

        if ($result === false) {
            $error = $this->redis->getLastError();
            if ($error !== null && $error !== '') {
                $this->redis->clearLastError();
                throw new \RuntimeException("Redis eval failed: {$error}");
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
            throw new \RuntimeException("Cannot read lua script: {$path}");
        }

        self::$scriptCache = $txt;

        return $txt;
    }
}

