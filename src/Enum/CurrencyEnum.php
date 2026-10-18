<?php
// src/Enum/CurrencyEnum.php

declare(strict_types=1);

namespace App\Enum;

enum CurrencyEnum: string
{
    case BRL = 'BRL';
    case USD = 'USD';

    public function labels(): string
    {
        return match ($this) {
            self::BRL => 'Real (BRL)',
            self::USD => 'US Dollar (USD)',
        };
    }

    /**
     * pt_BR currency symbol for display; balances are formatted per-account
     * currency rather than assumed to be BRL.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::BRL => 'R$',
            self::USD => 'US$',
        };
    }

    public function locale(): string
    {
        return match ($this) {
            self::BRL => 'pt_BR',
            self::USD => 'en_US',
        };
    }
}
