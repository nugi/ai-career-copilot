<?php

namespace App\Ai\Redaction;

/**
 * Patterns for national ID, tax and insurance numbers that have a format which is rare in other text.
 *
 * A match needs no label. The NIK and NPWP patterns are also used with a label, through LabelledValuePattern.
 * Each pattern uses a digit or letter boundary, so a longer number is not a match.
 */
final class IdentifierPatterns
{
    /**
     * An old-format Indonesian NPWP tax number, such as 12.345.678.9-012.345. A date such as 12.03.2021 is not a match.
     */
    public const NPWP_PATTERN = '~(?<!\d)\d{2}\.\d{3}\.\d{3}\.\d[.-]\d{3}\.\d{3}(?!\d)~u';

    /**
     * A labelled NPWP: the word NPWP, optional spaces, an optional colon, and 15 or 16 digits with optional dots, dashes or spaces between them.
     * The label stays, and only the number is replaced. Examples: NPWP: 123456789012345, NPWP 12.345.678.9.012.345.
     * A number of 17 digits or more is not a match.
     */
    public const LABELLED_NPWP_PATTERN = '~(?<!\p{L})NPWP[ \t]*:?[ \t]*\K\d(?:[.\- ]?\d){14,15}(?!\d)~iu';

    /**
     * An Indonesian NIK: exactly 16 digits, or 16 digits in groups of 4 with spaces or dashes.
     * Examples: 3201234567890001, 3201 2345 6789 0001, 3201-2345-6789-0001.
     * A run of 17 or more digits is not a match.
     */
    public const NIK_PATTERN = '~(?<!\d)\d{16}(?!\d)|(?<!\d)\d{4}[ -]\d{4}[ -]\d{4}[ -]\d{4}(?!\d)~u';

    /**
     * A US Social Security Number with the format 123-45-6789. A longer digit run is not a match.
     * Examples: 123-45-6789. Not a match: 123-456-789, 1123-45-6789.
     */
    public const US_SSN_PATTERN = '~(?<!\d)\d{3}-\d{2}-\d{4}(?!\d)~u';

    /**
     * A UK National Insurance number: two letters, three pairs of digits, and a last letter from A to D.
     * The spaces between the groups are optional. The pattern is case-sensitive.
     * Examples: AB 12 34 56 C, QQ123456C. Not a match: ab 12 34 56 c, AB 12 34 56 E.
     */
    public const UK_NATIONAL_INSURANCE_PATTERN = '~\b[A-Z]{2} ?\d{2} ?\d{2} ?\d{2} ?[A-D]\b~u';

    /**
     * A Singapore NRIC or FIN: a prefix letter S, T, F, G or M, seven digits, and a check letter. The pattern is case-sensitive.
     * Examples: S1234567D, T0123456J. Not a match: s1234567d, S12345678D.
     */
    public const SINGAPORE_NRIC_PATTERN = '~\b[STFGM]\d{7}[A-Z]\b~u';

    /**
     * A Malaysian MyKad number: six digits, two digits, and four digits, with dashes. A longer digit run is not a match.
     * Examples: 900101-14-5678. Not a match: 900101145678, 9001011-14-5678.
     */
    public const MALAYSIA_MYKAD_PATTERN = '~(?<!\d)\d{6}-\d{2}-\d{4}(?!\d)~u';

    /**
     * A Brazilian CPF number: 123.456.789-09 with dots and a dash. A longer digit run is not a match.
     * Examples: 123.456.789-09. Not a match: 12345678909, 123.456.789-0.
     */
    public const BRAZIL_CPF_PATTERN = '~(?<!\d)\d{3}\.\d{3}\.\d{3}-\d{2}(?!\d)~u';

    /**
     * An Indian Aadhaar number: 12 digits in three groups of four, with single spaces.
     * It must not be part of a longer group of digits. The 16-digit NIK 1234 5678 9012 3456 is not a match of this pattern,
     * because the NIK pattern replaces it first.
     * Examples: 1234 5678 9012. Not a match: 1234 5678 9012 3, 1234  5678 9012.
     */
    public const INDIA_AADHAAR_PATTERN = '~(?<![\d])(?<!\d )\d{4} \d{4} \d{4}(?! ?\d)~u';
}
