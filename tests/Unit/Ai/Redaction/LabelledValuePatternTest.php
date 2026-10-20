<?php

use App\Ai\Redaction\LabelledValuePattern;

test('replaces the value after a label and keeps the label', function (array $labels, string $input, string $expected) {
    expect(preg_replace(LabelledValuePattern::for($labels, 80), '[VALUE]', $input))->toBe($expected);
})->with([
    'the longest label matches first' => [['Status', 'Status Perkawinan'], 'Status Perkawinan: Menikah', 'Status Perkawinan: [VALUE]'],
    'the shorter label matches when the longer label is absent' => [['Status', 'Status Perkawinan'], 'Status: Menikah', 'Status: [VALUE]'],
    'a word label with a space before the colon' => [['Geburtsdatum'], 'Geburtsdatum : 01.02.1990', 'Geburtsdatum : [VALUE]'],
    'a word label with a line break before the colon' => [['Agama'], "Agama\n: Islam", "Agama\n: [VALUE]"],
    'a word label with a line break after the colon' => [['Agama'], "Agama:\nIslam", "Agama:\n[VALUE]"],
    'spaces before a separator are not part of the value' => [['Agama'], 'Agama: Islam  Backend', 'Agama: [VALUE]  Backend'],
]);

test('a label needs a colon unless it is a CJK label', function (array $labels, string $input, string $expected) {
    expect(preg_replace(LabelledValuePattern::for($labels, 80), '[VALUE]', $input))->toBe($expected);
})->with([
    'word label without a colon stays' => [['Age'], 'Age 34', 'Age 34'],
    'CJK label with a colon' => [['生年月日'], '生年月日: 1990年1月1日', '生年月日: [VALUE]'],
    'CJK label with a full-width colon' => [['生年月日'], '生年月日：1990年1月1日', '生年月日：[VALUE]'],
    'CJK label with a space' => [['生年月日'], '生年月日 1990年1月1日', '生年月日 [VALUE]'],
]);

test('a word label inside a longer word is not a match', function (string $label, string $input) {
    expect(preg_replace(LabelledValuePattern::for([$label], 80), '[VALUE]', $input))->toBe($input);
})->with([
    'age inside ages' => ['Age', 'Ages: 5'],
    'wohnort inside wohnortwechsel' => ['Wohnort', 'Wohnortwechsel: ja'],
]);

test('a value stops before a pipe or a line break', function (string $input, string $expected) {
    expect(preg_replace(LabelledValuePattern::for(['Agama'], 80), '[VALUE]', $input))->toBe($expected);
})->with([
    'pipe' => ['Agama: Islam | Status', 'Agama: [VALUE] | Status'],
    'line break' => ["Agama: Islam\nBackend", "Agama: [VALUE]\nBackend"],
]);

test('a value that is longer than the limit keeps the rest of the text', function () {
    expect(preg_replace(LabelledValuePattern::for(['Agama'], 5), '[VALUE]', 'Agama: Islam Raya'))
        ->toBe('Agama: [VALUE] Raya');
});

test('a value without a long enough run of digits is not a match when a digit run is required', function (string $text) {
    expect(preg_replace(LabelledValuePattern::for(['Phone', 'Mobile', 'Cell'], 40, minimumDigitRun: 7), '[VALUE]', $text))
        ->toBe($text);
})->with([
    'no digits' => ['Phone: on request'],
    'versions in a skills line' => ['Mobile: iOS 17, Android 14, React Native 0.74'],
    'a count in a sentence' => ['Cell: 5 engineers across 3 time zones'],
]);

test('a value with a run of digits and phone punctuation is a match when a digit run is required', function (string $text) {
    expect(preg_replace(LabelledValuePattern::for(['Phone'], 40, minimumDigitRun: 7), '[VALUE]', $text))
        ->toBe('Phone: [VALUE]');
})->with([
    'spaces' => ['Phone: 555 0132'],
    'parentheses and dashes' => ['Phone: (030) 123-4567'],
    'dots' => ['Phone: 01.23.45.67.89'],
]);

test('a list with no label is rejected', function () {
    expect(fn () => LabelledValuePattern::for([], 80))->toThrow(InvalidArgumentException::class);
});
