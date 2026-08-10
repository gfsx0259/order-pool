<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Enum;

enum PaymentModel: string
{
    case CPL = 'cpl';
    case CPA = 'cpa';

    public static function normalize(string $value): self
    {
        return self::tryFrom(strtolower($value)) ?? self::CPL;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
