<?php

test('a text with an email address, a phone number or a given personal value is not redacted', function (string $text, array $personalValues) {
    expect($text)->not->toBeRedacted(...$personalValues);
})->with([
    'an email address' => ['Contact rina.wijaya@example.com for details', []],
    'an international phone number' => ['Call +44 20 7946 0958 today', []],
    'a phone number without a country code' => ['Call (415) 555-0132 today', []],
    'a personal value in another case' => ['RINA leads the team', ['Rina']],
]);

test('a text with placeholders, dates and amounts is redacted', function (string $text) {
    expect($text)->toBeRedacted('Rina');
})->with([
    'placeholders' => '[NAME] | [EMAIL] | [PHONE] | [URL] | [ADDRESS] | NIK [NIK]',
    'dates and year ranges' => 'Backend Engineer, 2019-01 – 2021-12, and Intern, 2014 - 2018',
    'amounts and metrics' => 'Cut p95 latency from 800 ms to 180 ms for Rp 15.000.000 a month, with +5 years of experience',
]);
