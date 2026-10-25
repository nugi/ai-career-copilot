<?php

use App\Enums\SalaryPeriod;
use App\JobSources\Normalization\SalaryText;
use App\JobSources\Normalization\SalaryTextParser;

test('salary text preserves its amounts and posted units', function (string $text, ?string $countryCode, SalaryText $expected) {
    $parsed = (new SalaryTextParser)->parse($text, $countryCode);

    expect($parsed)->toEqual($expected);
})->with([
    'dollar range per year' => ['$80,000 - $100,000 a year', null, new SalaryText(80000, 100000, 'USD', SalaryPeriod::Yearly)],
    'euro range in thousands' => ['€45k–60k', null, new SalaryText(45000, 60000, 'EUR', null)],
    'upper bound' => ['Up to $60k per year', null, new SalaryText(null, 60000, 'USD', SalaryPeriod::Yearly)],
    'lower bound' => ['From £40,000', null, new SalaryText(40000, null, 'GBP', null)],
    'fractional hourly pay' => ['USD 25.50/hr', null, new SalaryText(25.5, 25.5, 'USD', SalaryPeriod::Hourly)],
    'Indonesian monthly range' => ['Rp 15-20 juta per bulan', null, new SalaryText(15000000, 20000000, 'IDR', SalaryPeriod::Monthly)],
    'Indonesian thousands separators' => ['Rp 8.000.000 / bulan', null, new SalaryText(8000000, 8000000, 'IDR', SalaryPeriod::Monthly)],
    'Indian lakh' => ['₹12 lakh per annum', null, new SalaryText(1200000, 1200000, 'INR', SalaryPeriod::Yearly)],
    'currency code overrides dollar symbol' => ['CAD $90,000', null, new SalaryText(90000, 90000, 'CAD', null)],
    'no currency or period' => ['50k-70k', null, new SalaryText(50000, 70000, null, null)],
    'canadian bare dollar' => ['$80k a year', 'CA', new SalaryText(80000, 80000, 'CAD', SalaryPeriod::Yearly)],
    'us prefixed dollar' => ['US$90,000', null, new SalaryText(90000, 90000, 'USD', null)],
    'argentine bare dollar' => ['$80k a year', 'AR', new SalaryText(80000, 80000, null, SalaryPeriod::Yearly)],
]);

test('salary text without a usable amount is rejected', function (string $text) {
    expect((new SalaryTextParser)->parse($text))->toBeNull();
})->with([
    'no amount' => ['Competitive salary'],
    'reversed range' => ['100k - 80k'],
]);
