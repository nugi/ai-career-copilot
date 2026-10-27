<?php

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

test('the daily file is stored once', function () {
    Http::fake([
        config('services.ecb.rates_url') => Http::response(
            file_get_contents(base_path('tests/Fixtures/exchange-rates/eurofxref-daily.xml')),
            200,
            ['Content-Type' => 'application/xml'],
        ),
    ]);

    $this->artisan('exchange-rates:fetch')
        ->expectsOutput('Stored 3 rates.')
        ->assertExitCode(0);
    $this->artisan('exchange-rates:fetch')
        ->expectsOutput('Stored 3 rates.')
        ->assertExitCode(0);

    $this->assertDatabaseCount('exchange_rates', 3);

    $rate = ExchangeRate::query()->where('currency', 'IDR')->sole();

    expect($rate->rate_date->toDateString())->toBe('2026-10-26');
    expect($rate->rate)->toBe('20320.32000000');
});

test('a failed request stores nothing and fails', function () {
    Sleep::fake();
    Http::fake([
        config('services.ecb.rates_url') => Http::response('', 503),
    ]);

    $this->artisan('exchange-rates:fetch')
        ->expectsOutput('The ECB rates request failed with status 503.')
        ->assertExitCode(1);

    $this->assertDatabaseCount('exchange_rates', 0);
});
