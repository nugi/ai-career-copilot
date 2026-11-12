<?php

namespace App\Actions\Jobs;

use App\Enums\SalaryPeriod;

/**
 * Represent a minimum salary in its original currency and period.
 */
final readonly class SalaryFloor
{
    /**
     * @param  int  $amount  The minimum salary in whole currency units.
     * @param  string  $currency  The uppercase ISO 4217 currency code.
     * @param  SalaryPeriod  $period  The period covered by the salary.
     */
    public function __construct(
        public int $amount,
        public string $currency,
        public SalaryPeriod $period,
    ) {}
}
