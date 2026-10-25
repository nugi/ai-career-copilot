<?php

namespace App\JobSources\Normalization;

use App\Enums\SalaryPeriod;

/**
 * Reads a salary from free text such as "Up to k a year" or "Rp 15-20 juta per bulan". Text that is
 * ambiguous gives no salary, never a guess.
 */
final class SalaryTextParser
{
    private const string MULTIPLIERS = 'mn|juta|jt|ribu|rb|lakh|lac|k|m';

    private const string CURRENCY_TOKEN = '(?:[A-Z]{3}|'.self::DOLLAR_PREFIXES.'|Rp|[$€£₹¥])';

    /**
     * Dollar symbols that name their own currency, so only a bare "$" depends on the country.
     */
    private const string DOLLAR_PREFIXES = '(?<![A-Za-z])(?:US\$|AU\$|A\$|CA\$|C\$|S\$|NZ\$|HK\$|NT\$|MX\$|R\$)';

    /**
     * @var array<string, string>
     */
    private const array PREFIXED_DOLLARS = [
        'US$' => 'USD', 'AU$' => 'AUD', 'A$' => 'AUD', 'CA$' => 'CAD', 'C$' => 'CAD',
        'S$' => 'SGD', 'NZ$' => 'NZD', 'HK$' => 'HKD', 'NT$' => 'TWD', 'MX$' => 'MXN', 'R$' => 'BRL',
    ];

    /**
     * Countries whose local dollar is not in CURRENCIES, so a bare "$" there has no known currency.
     *
     * @var list<string>
     */
    private const array OTHER_DOLLAR_COUNTRIES = [
        'AR', 'CL', 'CO', 'CU', 'DO', 'UY', 'BS', 'BB', 'BZ', 'BM', 'BN', 'KY',
        'FJ', 'GY', 'JM', 'LR', 'NA', 'SB', 'SR', 'TT', 'TW',
    ];

    /** @var list<string> */
    private const array CURRENCIES = [
        'USD', 'EUR', 'GBP', 'IDR', 'SGD', 'AUD', 'CAD', 'INR', 'JPY', 'CNY',
        'CHF', 'NZD', 'HKD', 'SEK', 'NOK', 'DKK', 'KRW', 'TWD', 'THB', 'MYR',
        'PHP', 'VND', 'AED', 'SAR', 'ZAR', 'BRL', 'MXN', 'PLN', 'TRY', 'RUB',
    ];

    /**
     * Read a single amount or an explicit range, never unrelated or reversed amounts.
     */
    public function parse(string $text, ?string $countryCode = null): ?SalaryText
    {
        preg_match_all(
            '~\d+(?:[.,]\d+)*(?:\s*(?:'.self::MULTIPLIERS.')(?!\p{L}))?~iu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        $amounts = $matches[0];

        if (count($amounts) < 1 || count($amounts) > 2) {
            return null;
        }

        $first = $this->amount($amounts[0][0]);

        if ($first === null) {
            return null;
        }

        $min = $first['amount'];
        $max = $min;

        if (count($amounts) === 2) {
            $gap = substr($text, $amounts[0][1] + strlen($amounts[0][0]), $amounts[1][1] - $amounts[0][1] - strlen($amounts[0][0]));

            if (preg_match('~^\s*(?:'.self::CURRENCY_TOKEN.')?\s*(?:[-–—]|to|sampai|s/d)\s*(?:'.self::CURRENCY_TOKEN.')?\s*$~iu', $gap) !== 1) {
                return null;
            }

            $second = $this->amount($amounts[1][0]);

            if ($second === null) {
                return null;
            }

            if (! $first['hasMultiplier']) {
                $min *= $second['multiplier'];
            }

            $max = $second['amount'];

            if ($min > $max) {
                return null;
            }
        } else {
            $prefix = substr($text, 0, $amounts[0][1]);
            $prefix = preg_replace('~\b(?:'.implode('|', self::CURRENCIES).')\b|'.self::DOLLAR_PREFIXES.'|Rp|[$€£₹¥]~u', '', $prefix) ?? $prefix;

            if (preg_match('/\b(?:up\s+to|max|maximum|hingga|sampai)\s*$/iu', $prefix) === 1) {
                $min = null;
            } elseif (preg_match('/\b(?:from|min|minimum|starting\s+at|mulai)\s*$/iu', $prefix) === 1) {
                $max = null;
            } elseif (preg_match('/[-–—]\s*$/u', $prefix) === 1) {
                return null;
            }
        }

        return new SalaryText($min, $max, $this->currency($text, $countryCode), self::period($text));
    }

    /**
     * Share the same explicit period vocabulary between source fields and salary text.
     */
    public static function period(string $text): ?SalaryPeriod
    {
        foreach ([
            'fortnightly' => '~\b(?:fortnight|fortnightly|biweekly|bi-weekly)\b~iu',
            'hourly' => '~\b(?:hour|hr|hourly|jam)\b|/h\b~iu',
            'daily' => '~\b(?:day|daily|hari)\b~iu',
            'weekly' => '~\b(?:week|weekly|minggu)\b~iu',
            'monthly' => '~\b(?:month|monthly|mo|bulan|bln)\b~iu',
            'yearly' => '~\b(?:year|yearly|yr|annual|annually|annum|tahun|thn)\b|\bp\.a\.(?!\p{L})~iu',
        ] as $period => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return SalaryPeriod::from($period);
            }
        }

        return null;
    }

    /**
     * Interpret separators independently and preserve the exact fractional amount.
     *
     * @return array{amount: float, multiplier: int, hasMultiplier: bool}|null
     */
    private function amount(string $text): ?array
    {
        if (preg_match('~^(?<number>\d+(?:[.,]\d+)*)(?:\s*(?<multiplier>'.self::MULTIPLIERS.'))?$~iu', $text, $parts) !== 1) {
            return null;
        }

        $number = preg_replace('/[.,](?=\d{3}(?:\D|$))/', '', $parts['number']);
        $number = str_replace(',', '.', $number ?? $parts['number']);

        if (! is_numeric($number)) {
            return null;
        }

        $multiplier = match (strtolower($parts['multiplier'] ?? '')) {
            'k', 'ribu', 'rb' => 1000,
            'm', 'mn', 'juta', 'jt' => 1000000,
            'lakh', 'lac' => 100000,
            default => 1,
        };
        $amount = (float) $number * $multiplier;

        return is_finite($amount) ? [
            'amount' => $amount,
            'multiplier' => $multiplier,
            'hasMultiplier' => isset($parts['multiplier']),
        ] : null;
    }

    /**
     * Prefer an adjacent known ISO code to symbols; a bare yen symbol stays ambiguous. A bare dollar sign
     * follows the posting's country.
     */
    private function currency(string $text, ?string $countryCode): ?string
    {
        preg_match_all('/(?<![A-Za-z])[A-Z]{3}(?![A-Za-z])/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $codes = [];
        $hasCode = false;

        foreach ($matches[0] as [$code, $offset]) {
            if (! in_array($code, self::CURRENCIES, true)) {
                continue;
            }

            $hasCode = true;
            $before = substr($text, 0, $offset);
            $after = substr($text, $offset + strlen($code));

            if (preg_match('~^\s*(?:'.self::DOLLAR_PREFIXES.'|[$€£₹¥])?\s*\d~u', $after) === 1
                || preg_match('~\d(?:[.,]\d+)*(?:\s*(?:'.self::MULTIPLIERS.'))?\s*$~iu', $before) === 1) {
                $codes[] = $code;
            }
        }

        $codes = array_values(array_unique($codes));

        if ($codes !== []) {
            return count($codes) === 1 ? $codes[0] : null;
        }

        if ($hasCode) {
            return null;
        }

        foreach (self::PREFIXED_DOLLARS as $symbol => $currency) {
            if (preg_match('~(?<![A-Za-z])'.preg_quote($symbol, '~').'~u', $text) === 1) {
                return $currency;
            }
        }

        foreach (['€' => 'EUR', '£' => 'GBP', 'Rp' => 'IDR', '₹' => 'INR'] as $symbol => $currency) {
            if (str_contains($text, $symbol)) {
                return $currency;
            }
        }

        if (str_contains($text, '$')) {
            return self::dollarCurrency($countryCode);
        }

        return null;
    }

    /**
     * Read a bare dollar sign as the posted country's dollar, and leave a local dollar outside CURRENCIES unknown.
     */
    private static function dollarCurrency(?string $countryCode): ?string
    {
        if ($countryCode === null) {
            return 'USD';
        }

        return match ($countryCode) {
            'CA' => 'CAD',
            'AU' => 'AUD',
            'NZ' => 'NZD',
            'SG' => 'SGD',
            'HK' => 'HKD',
            'MX' => 'MXN',
            default => in_array($countryCode, self::OTHER_DOLLAR_COUNTRIES, true) ? null : 'USD',
        };
    }
}
