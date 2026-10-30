<?php

namespace App\Actions\Jobs;

use App\Models\JobPosting;

/**
 * Build the text of a posting's vector: the title and the skill names (ADR 0026).
 * The embedding benchmark uses the same text, so the two cannot drift.
 */
final class PostingEmbeddingText
{
    /**
     * Sort skill names so relationship order cannot change the vector input. The caller must eager load skills.
     */
    public function text(JobPosting $posting): string
    {
        $skills = $posting->skills->pluck('name')->sort()->values()->all();

        return $posting->title.($skills === [] ? '' : "\nSkills: ".implode(', ', $skills));
    }

    /**
     * Identify identical vector inputs so postings can share a saved vector.
     */
    public function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
