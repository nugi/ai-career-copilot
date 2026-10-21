<?php

namespace App\Ai;

use App\Ai\Redaction\FieldLabels;
use App\Ai\Redaction\IdentifierPatterns;
use App\Ai\Redaction\LabelledValuePattern;
use RuntimeException;

/**
 * Removes personal contact data from resume text before the text is sent to an AI provider.
 *
 * Each match is replaced by one placeholder: [EMAIL], [URL], [NPWP], [NIK], [ID], [PHONE], [ADDRESS], [PERSONAL] or [NAME].
 * The replacements run in this order: email, URL, NPWP, labelled identifiers, NIK, other identifier formats, phones, Aadhaar, address, personal fields, and name.
 * The NPWP runs before the NIK, because a labelled 16-digit NPWP must not become [NIK].
 * The labelled identifiers run before the NIK, so NIK: 3273012345670001 becomes NIK: [ID].
 * A NIK runs before the phone patterns, because a 16-digit NIK can start with 62 and match the Indonesian phone pattern.
 * The name runs last, because an email or a link that contains the name is already replaced.
 * A placeholder that an earlier step wrote stays the same, so Phone: [PHONE] does not change.
 * All other text, including the line breaks, stays the same.
 *
 * The label lists are in FieldLabels, so a new language needs only new labels.
 * The labelled patterns are built by LabelledValuePattern, and the identifier formats are in IdentifierPatterns.
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
     * A North American number without a country code: a 3-digit area code in parentheses or with a dash or dot, then 3 digits and 4 digits.
     * Examples: (415) 555-0132, (415) 555 0132, 415-555-0132, 415.555.0132.
     * Not a match: 2021.06.15, because the groups have the wrong length.
     */
    private const NORTH_AMERICA_PHONE_PATTERN = '~(?<!\d)(?:\(\d{3}\)[ ]?|\d{3}[-.])\d{3}[-. ]\d{4}(?!\d)~u';

    /**
     * A street address. A match starts at a street keyword, or at the RT of an RT and RW pair, and ends at the first of these:
     * a 5-digit postal code (the postal code is part of the match), a separator (not part of the match), or the end of the text.
     *
     * The separators are |, •, ·, a tab, two or more spaces in a row, and a line break. Spaces before a separator are not part of the match.
     * A match runs for at most 120 characters after the keyword. A longer address is cut after 120 characters, and the rest of the text stays.
     * The limit counts characters, not bytes, because the pattern uses the u modifier.
     *
     * The abbreviations Jl., Jln. and Gg. match at any case and with any text after them.
     * The full words Jalan, Gang, Kompleks, Komplek, Perumahan and Perum match only when the next word starts with an uppercase letter or a digit.
     * A phrase such as berjalan kaki, jalan kaki or Gang of Four is not a match. An optional dot and optional spaces may follow the keyword.
     *
     * A line with an RT and an RW number starts a match at RT, for example RT 03/RW 05, RT.003 RW.005 or RT 3 / RW 5. The text before RT stays.
     * A city name alone is not a match.
     */
    private const ADDRESS_PATTERN = '~(?:(?<![A-Za-z])(?:(?:Perumahan|Perum|Kompleks|Komplek|Jalan|Gang)\b(?=\.?[ \t]*(?-i:[A-Z0-9]))|Jln\b|Jl\b|Gg\b)\.?|\bRT\.?[ \t]*\d{1,3}[ \t]*/?[ \t]*RW\.?[ \t]*\d{1,3})(?:(?!(?=  |[ \t]*(?:\||•|·|\t|\r|\n|\z))|(?<!\d)\d{5}(?!\d))[^\r\n]){0,120}(?:(?<!\d)\d{5}(?!\d))?~iu';

    /**
     * Replace the personal contact data in the text with placeholders.
     *
     * The name is the user's full name. It is replaced as a whole, then each part of 3 or more characters outside a company name is replaced.
     * A null name, or a name with no letters, leaves the name unchanged.
     */
    public function redact(string $text, ?string $name = null): string
    {
        $text = $this->replace(self::EMAIL_PATTERN, '[EMAIL]', $text);
        $text = $this->replace(self::URL_PATTERN, '[URL]', $text);

        $text = $this->replace(IdentifierPatterns::NPWP_PATTERN, '[NPWP]', $text);
        $text = $this->replace(IdentifierPatterns::LABELLED_NPWP_PATTERN, '[NPWP]', $text);
        $text = $this->replace(LabelledValuePattern::for(FieldLabels::IDENTIFIER, 40, minimumDigitRun: 4), '[ID]', $text);
        $text = $this->replace(IdentifierPatterns::NIK_PATTERN, '[NIK]', $text);

        $identifierPatterns = [
            IdentifierPatterns::US_SSN_PATTERN,
            IdentifierPatterns::UK_NATIONAL_INSURANCE_PATTERN,
            IdentifierPatterns::SINGAPORE_NRIC_PATTERN,
            IdentifierPatterns::MALAYSIA_MYKAD_PATTERN,
            IdentifierPatterns::BRAZIL_CPF_PATTERN,
        ];

        foreach ($identifierPatterns as $pattern) {
            $text = $this->replace($pattern, '[ID]', $text);
        }

        $text = $this->replace(LabelledValuePattern::for(FieldLabels::PHONE, 40, minimumDigitRun: 7), '[PHONE]', $text);

        $phonePatterns = [
            self::MOBILE_PHONE_PATTERN,
            self::LANDLINE_PHONE_PATTERN,
            self::INTERNATIONAL_PHONE_PATTERN,
            self::NORTH_AMERICA_PHONE_PATTERN,
        ];

        foreach ($phonePatterns as $pattern) {
            $text = $this->replace($pattern, '[PHONE]', $text);
        }

        // The Aadhaar format also matches the Indonesian mobile number 0812 3456 7890, so it runs after the phone patterns.
        $text = $this->replace(IdentifierPatterns::INDIA_AADHAAR_PATTERN, '[ID]', $text);

        $text = $this->replace(LabelledValuePattern::for(FieldLabels::ADDRESS, 160), '[ADDRESS]', $text);
        $text = $this->replace(self::ADDRESS_PATTERN, '[ADDRESS]', $text);
        $text = $this->replace(LabelledValuePattern::for(FieldLabels::PERSONAL, 80), '[PERSONAL]', $text);

        return $this->redactName($text, $name);
    }

    /**
     * Replace the full name, then each part of the name that has 3 or more characters.
     *
     * The parts are split on whitespace. The full name matches its parts in order, with one or more whitespace characters between them,
     * so a name split over two lines is also replaced. A part of 2 characters, such as Li, stays, because it matches too much text.
     * A match is a whole word in any case, and a bracket next to it does not count as a word character, so [PHONE] is not changed by the name Phone.
     * A single part inside a company name stays unchanged, but the full name is still replaced.
     */
    private function redactName(string $text, ?string $name): string
    {
        if ($name === null || preg_match('/\p{L}/u', $name) !== 1) {
            return $text;
        }

        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $quotedParts = array_map(fn (string $part): string => preg_quote($part, '~'), $parts);

        if (count($quotedParts) > 1) {
            $text = $this->replace($this->wholeWord(implode('\s+', $quotedParts)), '[NAME]', $text);
        }

        foreach ($parts as $part) {
            if (mb_strlen($part) < 3) {
                continue;
            }

            $text = $this->replaceOutsideCompanyNames($this->wholeWord(preg_quote($part, '~')), $text);
        }

        return $text;
    }

    /**
     * Replace every match of the pattern with [NAME], except inside a company name, such as PT Sari Roti.
     *
     * A company name is a prefix from FieldLabels::COMPANY_PREFIXES in its exact case, an optional dot, and 1 to 4 words on the same line
     * that start with an uppercase letter. The text is split around the company names, and only the pieces between them are changed.
     * A company name ends before a character that is not a letter or a digit, so a piece never starts in the middle of a word.
     */
    private function replaceOutsideCompanyNames(string $pattern, string $text): string
    {
        $prefixes = array_map(fn (string $prefix): string => preg_quote($prefix, '~'), FieldLabels::COMPANY_PREFIXES);
        $companyPattern = '~((?<![\p{L}\p{N}])(?:'.implode('|', $prefixes).')(?![\p{L}\p{N}])\.?(?:[ \t]+\p{Lu}[\p{L}\p{N}&\x{27}-]*){1,4})~u';
        $pieces = preg_split($companyPattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($pieces === false) {
            throw new RuntimeException('PII redaction failed: '.preg_last_error_msg());
        }

        foreach ($pieces as $index => $piece) {
            if ($index % 2 === 0) {
                $pieces[$index] = $this->replace($pattern, '[NAME]', $piece);
            }
        }

        return implode('', $pieces);
    }

    /**
     * Wrap a pattern so that it matches only a whole word. A letter, a digit, or an opening or closing bracket next to the match stops it.
     */
    private function wholeWord(string $pattern): string
    {
        return '~(?<![\p{L}\p{N}\[])'.$pattern.'(?![\p{L}\p{N}\]])~iu';
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
