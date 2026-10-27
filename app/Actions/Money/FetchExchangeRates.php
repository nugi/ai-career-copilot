<?php

namespace App\Actions\Money;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Store the ECB daily reference rates: the units of each currency for 1 EUR, on the date that the file gives.
 *
 * A run on the same file stores no new rows. A row with an invalid currency or rate is skipped and logged, and a failed
 * request or a file without a valid date throws, so the stored rates stay as they were.
 */
final class FetchExchangeRates
{
    /**
     * Fetch and upsert the reference rates for the date published by the ECB.
     */
    public function handle(): int
    {
        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->retry(2, 1000, throw: false)
            ->get(config()->string('services.ecb.rates_url'));

        if (! $response->successful()) {
            throw new RuntimeException("The ECB rates request failed with status {$response->status()}.");
        }

        $previousErrors = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($response->body(), options: LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if ($xml === false) {
            throw new RuntimeException('The ECB rates file has no valid date.');
        }

        $namespace = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';
        $xml->registerXPathNamespace('ecb', $namespace);
        $datedCube = ($xml->xpath('//ecb:Cube[@time]') ?: [])[0] ?? null;
        $date = (string) ($datedCube?->attributes()['time'] ?? '');

        if (! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new RuntimeException('The ECB rates file has no valid date.');
        }

        /** @var list<array{rate_date: string, currency: string, rate: string}> $rates */
        $rates = [];

        foreach ($datedCube->children($namespace)->Cube as $cube) {
            $currency = (string) $cube->attributes()['currency'];
            $rate = (string) $cube->attributes()['rate'];

            if (! preg_match('/^[A-Z]{3}$/D', $currency)
                || ! is_numeric($rate) || ! is_finite((float) $rate) || (float) $rate <= 0) {
                Log::warning('exchange_rates.skipped', ['currency' => $currency, 'rate' => $rate]);

                continue;
            }

            $rates[] = ['rate_date' => $date, 'currency' => $currency, 'rate' => $rate];
        }

        ExchangeRate::query()->upsert($rates, ['rate_date', 'currency'], ['rate', 'updated_at']);

        $count = count($rates);

        Log::info('exchange_rates.fetched', ['rate_date' => $date, 'count' => $count]);

        return $count;
    }
}
