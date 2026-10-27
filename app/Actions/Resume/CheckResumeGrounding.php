<?php

namespace App\Actions\Resume;

class CheckResumeGrounding
{
    /**
     * List the paths of the bullets that the resume text does not contain word for word.
     *
     * Both the text and each bullet are normalized first, so curly quotes, dashes, bullet characters and line breaks do not
     * cause a false mismatch. A bullet is grounded when its normalized text is part of the normalized text.
     *
     * @param  array<mixed>  $experiences
     * @return list<string> The paths, such as experiences.0.bullets.2.
     */
    public function bullets(string $content, array $experiences): array
    {
        $normalizedContent = $this->normalizeText($content);
        $ungrounded = [];

        foreach ($experiences as $experienceIndex => $experience) {
            foreach ($experience['bullets'] as $bulletIndex => $bullet) {
                $normalizedBullet = $this->normalizeText($bullet);

                if ($normalizedBullet !== '' && ! str_contains($normalizedContent, $normalizedBullet)) {
                    $ungrounded[] = "experiences.{$experienceIndex}.bullets.{$bulletIndex}";
                }
            }
        }

        return $ungrounded;
    }

    /**
     * Normalize a text for the bullet check: plain quotes and dashes, no bullet characters at the start of a line,
     * one space for each run of whitespace, lowercase, and trimmed.
     */
    private function normalizeText(string $text): string
    {
        $text = strtr($text, ['‘' => "'", '’' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-']);
        $text = preg_replace('/^[ \t]*[•·▪●◦-][ \t]*/mu', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim(mb_strtolower($text));
    }
}
