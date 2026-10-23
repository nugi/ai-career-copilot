<?php

namespace App\JobSources\Normalization;

use RuntimeException;
use Transliterator;

/**
 * Builds the parts of the key that links the same job across sources: the company and title in a normalized
 * spelling and a hash of both with the location key. See ADR 0018.
 */
final class DuplicateKey
{
    private static ?Transliterator $transliterator = null;

    /**
     * Normalize company spelling without a leading Indonesian PT or trailing legal forms.
     */
    public static function company(string $name): string
    {
        $cleaned = self::clean($name);
        $tokens = explode(' ', $cleaned);

        if ($tokens[0] === 'pt') {
            array_shift($tokens);
        }

        while ($tokens !== [] && in_array(end($tokens), [
            'inc', 'incorporated', 'llc', 'ltd', 'limited', 'gmbh', 'ag', 'pt', 'tbk',
            'bv', 'nv', 'sa', 'sas', 'srl', 'plc', 'corp', 'corporation', 'co', 'company',
            'pte', 'pty', 'oy', 'ab', 'kk',
        ], true)) {
            array_pop($tokens);
        }

        return $tokens === [] ? $cleaned : implode(' ', $tokens);
    }

    /**
     * Normalize role abbreviations and compound words without remote or gender markers.
     */
    public static function title(string $title): string
    {
        $title = preg_replace('~\(\s*[mfw]\s*/\s*[mfw]\s*/\s*[dx]\s*\)~iu', '', $title) ?? $title;
        $tokens = explode(' ', self::clean($title));
        $tokens = array_map(fn (string $token): string => match ($token) {
            'sr', 'snr' => 'senior',
            'jr' => 'junior',
            'mgr' => 'manager',
            'eng', 'engr' => 'engineer',
            'dev' => 'developer',
            'swe', 'sde' => 'software engineer',
            'remote', 'fully' => '',
            default => $token,
        }, $tokens);
        $title = implode(' ', $tokens);
        $title = preg_replace(
            ['/\bfront\s+end\b/', '/\bback\s+end\b/', '/\bfull\s+stack\b/'],
            ['frontend', 'backend', 'fullstack'],
            $title,
        ) ?? $title;

        return trim(preg_replace('/\s+/', ' ', $title) ?? $title);
    }

    /**
     * Identify the same normalized company, role and location across sources.
     */
    public static function hash(string $company, string $title, string $locationKey): string
    {
        return hash('sha256', "{$company}|{$title}|{$locationKey}");
    }

    /**
     * Transliterate names and replace punctuation with stable token boundaries.
     */
    private static function clean(string $name): string
    {
        self::$transliterator ??= Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $name = self::$transliterator?->transliterate($name);

        if (! is_string($name)) {
            throw new RuntimeException('Unable to transliterate a duplicate key.');
        }

        $name = str_replace('&', ' and ', $name);

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name);
    }
}
