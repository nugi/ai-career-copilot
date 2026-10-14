<?php

namespace App\Enums;

enum Currency: string
{
    case Idr = 'IDR';
    case Usd = 'USD';

    public function label(): string
    {
        return match ($this) {
            self::Idr => 'IDR (Rp)',
            self::Usd => 'USD ($)',
        };
    }
}
