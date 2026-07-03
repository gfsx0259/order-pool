<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Redis;

final readonly class KeySchema
{
    public function __construct(
        private string $prefix = '',
    ) {}

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function presetOrderPoolKey(int $presetId): string
    {
        return $this->prefix . sprintf('preset:%d:orders_pool', $presetId);
    }

    public function presetRestoreLockKey(int $presetId): string
    {
        return $this->prefix . sprintf('preset:%d:restore_lock', $presetId);
    }

    /** WD-RR match history (Redis LIST, last 500, TTL 24h). */
    public function presetHistoryKey(int $presetId): string
    {
        return $this->prefix . sprintf('preset:%d:history', $presetId);
    }

    public function orderDataKey(string $orderId): string
    {
        return $this->prefix . sprintf('order:%s:data', $orderId);
    }

    /**
     * How many leads LM routed to this order since last capacity refresh (LM: day-scoped, IREV: reset on snapshot).
     */
    public function orderSoldKey(string $orderId, int $localDay): string
    {
        return $this->prefix . sprintf('order:%s:sold:%d', $orderId, $localDay);
    }

    /**
     * Dry-run WD-RR counters (separate from prod {@see orderSoldKey}).
     */
    public function orderSoldDryKey(string $orderId, int $localDay): string
    {
        return $this->prefix . sprintf('order:%s:sold_dry:%d', $orderId, $localDay);
    }
}
