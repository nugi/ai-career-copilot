<?php

namespace App\Ai;

use RuntimeException;

/**
 * Removes personal contact data from resume text before the text is sent to an AI provider.
 *
 * Each match is replaced by one placeholder: [EMAIL], [URL], [NIK], [PHONE] or [ADDRESS].
 * The replacements run in this order. A NIK runs before the phone patterns, because a 16-digit NIK can start with 62 and match the Indonesian phone pattern.
 * All other text, including the line breaks, stays the same.
 *
 * The input must be valid UTF-8. The patterns use the u modifier, so invalid input makes redact() throw a RuntimeException. The caller cleans the text with mb_scrub first.
 */
class PiiRedactor
{
    /**
     * An email address, such as budi.santoso+jobs@mail.co.id.
     */
    private const EMAIL_PATTERN = '~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~u';

    /**
     * A link that starts with http:// or https://, or a bare profile link that starts with www., linkedin.com/, github.com/ or gitlab.com/.
     * A bare domain with no path, such as Laravel.com, is not a match.
     */
    private const URL_PATTERN = '~\bhttps?://\S*[^\s.,;:!?)\]]|\b(?:www\.|linkedin\.com/|github\.com/|gitlab\.com/)\S*[^\s.,;:!?)\]]~iu';

    /**
     * An Indonesian NIK: exactly 16 digits, or 16 digits in groups of 4 with spaces or dashes.
     * A run of 17 or more digits is not a match.
     */
    private const NIK_PATTERN = '~(?<!\d)\d{16}(?!\d)|(?<!\d)\d{4}[ -]\d{4}[ -]\d{4}[ -]\d{4}(?!\d)~u';

    /**
     * An Indonesian mobile number with the prefix (+62), +62, 62 or 0, followed by the digit 8.
     * Examples: (+62) 812-3456-7890, +62 812-3456-7890, 0812 3456 7890, 081234567890.
     */
    private const MOBILE_PHONE_PATTERN = '~(?<!\d)(?:\(\+62\)|\+62|62|0)[ -]?8\d{2}(?:[ -]?\d{3,4}){2}(?!\d)~u';

    /**
     * An Indonesian landline number with an area code in parentheses, with a leading 0, or with the prefix +62.
     * Examples: (021) 555-1234, 021-5551234, +62 21 5551234, (0274) 512345.
     * The 0 alternative comes first, because PCRE2 10.47 with JIT does not match 021-5551234 when the parenthesised alternative comes first.
     */
    private const LANDLINE_PHONE_PATTERN = '~(?<!\d)(?:0\d{2,3}|\(0\d{2,3}\)|\+62[ -]?\d{2,3})[ -]?\d{3,4}[ -]?\d{3,4}(?!\d)~u';

    /**
     * An international number: a + and a country code, then 7 to 12 more digits with optional spaces, dashes, dots or parentheses.
     * The lookahead requires at least 9 digits in total, so a short value such as +5 years is not a match.
     * Examples: +65 9123 4567, +1 (415) 555-0132.
     */
    private const INTERNATIONAL_PHONE_PATTERN = '~\+(?=(?:[ .()-]*\d){9})\d{1,3}(?:[ .()-]*\d){7,12}(?!\d)~u';

    /**
     * A street address. A match starts at a street keyword, or at the RT of an RT and RW pair, and ends at the first of these:
     * a 5-digit postal code (the postal code is part of the match), a separator (not part of the match), or the end of the text.
     *
     * The separators are |, •, ·, a tab, two or more spaces in a row, and a line break. Spaces before a separator are not part of the match.
     * A match runs for at most 120 characters after the keyword. A longer address is cut after 120 characters, and the rest of the text stays.
     * The limit counts characters, not bytes, because the pattern uses the u modifier.
     *
     * The abbreviations Jl., Jln. and Gg. match at any case and with any text after them.
     * The full words Jalan, Gang, Komplek, Perumahan and Perum match only when the next word starts with an uppercase letter or a digit.
     * A phrase such as berjalan kaki, jalan kaki or Gang of Four is not a match. An optional dot and optional spaces may follow the keyword.
     *
     * A line with an RT and an RW number starts a match at RT, for example RT 03/RW 05, RT.003 RW.005 or RT 3 / RW 5. The text before RT stays.
     * A city name alone is not a match.
     */
    private const ADDRESS_PATTERN = '~(?:(?<![A-Za-z])(?:(?:Perumahan|Perum|Komplek|Jalan|Gang)\b(?=\.?[ \t]*(?-i:[A-Z0-9]))|Jln\b|Jl\b|Gg\b)\.?|\bRT\.?[ \t]*\d{1,3}[ \t]*/?[ \t]*RW\.?[ \t]*\d{1,3})(?:(?!(?=  |[ \t]*(?:\||•|·|\t|\r|\n|\z))|(?<!\d)\d{5}(?!\d))[^\r\n]){0,120}(?:(?<!\d)\d{5}(?!\d))?~iu';

    /**
     * Replace the personal contact data in the text with placeholders.
     */
    public function redact(string $text): string
    {
        $text = $this->replace(self::EMAIL_PATTERN, '[EMAIL]', $text);
        $text = $this->replace(self::URL_PATTERN, '[URL]', $text);
        $text = $this->replace(self::NIK_PATTERN, '[NIK]', $text);

        foreach ([self::MOBILE_PHONE_PATTERN, self::LANDLINE_PHONE_PATTERN, self::INTERNATIONAL_PHONE_PATTERN] as $pattern) {
            $text = $this->replace($pattern, '[PHONE]', $text);
        }

        return $this->replace(self::ADDRESS_PATTERN, '[ADDRESS]', $text);
    }

    /**
     * Replace every match of the pattern.
     *
     * A failed match run throws an exception, so the caller never sends text that it could not redact.
     */
    private function replace(string $pattern, string $placeholder, string $text): string
    {
        $result = preg_replace($pattern, $placeholder, $text);

        if ($result === null) {
            throw new RuntimeException('PII redaction failed: '.preg_last_error_msg());
        }

        return $result;
    }
}
