<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use stdClass;

#[Signature('skills:unknown {--limit=50 : The most names to show}')]
#[Description('Show the extracted skill names that no catalogue skill matches, by the number of postings.')]
class UnknownSkillsCommand extends Command
{
    /**
     * Print the unknown skill names, grouped by the normalized name, with the number of postings that require each and
     * that list each as a plus.
     *
     * skills:rematch removes a name from the saved extractions when a catalogue change makes it resolve.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('The --limit option must be 1 or more.');

            return self::FAILURE;
        }

        /** @var list<stdClass> $rows */
        $rows = DB::select(<<<SQL
            select min(entry->>'name') as name,
                count(distinct posting.id) filter (where (entry->>'required')::boolean) as required,
                count(distinct posting.id) filter (where not (entry->>'required')::boolean) as nice_to_have,
                count(distinct posting.id) as postings
            from job_postings as posting
            cross join jsonb_array_elements(posting.extracted_skills->'unknown') as entry
            group by entry->>'normalized'
            order by postings desc, entry->>'normalized'
            limit {$limit}
            SQL);

        if ($rows === []) {
            $this->info('No unknown skills.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Required', 'Nice to have', 'Postings'],
            array_map(fn (stdClass $row): array => [$row->name, (int) $row->required, (int) $row->nice_to_have, (int) $row->postings], $rows),
        );

        return self::SUCCESS;
    }
}
