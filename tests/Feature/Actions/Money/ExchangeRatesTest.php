<?php

use App\Actions\Money\ExchangeRates;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;

test('a cross rate goes through the euro and keeps its date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27', 'UTC'));
    ExchangeRate::factory()->create([
        'currency' => 'USD',
        'rate' => '1.1188',
        'rate_date' => '2026-10-26',
    ]);
    ExchangeRate::factory()->create([
        'currency' => 'IDR',
        'rate' => '20320.32',
        'rate_date' => '2026-10-26',
    ]);
    ExchangeRate::factory()->create([
        'currency' => 'USD',
        'rate' => '1.25',
        'rate_date' => '2026-10-20',
    ]);

    $exchangeRates = new ExchangeRates;
    $rate = $exchangeRates->latest('USD', 'IDR');

    expect($rate->value)->toEqualWithDelta(20320.32 / 1.1188, 0.0001);
    expect($rate->date->toDateString())->toBe('2026-10-26');
    expect($rate->isStale)->toBeFalse();
    expect($exchangeRates->latest('EUR', 'IDR')->value)->toEqualWithDelta(20320.32, 0.0001);
    expect($exchangeRates->latest('USD', 'XYZ'))->toBeNull();
});

test('a rate older than 7 days is stale', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27', 'UTC'));
    ExchangeRate::factory()->create([
        'currency' => 'USD',
        'rate' => '1.1188',
        'rate_date' => '2026-10-19',
    ]);
    ExchangeRate::factory()->create([
        'currency' => 'GBP',
        'rate' => '0.83820',
        'rate_date' => '2026-10-19',
    ]);

    $rate = (new ExchangeRates)->latest('USD', 'GBP');

    expect($rate->isStale)->toBeTrue();
});
