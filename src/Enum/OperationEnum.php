<?php
// src/Enum/Operation.php

declare(strict_types=1);

namespace App\Enum;

enum OperationEnum: string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';

    public function labels(): string
    {
        return match ($this) {
            self::CREDIT => 'Crédito',
            self::DEBIT => 'Débito',
        };
    }
}