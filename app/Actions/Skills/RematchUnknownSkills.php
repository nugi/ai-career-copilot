<?php

namespace App\Actions\Skills;

use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\SkillAlias;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolve stored unknown names against the verified catalogue without extracting the posting text again.
 */
class RematchUnknownSkills
{
    /**
     * Attach newly resolved names and return the number of postings that changed.
     */
    public function handle(): int
    {
        $skillsByName = Skill::query()->where('is_verified', true)->pluck('id', 'normalized_name')->all();
        $aliases = SkillAlias::query()->whereHas('skill', fn (Builder $query): Builder => $query->where('is_verified', true))
            ->pluck('skill_id', 'alias')->all();
        $skillsByName += $aliases;
        $changed = 0;

        JobPosting::query()->whereJsonLength('extracted_skills->unknown', '>', 0)
            ->chunkById(100, function (Collection $postings) use ($skillsByName, &$changed): void {
                foreach ($postings as $posting) {
                    if (! array_any($posting->extracted_skills['unknown'], fn (array $entry): bool => isset($skillsByName[$entry['normalized']]))) {
                        continue;
                    }

                    $names = DB::transaction(function () use ($posting, $skillsByName): array {
                        $locked = JobPosting::query()->whereKey($posting->getKey())->lockForUpdate()->first();

                        if ($locked === null) {
                            return [];
                        }

                        $extractedSkills = $locked->extracted_skills;
                        $unknown = $extractedSkills['unknown'] ?? [];
                        $matches = array_filter($unknown, fn (array $entry): bool => isset($skillsByName[$entry['normalized']]));

                        if ($matches === []) {
                            return [];
                        }

                        $existing = $locked->skills()->pluck('job_posting_skill.is_required', 'skills.id')->all();
                        $pivot = [];

                        foreach ($matches as $entry) {
                            $skillId = $skillsByName[$entry['normalized']];
                            $pivot[$skillId] = ['is_required' => $entry['required']
                                || ($pivot[$skillId]['is_required'] ?? false)
                                || ($existing[$skillId] ?? false)];
                        }

                        $locked->skills()->syncWithoutDetaching($pivot);
                        $extractedSkills['unknown'] = array_values(array_filter(
                            $unknown,
                            fn (array $entry): bool => ! isset($skillsByName[$entry['normalized']]),
                        ));
                        $locked->update(['extracted_skills' => $extractedSkills]);

                        return array_column($matches, 'name');
                    });

                    if ($names !== []) {
                        $changed++;
                        Log::info('skills.rematched', ['job_posting_id' => $posting->getKey(), 'names' => $names]);
                    }
                }
            });

        return $changed;
    }
}
