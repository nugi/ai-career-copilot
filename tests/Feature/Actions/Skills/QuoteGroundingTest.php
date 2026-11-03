<?php

use App\Actions\Skills\QuoteGrounding;

test('a quote matches despite case whitespace and curly punctuation', function () {
    $content = "• The team’s \"remote\" policy—open to\n  candidates in Europe.";
    $quote = "THE TEAM'S “REMOTE” POLICY–OPEN TO candidates   in EUROPE.";

    expect(app(QuoteGrounding::class)->appearsIn($content, $quote))->toBeTrue();
});

test('a paraphrase does not match the source text', function () {
    expect(app(QuoteGrounding::class)->appearsIn(
        'Must be based in Canada.',
        'Applicants must live in Canada.',
    ))->toBeFalse();
});

test('an empty quote is rejected after normalization', function (string $quote) {
    expect(app(QuoteGrounding::class)->appearsIn('Must be based in Canada.', $quote))->toBeFalse();
})->with(['empty' => '', 'whitespace' => " \n\t ", 'bullet only' => '• ']);
