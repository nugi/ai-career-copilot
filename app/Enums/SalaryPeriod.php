<?php

namespace App\Enums;

enum SalaryPeriod: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Hourly => 'per hour',
            self::Daily => 'per day',
            self::Weekly => 'per week',
            self::Fortnightly => 'per fortnight',
            self::Monthly => 'per month',
            self::Yearly => 'per year',
        };
    }

    /**
     * A user states a salary floor per month or per year (ADR 0017).
     *
     * @return list<self>
     */
    public static function profileCases(): array
    {
        return [self::Monthly, self::Yearly];
    }

    /**
     * Annual factors are 2080 hourly, 260 daily, 52 weekly, 26 fortnightly,
     * 12 monthly and 1 yearly (ADR 0017). Indonesian monthly pay counts
     * 13 payments to include THR (ADR 0004).
     */
    public function annualFactor(?string $countryCode): int
    {
        return match ($this) {
            self::Hourly => 2080,
            self::Daily => 260,
            self::Weekly => 52,
            self::Fortnightly => 26,
            self::Monthly => $countryCode === 'ID' ? 13 : 12,
            self::Yearly => 1,
        };
    }
}
