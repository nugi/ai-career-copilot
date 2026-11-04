<?php

use App\Actions\Jobs\RestrictionWording;

test('a quote restricts only when it uses a restricting word', function (string $quote, bool $restricts) {
    expect((new RestrictionWording)->restricts($quote))->toBe($restricts);
})->with([
    'only' => ['Remote USA Only', true],
    'must be based in' => ['Must be based in Canada.', true],
    'authorized' => ['Applicants must be authorized to work in the US', true],
    'authorised' => ['You are authorised to work in the UK', true],
    'limited to' => ['This role is limited to EU residents', true],
    'open to candidates' => ['Open to candidates in Canada or Europe', true],
    'eligible' => ['Eligible locations: Brazil, Mexico', true],
    'a team description' => ['Join our close-knit LATAM remote team', false],
    'where the company sits' => ['We are based in Berlin', false],
    'a preference' => ['EMEA preferred', false],
    'part of a word' => ['A commonly used stack', false],
]);
