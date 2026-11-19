<?php

namespace App\Actions\Skills;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Rank the required and nice-to-have skills of scored postings that the user does not have, in one query.
 *
 * Each posting adds its fit score, halved for every half_life_days since it was posted, and a nice-to-have skill adds only
 * nice_to_have_weight of that. Skill names are compared in lower case, as FitScorer compares them.
 */
/**
 * Rank the skills of scored postings that the user does not have, in one query.
 *
 * Each posting adds its fit score, halved for every half_life_days since it was posted. A nice-to-have skill adds only
 * nice_to_have_weight of that. Skill names are compared in lower case, as FitScorer compares them.
 */
final class RankSkillGaps
{
    /**
     * @param  array<int, int>  $scores
     * @return list<SkillGap>
     */
    public function handle(User $user, array $scores, ?int $limit = null): array
    {
        if ($scores === []) {
            return [];
        }

        $sql = <<<'SQL'
            WITH top_matches AS (
                SELECT job_postings.id, job_postings.posted_at, input.score,
                    count(*) OVER () AS match_count
                FROM unnest(?::bigint[], ?::int[]) AS input(posting_id, score)
                JOIN job_postings ON job_postings.id = input.posting_id
                WHERE job_postings.skills_extracted_at IS NOT NULL
            ),
            weighted_skills AS (
                SELECT skills.id, skills.name, job_posting_skill.is_required, top_matches.match_count,
                    top_matches.score / 100.0
                        * power(0.5, greatest(0, extract(epoch FROM (?::timestamp - top_matches.posted_at)) / 86400.0) / ?::numeric)
                        * CASE WHEN job_posting_skill.is_required THEN 1 ELSE ?::numeric END AS weight
                FROM top_matches
                JOIN job_posting_skill ON job_posting_skill.job_posting_id = top_matches.id
                JOIN skills ON skills.id = job_posting_skill.skill_id
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM user_skills
                    JOIN skills AS owned_skills ON owned_skills.id = user_skills.skill_id
                    WHERE user_skills.user_id = ?
                        AND lower(trim(owned_skills.name)) = lower(trim(skills.name))
                )
            ),
            skill_totals AS (
                SELECT id, name AS skill, sum(weight) AS weight,
                    count(*) FILTER (WHERE is_required) AS required_jobs,
                    count(*) FILTER (WHERE NOT is_required) AS nice_jobs,
                    count(*) AS jobs, max(match_count) AS "of"
                FROM weighted_skills
                GROUP BY id, name
            )
            SELECT skill_totals.*, rank() OVER (ORDER BY weight DESC) AS rank
            FROM skill_totals
            SQL;

        $query = DB::query()->fromRaw('('.$sql.') AS ranked_gaps', [
            '{'.implode(',', array_keys($scores)).'}',
            '{'.implode(',', array_values($scores)).'}',
            now(),
            config('skill-gaps.half_life_days'),
            config('skill-gaps.nice_to_have_weight'),
            $user->id,
        ])->orderBy('rank')->orderByDesc('jobs')->orderBy('skill');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query->get()->map(fn (object $row): SkillGap => new SkillGap(
            (string) $row->skill,
            round((float) $row->weight, 3),
            (int) $row->required_jobs,
            (int) $row->nice_jobs,
            (int) $row->jobs,
            (int) $row->of,
        ))->all());
    }
}
