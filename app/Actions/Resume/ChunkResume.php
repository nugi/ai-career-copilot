<?php

namespace App\Actions\Resume;

use App\Enums\ResumeSection;
use App\Models\Resume;
use App\Models\ResumeChunk;
use Illuminate\Database\Eloquent\Builder;

/**
 * Build self-contained resume sections and preserve current vectors when their text has not changed.
 */
class ChunkResume
{
    /** The most estimated tokens in one experience chunk, so a long role splits into groups of 2 or 3 bullets that each match one topic. */
    private const MAX_EXPERIENCE_TOKENS = 120;

    /**
     * Build the chunks of a parse in reading order: summary, experiences, skills, education.
     *
     * @param  array<array-key, mixed>  $parsedData
     * @return list<array{section: ResumeSection, content: string, period_start: string|null, period_end: string|null, token_count: int}>
     */
    public function chunks(array $parsedData): array
    {
        $chunks = [];
        $summary = $parsedData['summary'] ?? null;

        if (is_string($summary) && trim($summary) !== '') {
            $chunks[] = $this->chunk(ResumeSection::Summary, 'Summary: '.$summary);
        }

        foreach ($this->entries($parsedData['experiences'] ?? null) as $experience) {
            if (! is_string($experience['title'] ?? null) || ! is_string($experience['company'] ?? null) || ! is_string($experience['start'] ?? null)) {
                continue;
            }

            $start = $experience['start'];
            $end = is_string($experience['end'] ?? null) ? $experience['end'] : null;
            $prefix = "Experience as {$experience['title']} at {$experience['company']} ({$start}–".($end ?? 'present').'):';
            $content = $prefix;
            $hasBullets = false;
            $bullets = is_array($experience['bullets'] ?? null) ? array_filter($experience['bullets'], is_string(...)) : [];

            foreach ($bullets as $bullet) {
                $next = $content."\n- ".$bullet;

                if ($hasBullets && $this->tokenCount($next) > self::MAX_EXPERIENCE_TOKENS) {
                    $chunks[] = $this->chunk(ResumeSection::Experience, $content, $start, $end);
                    $content = $prefix;
                }

                $content .= "\n- ".$bullet;
                $hasBullets = true;
            }

            $chunks[] = $this->chunk(ResumeSection::Experience, $content, $start, $end);
        }

        $skills = is_array($parsedData['skills'] ?? null) ? array_filter($parsedData['skills'], is_string(...)) : [];

        if ($skills !== []) {
            $chunks[] = $this->chunk(ResumeSection::Skills, 'Skills: '.implode(', ', $skills));
        }

        $lines = [];
        $starts = [];
        $ends = [];

        foreach ($this->entries($parsedData['education'] ?? null) as $education) {
            if (! is_string($education['institution'] ?? null)) {
                continue;
            }

            $degree = is_string($education['degree'] ?? null) ? $education['degree'] : null;
            $field = is_string($education['field'] ?? null) ? $education['field'] : null;
            $qualification = $degree === null ? $field : $degree.($field === null ? '' : ' in '.$field);
            $line = ($qualification === null ? '' : $qualification.', ').$education['institution'];
            $start = is_string($education['start'] ?? null) ? $education['start'] : null;
            $end = is_string($education['end'] ?? null) ? $education['end'] : null;

            if ($start !== null) {
                $starts[] = $start;
            }

            if ($end !== null) {
                $ends[] = $end;
            }

            $dates = array_filter([$start, $end], fn (?string $date): bool => $date !== null);
            $lines[] = '- '.$line.($dates === [] ? '' : ' ('.implode('–', $dates).')');
        }

        if ($lines !== []) {
            sort($starts, SORT_STRING);
            rsort($ends, SORT_STRING);
            $chunks[] = $this->chunk(ResumeSection::Education, "Education:\n".implode("\n", $lines), $starts[0] ?? null, $ends[0] ?? null);
        }

        return $chunks;
    }

    /**
     * Rebuild under the caller's transaction, sharing vectors only within the resume owner's records.
     */
    public function sync(Resume $resume): void
    {
        $chunks = $this->chunks($resume->parsed_data ?? []);
        $old = $resume->chunks()->get();
        $comparable = $old->map(fn (ResumeChunk $chunk): array => [
            'section' => $chunk->section,
            'content' => $chunk->content,
            'period_start' => $chunk->period_start,
            'period_end' => $chunk->period_end,
        ])->all();
        $new = array_map(fn (array $chunk): array => array_diff_key($chunk, ['token_count' => true]), $chunks);

        if ($comparable === $new) {
            return;
        }

        $vectors = ResumeChunk::query()
            ->whereHas('resume', fn (Builder $query): Builder => $query->where('user_id', $resume->user_id))
            ->whereNotNull('embedding')
            ->where('embedding_model', config('ai-routing.embeddings.model'))
            ->orderBy('id')->get()->keyBy('content');

        $resume->chunks()->delete();

        foreach ($chunks as $position => $chunk) {
            $source = $vectors->get($chunk['content']);
            $resume->chunks()->create([
                ...$chunk,
                'position' => $position,
                'embedding' => $source?->embedding,
                'embedding_model' => $source?->embedding_model,
                'embedded_at' => $source?->embedded_at,
            ]);
        }
    }

    /**
     * Ignore malformed section lists and entries without coercing their values into text.
     *
     * @return list<array<array-key, mixed>>
     */
    private function entries(mixed $entries): array
    {
        return is_array($entries) ? array_values(array_filter($entries, is_array(...))) : [];
    }

    /**
     * @return array{section: ResumeSection, content: string, period_start: string|null, period_end: string|null, token_count: int}
     */
    private function chunk(ResumeSection $section, string $content, ?string $start = null, ?string $end = null): array
    {
        return [
            'section' => $section,
            'content' => $content,
            'period_start' => $start,
            'period_end' => $end,
            'token_count' => $this->tokenCount($content),
        ];
    }

    /**
     * Estimate tokens at 4 characters each, because the provider reports usage for a whole request only.
     */
    private function tokenCount(string $content): int
    {
        return (int) ceil(mb_strlen($content) / 4);
    }
}
