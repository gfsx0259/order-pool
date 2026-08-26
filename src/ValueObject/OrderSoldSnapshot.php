<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\ValueObject;

/**
 * Live LM counters from Postgres + the current local day for this order's TZ.
 *
 * Used to (re)seed Redis `sold` and matcher in-memory remaining after
 * delivery returns, without a second query.
 */
final readonly class OrderSoldSnapshot
{
    public function __construct(
        public int $orderId,
        public int $limitTotal,
        public int $receivedCount,
        public ?int $dailyLimit,
        public ?int $dailyReceivedCount,
        public ?int $dailyReceivedLocalDay,
        public int $currentLocalDay,
        public bool $hasDailyLimit,
    ) {}

    /**
     * Slots left for matching: lifetime remaining, capped by today's daily remaining.
     */
    public function remainingCapacity(
        int $pendingReceived = 0,
        int $pendingDaily = 0,
        ?int $pendingDailyLocalDay = null,
    ): int {
        $totalRemaining = max(0, $this->limitTotal - $this->receivedCount - $pendingReceived);
        if (!$this->hasDailyLimit || $this->dailyLimit === null) {
            return $totalRemaining;
        }

        $dailyRemaining = max(0, $this->dailyLimit - $this->dailyUsedForCurrentDay(
            $pendingDaily,
            $pendingDailyLocalDay,
        ));

        return min($totalRemaining, $dailyRemaining);
    }

    /**
     * Value to write into `order:{id}:sold:{currentLocalDay}`.
     */
    public function redisSoldCount(
        int $pendingReceived = 0,
        int $pendingDaily = 0,
        ?int $pendingDailyLocalDay = null,
    ): int {
        if ($this->hasDailyLimit) {
            return $this->dailyUsedForCurrentDay($pendingDaily, $pendingDailyLocalDay);
        }

        return max(0, $this->receivedCount + $pendingReceived);
    }

    public function isLifetimeExhausted(int $pendingReceived = 0): bool
    {
        return $this->limitTotal - $this->receivedCount - $pendingReceived <= 0;
    }

    private function dailyUsedForCurrentDay(int $pendingDaily, ?int $pendingDailyLocalDay): int
    {
        $fromDb = $this->dailyReceivedLocalDay === $this->currentLocalDay
            ? ($this->dailyReceivedCount ?? 0)
            : 0;

        $fromPending = $pendingDailyLocalDay === $this->currentLocalDay
            ? $pendingDaily
            : 0;

        return max(0, $fromDb + $fromPending);
    }
}
