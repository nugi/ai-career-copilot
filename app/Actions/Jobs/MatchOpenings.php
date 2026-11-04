<?php

namespace App\Actions\Jobs;

use App\Models\JobPosting;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fuse a semantic distance leg and a keyword hit-count leg over the same filtered openings.
 */
final class MatchOpenings
{
    private const int LEG_SIZE = 50;

    private const int RRF_K = 60;

    public function __construct(private SearchOpenings $searchOpenings) {}

    /**
     * Rank semantic matches by distance and keyword matches by distinct terms, then fuse their ranks.
     *
     * @param  list<string>  $terms
     * @return Collection<int, JobPosting>
     */
    public function handle(Profile $profile, ?string $vector, ?string $country, int $limit = 30, array $terms = []): Collection
    {
        $search = new JobSearch(array_values($profile->work_arrangements->all()), $country, null, true);
        $query = $this->searchOpenings->query($search, $profile);
        $termArray = '{'.implode(',', array_map(fn (string $term): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $term).'"', $terms)).'}';
        $legSize = max(self::LEG_SIZE, $limit);
        $rrfK = self::RRF_K;

        $sql = <<<SQL
            WITH filtered_openings AS MATERIALIZED (
                {$query->toSql()}
            ),
            terms AS MATERIALIZED (
                SELECT DISTINCT term, phraseto_tsquery('simple', term) AS phrase
                FROM unnest(?::text[]) AS input(term)
                WHERE phraseto_tsquery('simple', term)::text <> ''
            ),
            query_terms AS (
                SELECT string_agg('(' || phrase::text || ')', ' | ')::tsquery AS any_term FROM terms
            ),
            scored AS (
                SELECT filtered_openings.*,
                    embedding <=> ?::vector AS distance,
                    (SELECT count(*) FROM terms WHERE search_vector @@ phrase) AS hits,
                    ts_rank(search_vector, query_terms.any_term, 32) AS kw_score
                FROM filtered_openings CROSS JOIN query_terms
            ),
            ranked AS (
                SELECT scored.*,
                    CASE WHEN distance IS NOT NULL THEN row_number() OVER
                        (PARTITION BY distance IS NOT NULL ORDER BY distance, scored.id DESC) END AS sem_rank,
                    CASE WHEN hits > 0 THEN row_number() OVER
                        (PARTITION BY hits > 0 ORDER BY hits DESC, kw_score DESC, scored.id DESC) END AS kw_rank
                FROM scored
            ),
            legs AS (
                SELECT ranked.*,
                    CASE WHEN sem_rank <= ? THEN sem_rank END AS semantic_rank,
                    CASE WHEN kw_rank <= ? THEN kw_rank END AS keyword_rank
                FROM ranked
            )
            SELECT legs.*, coalesce(1.0 / ({$rrfK} + semantic_rank), 0)
                + coalesce(1.0 / ({$rrfK} + keyword_rank), 0) AS score
            FROM legs
            WHERE semantic_rank IS NOT NULL OR keyword_rank IS NOT NULL
            ORDER BY score DESC, distance ASC NULLS LAST, legs.id DESC
            LIMIT ?
            SQL;

        return JobPosting::fromQuery($sql, [...$query->getBindings(), $termArray, $vector, $legSize, $legSize, $limit])->load('skills');
    }
}
