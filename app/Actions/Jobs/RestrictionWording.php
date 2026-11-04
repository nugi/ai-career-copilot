<?php

namespace App\Actions\Jobs;

/**
 * Check that an eligibility quote uses a word that restricts who can apply (ADR 0029, point 7).
 *
 * A grounded quote proves only that the words are in the posting. A sentence that describes the team ("Join our
 * close-knit LATAM remote team") is grounded and restricts nothing, so a reading without one of these words is dropped
 * and the posting stays unclear. The list is English; a quote in another language is dropped, which leaves the job visible.
 */
final class RestrictionWording
{
    /**
     * Restricting words and phrases, matched as whole words after lowercasing. "Based in" alone is not here: it also
     * describes where the company sits. "Must be based in" passes through "must".
     */
    public const string PATTERN = '/\b(?:only|must|require[sd]?|authori[sz]ed|eligible|eligibility|limited to|restricted to'
        .'|open to (?:candidates|applicants|residents|people|those)|residents?|residing|citizens?|citizenship)\b/iu';

    /**
     * Determine whether the quote contains a restricting word.
     */
    public function restricts(string $quote): bool
    {
        return preg_match(self::PATTERN, $quote) === 1;
    }
}
