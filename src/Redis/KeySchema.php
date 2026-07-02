<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Redis;

final readonly class KeySchema
{
    public function __construct(
        private string $prefix = '',
    ) {}

    public function presetOrderPoolKey(int $presetId): string
    {
        return $this->prefix . sprintf('preset:%d:orders_pool', $presetId);
    }

    public function orderDataKey(string $orderId): string
    {
        return $this->prefix . sprintf('order:%s:data', $orderId);
    }

    public function orderDeliveredKey(string $orderId, int $localDay): string
    {
        return $this->prefix . sprintf('order:%s:delivered:%d', $orderId, $localDay);
    }

    public function irevRemainingKey(string $partnerUuid): string
    {
        return $this->prefix . sprintf('irev:%s:remaining', $partnerUuid);
    }

    public function irevAssignedKey(string $partnerUuid, int $localDay): string
    {
        return $this->prefix . sprintf('irev:%s:lm_assigned:%d', $partnerUuid, $localDay);
    }
}

