<?php

namespace App\Actions\Jobs;

use App\Enums\KeywordWeighting;
use App\Models\JobPosting;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fuse a semantic distance leg and a weighted keyword leg over the same filtered openings.
 *
 * Both legs rank the whole filtered set. A cap on each leg saves work only when each leg is a separate retrieval;
 * here both are computed over the same materialized rows, so a cap would only throw away rank information (ADR 0031).
 */
final class MatchOpenings
{
    public const int RRF_K = 60;

    public function __construct(private SearchOpenings $searchOpenings) {}

    /**
     * Rank semantic matches by distance and keyword matches by the summed weight of the terms they contain, then fuse
     * their ranks.
     *
     * The weight of a term is 1 for Count, ln(N / df) for Idf, where N is the number of filtered openings and df the
     * number that contain the term, and that times the term's multiplier for IdfProficiency. A term that no filtered
     * opening contains has no weight. With Count, the order is the earlier one: distinct terms, then ts_rank.
     *
     * @param  list<string>  $terms
     * @param  array<string, float>  $multipliers  A multiplier per term, used by IdfProficiency. A missing term counts 1.0.
     * @return Collection<int, JobPosting>
     */
    public function handle(
        Profile $profile,
        ?string $vector,
        ?string $country,
        int $limit = 30,
        array $terms = [],
        KeywordWeighting $weighting = KeywordWeighting::Count,
        array $multipliers = [],
        ?JobSearch $search = null,
    ): Collection {
        $search ??= new JobSearch(array_values($profile->work_arrangements->all()), $country, null, true);
        $query = $this->searchOpenings->query($search, $profile);
        $termArray = '{'.implode(',', array_map(fn (string $term): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $term).'"', $terms)).'}';
        $multiplierArray = '{'.implode(',', array_map(fn (string $term): string => (string) (float) ($multipliers[$term] ?? 1.0), $terms)).'}';
        $rrfK = self::RRF_K;

        $sql = <<<SQL
            WITH filtered_openings AS MATERIALIZED (
                {$query->toSql()}
            ),
            terms AS MATERIALIZED (
                SELECT phrase, max(multiplier) AS multiplier
                FROM unnest(?::text[], ?::float8[]) AS input(term, multiplier)
                CROSS JOIN LATERAL phraseto_tsquery('simple', normalize_search_text(term)) AS phrase
                WHERE phrase::text <> ''
                GROUP BY phrase
            ),
            corpus AS (
                SELECT count(*) AS n FROM filtered_openings
            ),
            term_weights AS MATERIALIZED (
                SELECT terms.phrase, CASE ?
                        WHEN 'count' THEN 1.0
                        WHEN 'idf' THEN ln(corpus.n::numeric / frequency.df)
                        ELSE ln(corpus.n::numeric / frequency.df) * terms.multiplier::numeric
                    END AS weight
                FROM terms
                CROSS JOIN corpus
                CROSS JOIN LATERAL (
                    SELECT count(*) AS df FROM filtered_openings WHERE search_vector @@ terms.phrase
                ) AS frequency
                WHERE frequency.df > 0
            ),
            query_terms AS (
                SELECT string_agg('(' || phrase::text || ')', ' | ')::tsquery AS any_term FROM terms
            ),
            scored AS (
                SELECT filtered_openings.*,
                    embedding <=> ?::vector AS distance,
                    (SELECT count(*) FROM term_weights WHERE search_vector @@ phrase) AS hits,
                    (SELECT coalesce(sum(weight), 0) FROM term_weights WHERE search_vector @@ phrase) AS keyword_weight,
                    ts_rank(search_vector, query_terms.any_term, 32) AS kw_score
                FROM filtered_openings CROSS JOIN query_terms
            ),
            ranked AS (
                SELECT scored.*,
                    CASE WHEN distance IS NOT NULL THEN row_number() OVER
                        (PARTITION BY distance IS NOT NULL ORDER BY distance, scored.id DESC) END AS semantic_rank,
                    CASE WHEN hits > 0 THEN row_number() OVER
                        (PARTITION BY hits > 0 ORDER BY keyword_weight DESC, hits DESC, kw_score DESC, scored.id DESC) END AS keyword_rank
                FROM scored
            )
            SELECT ranked.*, coalesce(1.0 / ({$rrfK} + semantic_rank), 0)
                + coalesce(1.0 / ({$rrfK} + keyword_rank), 0) AS score
            FROM ranked
            WHERE semantic_rank IS NOT NULL OR keyword_rank IS NOT NULL
            ORDER BY score DESC, distance ASC NULLS LAST, ranked.id DESC
            LIMIT ?
            SQL;

        return JobPosting::fromQuery($sql, [...$query->getBindings(), $termArray, $multiplierArray, $weighting->value, $vector, $limit])->load('skills');
    }
}
