<?php

namespace App\Actions\Resume;

use App\Actions\Skills\QuoteGrounding;

class CheckResumeGrounding
{
    public function __construct(private QuoteGrounding $quoteGrounding) {}

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
        $ungrounded = [];

        foreach ($experiences as $experienceIndex => $experience) {
            foreach ($experience['bullets'] as $bulletIndex => $bullet) {
                if ($this->quoteGrounding->normalizeText($bullet) !== '' && ! $this->quoteGrounding->appearsIn($content, $bullet)) {
                    $ungrounded[] = "experiences.{$experienceIndex}.bullets.{$bulletIndex}";
                }
            }
        }

        return $ungrounded;
    }
}
