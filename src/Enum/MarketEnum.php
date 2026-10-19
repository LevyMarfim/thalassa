<?php
// src/Enum/MarketEnum.php

declare(strict_types=1);

namespace App\Enum;

enum MarketEnum: string
{
    case FRACTIONAL = 'FRACTIONAL';
    case SPOT = 'SPOT';

    public function labels(): string
    {
        return match ($this) {
            self::FRACTIONAL => 'Fracionário',
            self::SPOT => 'À vista',
        };
    }
}
