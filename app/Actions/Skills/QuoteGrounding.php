<?php

namespace App\Actions\Skills;

final class QuoteGrounding
{
    /**
     * Determine whether a nonempty quote appears in the source after normalization.
     */
    public function appearsIn(string $content, string $quote): bool
    {
        $normalizedQuote = $this->normalizeText($quote);

        return $normalizedQuote !== '' && str_contains($this->normalizeText($content), $normalizedQuote);
    }

    /**
     * Normalize a text for the bullet check: plain quotes and dashes, no bullet characters at the start of a line,
     * one space for each run of whitespace, lowercase, and trimmed.
     */
    public function normalizeText(string $text): string
    {
        $text = strtr($text, ['‘' => "'", '’' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-']);
        $text = preg_replace('/^[ \t]*[•·▪●◦-][ \t]*/mu', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(mb_strtolower($text));
    }
}
