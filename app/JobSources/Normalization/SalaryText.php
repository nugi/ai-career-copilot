<?php

namespace App\JobSources\Normalization;

use App\Enums\SalaryPeriod;

/**
 * A salary read from text: either end may be missing, and so may the currency and the period.
 */
final readonly class SalaryText
{
    /**
     * Keep exact amounts and only the units explicitly present in salary text.
     */
    public function __construct(
        public ?float $min,
        public ?float $max,
        public ?string $currency,
        public ?SalaryPeriod $period,
    ) {}
}
