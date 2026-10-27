<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Keep each unknown name's original group without changing the other extraction fields or timestamps.
     */
    public function up(): void
    {
        DB::table('job_postings')
            ->whereRaw("exists (select 1 from jsonb_array_elements(extracted_skills->'unknown') as entry where jsonb_typeof(entry) = 'string')")
            ->chunkById(100, function (Collection $postings): void {
                foreach ($postings as $posting) {
                    $extractedSkills = json_decode($posting->extracted_skills, true, 512, JSON_THROW_ON_ERROR);
                    $required = array_map(
                        fn (string $name): string => Str::of($name)->squish()->lower()->toString(),
                        $extractedSkills['required'] ?? [],
                    );
                    $unknown = [];

                    foreach ($extractedSkills['unknown'] as $entry) {
                        if (is_string($entry)) {
                            $normalized = Str::of($entry)->squish()->lower()->toString();
                            $entry = ['name' => $entry, 'normalized' => $normalized, 'required' => in_array($normalized, $required, true)];
                        }

                        $unknown[$entry['normalized']] ??= $entry;
                    }

                    ksort($unknown, SORT_STRING);
                    $extractedSkills['unknown'] = array_values($unknown);

                    DB::table('job_postings')->where('id', $posting->id)->update([
                        'extracted_skills' => json_encode($extractedSkills, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    /**
     * Restore the list of name strings; the stored group is no longer available in that format.
     */
    public function down(): void
    {
        DB::table('job_postings')->whereJsonLength('extracted_skills->unknown', '>', 0)
            ->chunkById(100, function (Collection $postings): void {
                foreach ($postings as $posting) {
                    $extractedSkills = json_decode($posting->extracted_skills, true, 512, JSON_THROW_ON_ERROR);
                    $extractedSkills['unknown'] = array_map(
                        fn (array|string $entry): string => is_string($entry) ? $entry : $entry['name'],
                        $extractedSkills['unknown'],
                    );

                    DB::table('job_postings')->where('id', $posting->id)->update([
                        'extracted_skills' => json_encode($extractedSkills, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }
};
