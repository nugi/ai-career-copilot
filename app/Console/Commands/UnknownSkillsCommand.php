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
     * Print the unknown skill names, grouped by the name in lowercase, with the number of postings that ask for each.
     *
     * The report reads the saved extractions only, so a name that the catalogue gained still shows until the posting
     * text or the prompt version changes.
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
            select lower(unknown_names.name) as name, count(*) as postings
            from job_postings as posting
            cross join jsonb_array_elements_text(posting.extracted_skills->'unknown') as unknown_names(name)
            group by lower(unknown_names.name)
            order by postings desc, name
            limit {$limit}
            SQL);

        if ($rows === []) {
            $this->info('No unknown skills.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Postings'],
            array_map(fn (stdClass $row): array => [$row->name, (int) $row->postings], $rows),
        );

        return self::SUCCESS;
    }
}
