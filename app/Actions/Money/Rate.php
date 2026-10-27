<?php

namespace App\Actions\Money;

use Carbon\CarbonImmutable;

/**
 * A dated conversion from one unit of the source currency to the target currency.
 */
final readonly class Rate
{
    public function __construct(
        public float $value,
        public CarbonImmutable $date,
        public bool $isStale,
    ) {}

    /**
     * Convert an amount of the source currency into the target currency.
     */
    public function convert(float $amount): float
    {
        return $amount * $this->value;
    }
}
