<?php

namespace App\Console\Commands;

use App\Models\Resume;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

#[Signature('resume:parse-stats')]
#[Description('Show how often parse checks flag a problem, for each prompt version.')]
class ParseStatsCommand extends Command
{
    /**
     * Print, for each prompt version, the share of parses that each check flagged, and the number of resumes whose latest parse failed.
     *
     * The command prints counts and rates only. It never prints the resume text, the skill names or the bullets.
     */
    public function handle(): int
    {
        $versions = DB::table('resumes')
            ->whereNotNull('parsed_at')
            ->selectRaw(<<<'SQL'
                prompt_version,
                count(*) as parsed_count,
                count(*) filter (where parsed_data->'checks'->>'years_mismatch' = 'true') as mismatch_count,
                count(*) filter (where jsonb_array_length(parsed_data->'checks'->'ungrounded_skills') > 0) as skill_flag_count,
                coalesce(sum(jsonb_array_length(parsed_data->'checks'->'ungrounded_skills')), 0) as ungrounded_skill_total,
                count(*) filter (where coalesce(jsonb_array_length(parsed_data->'checks'->'ungrounded_bullets'), 0) > 0) as bullet_flag_count
                SQL)
            ->groupBy('prompt_version')
            ->orderBy('prompt_version')
            ->get();

        if ($versions->isEmpty()) {
            $this->info('No resumes have been parsed yet.');
            $this->printFailedCount();

            return self::SUCCESS;
        }

        $this->table(
            ['Prompt version', 'Parsed', 'Years mismatch', 'Ungrounded skills', 'Average ungrounded skills', 'Ungrounded bullets'],
            $versions->map(function (stdClass $version): array {
                $parsed = (int) $version->parsed_count;

                return [
                    $version->prompt_version ?? 'none',
                    $parsed,
                    $this->percentage((int) $version->mismatch_count, $parsed),
                    $this->percentage((int) $version->skill_flag_count, $parsed),
                    number_format((int) $version->ungrounded_skill_total / $parsed, 1),
                    $this->percentage((int) $version->bullet_flag_count, $parsed),
                ];
            })->all(),
        );

        $this->printFailedCount();

        return self::SUCCESS;
    }

    /**
     * Print how many resumes have a latest parse that failed, including resumes that never parsed.
     */
    private function printFailedCount(): void
    {
        $failed = Resume::query()
            ->whereNotNull('parse_failed_at')
            ->where(function (Builder $query): void {
                $query->whereNull('parsed_at')->orWhereColumn('parse_failed_at', '>', 'parsed_at');
            })
            ->count();

        $this->line("Resumes whose latest parse failed: {$failed}.");
    }

    /**
     * Format a count as a share of the total, with one decimal place.
     */
    private function percentage(int $count, int $total): string
    {
        return number_format($count / $total * 100, 1).'%';
    }
}
