<?php

namespace App\Ai\Redaction;

use InvalidArgumentException;

/**
 * Builds the pattern that matches the value after a label in resume text.
 *
 * The label and the colon stay in the text, because the pattern uses \K before the value.
 * A word label, such as Geburtsdatum, needs a colon and must not be part of a longer word.
 * A CJK label, such as 生年月日, also matches with a full-width colon or with a space, because CJK text often has no colon.
 * The value ends before a separator: |, •, ·, a tab, two spaces in a row, or a line break.
 */
final class LabelledValuePattern
{
    /**
     * The built patterns, by the key made from the arguments of for().
     *
     * @var array<string, string>
     */
    private static array $patterns = [];

    /**
     * Return the pattern for the labels. Each pattern is built once and then reused.
     *
     * Examples with the labels ['Geburtsdatum'] and a maximum of 80 characters:
     * Geburtsdatum: 01.02.1990 matches 01.02.1990, and Geburtsdatum : 01.02.1990 matches 01.02.1990.
     * Wohnortwechsel: ja does not match, because the label is part of a longer word.
     *
     * With a minimum digit run, the value must contain that many digits in a row before the next separator.
     * Spaces, dots, dashes and parentheses may come between the digits, as in a phone number.
     * With a run of 7, Phone: on request and Mobile: iOS 17, Android 14 do not match, and Phone: 555 0132 1 matches.
     *
     * @param  list<string>  $labels  The labels of one category, in any case.
     * @param  int  $maxLength  The most characters that one value can have.
     * @param  int  $minimumDigitRun  The fewest digits in a row that a value needs, or 0 when the value needs no digits.
     */
    public static function for(array $labels, int $maxLength, int $minimumDigitRun = 0): string
    {
        $key = serialize([$labels, $maxLength, $minimumDigitRun]);

        if (! isset(self::$patterns[$key])) {
            self::$patterns[$key] = self::build($labels, $maxLength, $minimumDigitRun);
        }

        return self::$patterns[$key];
    }

    /**
     * Build the pattern. The longest labels come first, so Status Perkawinan is tried before Status.
     *
     * @param  list<string>  $labels
     */
    private static function build(array $labels, int $maxLength, int $minimumDigitRun): string
    {
        usort($labels, fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        $wordLabels = [];
        $cjkLabels = [];

        foreach ($labels as $label) {
            $escapedLabel = str_replace(' ', '[ \t]+', preg_quote($label, '~'));

            if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $label) === 1) {
                $cjkLabels[] = $escapedLabel;
            } else {
                $wordLabels[] = $escapedLabel;
            }
        }

        $alternatives = [];

        if ($wordLabels !== []) {
            $alternatives[] = '(?<!\p{L})(?:'.implode('|', $wordLabels).')(?!\p{L})[ \t]*\R?[ \t]*[:：]';
        }

        if ($cjkLabels !== []) {
            $alternatives[] = '(?:'.implode('|', $cjkLabels).')(?:[:：]|[ \t]+)';
        }

        if ($alternatives === []) {
            throw new InvalidArgumentException('A labelled value pattern needs at least one label.');
        }

        $value = '(?:(?!  |[ \t]*(?:\||•|·|\t|\r|\n|\z))[^\r\n]){1,'.$maxLength.'}';
        $digitCheck = $minimumDigitRun > 0
            ? '(?=[^\r\n|•·]*?(?<!\d)\d(?:[ .()\-]*\d){'.($minimumDigitRun - 1).'})'
            : '';

        return '~(?:'.implode('|', $alternatives).')[ \t]*\R?[ \t]*\K'.$digitCheck.$value.'~iu';
    }
}
