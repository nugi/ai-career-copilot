<?php

namespace App\Enums;

enum SalaryPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'per month',
            self::Yearly => 'per year',
        };
    }
}
