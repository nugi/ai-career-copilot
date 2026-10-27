<?php

namespace App\Actions\Money;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;

/**
 * Reads the ECB rates stored by exchange-rates:fetch.
 *
 * Every rate carries its date so the page can show it (ADR 0004).
 */
final class ExchangeRates
{
    /**
     * Get the latest conversion from the source to each known currency and EUR in one query.
     *
     * @return array<string, Rate>
     */
    public function latestFrom(string $from): array
    {
        $rows = ExchangeRate::query()
            ->select(['currency', 'rate', 'rate_date'])
            ->distinct('currency')
            ->orderBy('currency')
            ->orderByDesc('rate_date')
            ->get()
            ->keyBy('currency');

        $sourceDate = $from === 'EUR' ? $rows->max('rate_date') : $rows->get($from)?->rate_date;

        if ($sourceDate === null) {
            return [];
        }

        $sourceDate = CarbonImmutable::instance($sourceDate);
        $sourceValue = $from === 'EUR' ? 1.0 : (float) $rows->get($from)->rate;
        $staleBefore = CarbonImmutable::today('UTC')->subDays(7);
        $rates = [
            'EUR' => new Rate(1.0 / $sourceValue, $sourceDate, $sourceDate->lessThan($staleBefore)),
        ];

        foreach ($rows as $currency => $row) {
            $date = $sourceDate->min(CarbonImmutable::instance($row->rate_date));
            $rates[$currency] = new Rate(
                (float) $row->rate / $sourceValue,
                $date,
                $date->lessThan($staleBefore),
            );
        }

        return $rates;
    }

    /**
     * Get the latest conversion from one currency to another, or null when either has no stored rate.
     */
    public function latest(string $from, string $to): ?Rate
    {
        return $this->latestFrom($from)[$to] ?? null;
    }
}
