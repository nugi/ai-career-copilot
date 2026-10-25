<?php

namespace App\Actions\Jobs;

use App\JobSources\Contracts\JobSource;
use App\JobSources\Normalization\PostingNormalizer;
use App\Models\JobPosting;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Imports one source run: normalizes its postings, upserts them on (source, dedup_key) and links the rows of one job
 * across sources to a canonical row (ADR 0018). Running it again with the same data creates no rows and changes no
 * links. A later run that sends no salary, employment type or seniority keeps the stored value, and a new salary
 * replaces the whole salary group. first_seen_at never changes, last_seen_at moves on every run, and updated_at moves
 * only when the content hash changes.
 */
class ImportJobPostings
{
    /**
     * Test database pg_trgm similarity: Laravel/PHP = 0.8888889; Laravel/payments = 0.58536583.
     * Keep the cutoff between these pairs, rounded to a 0.05 increment.
     */
    private const float TITLE_SIMILARITY_THRESHOLD = 0.75;

    /**
     * Pay columns that a later run without pay must not clear; a new salary replaces all of them together.
     *
     * @var array<string, literal-string>
     */
    private const array SALARY_UPDATES = [
        'salary_min' => 'case when excluded.salary_min is null and excluded.salary_max is null then job_postings.salary_min else excluded.salary_min end',
        'salary_max' => 'case when excluded.salary_min is null and excluded.salary_max is null then job_postings.salary_max else excluded.salary_max end',
        'salary_currency' => 'case when excluded.salary_min is null and excluded.salary_max is null then job_postings.salary_currency else excluded.salary_currency end',
        'salary_period' => 'case when excluded.salary_min is null and excluded.salary_max is null then job_postings.salary_period else excluded.salary_period end',
        'annual_salary_min' => 'case when excluded.salary_min is null and excluded.salary_max is null then job_postings.annual_salary_min else excluded.annual_salary_min end',
        'annual_salary_max' => 'case when excluded.salary_min is null and excluded.salary_max is null then job_postings.annual_salary_max else excluded.annual_salary_max end',
        'salary_normalization_version' => 'case when excluded.salary_min is null and excluded.salary_max is null then job_postings.salary_normalization_version else excluded.salary_normalization_version end',
    ];

    /**
     * Columns where a later run that omits the value keeps the stored one.
     *
     * @var array<string, literal-string>
     */
    private const array KEPT_UPDATES = [
        'employment_type' => 'coalesce(excluded.employment_type, job_postings.employment_type)',
        'seniority' => 'coalesce(excluded.seniority, job_postings.seniority)',
    ];

    public function __construct(private PostingNormalizer $normalizer) {}

    /**
     * Store each usable source row, preserving its identity and source-owned expiry.
     */
    public function handle(JobSource $source): ImportReport
    {
        $generator = $source->fetch();
        $rows = [];
        $skipped = 0;

        foreach ($generator as $posting) {
            $normalized = $this->normalizer->normalize($posting);

            if ($normalized === null) {
                $skipped++;

                continue;
            }

            $attributes = $normalized->toAttributes();
            $attributes['country_codes'] = json_encode($attributes['country_codes'], JSON_THROW_ON_ERROR);
            $rows[] = $attributes;
        }

        $fetch = $generator->getReturn();
        $seenAt = now();

        foreach ($rows as $index => $row) {
            $rows[$index]['first_seen_at'] = $seenAt;
            $rows[$index]['last_seen_at'] = $seenAt;
        }

        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach (array_chunk($rows, 100) as $chunk) {
            $stored = JobPosting::query()
                ->where('source', $source->key())
                ->whereIn('dedup_key', array_column($chunk, 'external_id'))
                ->pluck('content_hash', 'dedup_key');

            foreach ($chunk as $row) {
                $key = $row['external_id'];

                if (! $stored->has($key)) {
                    $created++;
                } elseif ($stored->get($key) === $row['content_hash']) {
                    $unchanged++;
                } else {
                    $updated++;
                }
            }

            JobPosting::query()->upsert(
                $chunk,
                uniqueBy: ['source', 'dedup_key'],
                update: self::updates(array_keys($chunk[0])),
            );
        }

        return new ImportReport(
            source: $source->key(),
            fetch: $fetch,
            normalized: count($rows),
            skipped: $skipped,
            created: $created,
            updated: $updated,
            unchanged: $unchanged,
            linksChanged: $this->linkDuplicates(),
        );
    }

    /**
     * Build the upsert update list. The salary group moves as one, so a new maximum is never stored beside an old minimum.
     * An existing row keeps its first-seen time, and its updated time moves only when the content hash changes.
     *
     * @param  list<string>  $columns
     * @return array<int|string, string|Expression>
     */
    private static function updates(array $columns): array
    {
        $updates = [];

        foreach ($columns as $column) {
            if (in_array($column, ['source', 'external_id', 'first_seen_at'], true)) {
                continue;
            }

            if (isset(self::SALARY_UPDATES[$column])) {
                $updates[$column] = DB::raw(self::SALARY_UPDATES[$column]);

                continue;
            }

            if (isset(self::KEPT_UPDATES[$column])) {
                $updates[$column] = DB::raw(self::KEPT_UPDATES[$column]);

                continue;
            }

            $updates[] = $column;
        }

        $updates['updated_at'] = DB::raw(
            'case when job_postings.content_hash is distinct from excluded.content_hash then excluded.updated_at else job_postings.updated_at end'
        );

        return $updates;
    }

    /**
     * Group live rows by their exact key and choose the lowest ID in each group.
     * Missing keys stay separate until an import normalizes them. Each group head
     * maps to the earliest similar head with the same company, location and set of
     * role and level words ("senior", "staff", ...), unless their known seniorities
     * conflict. Level words matter because sources often leave seniority unset. Map only once, without
     * following chains. Set every live member's link to that mapped head, or null
     * for the head itself, and record whether the link came from the exact key or the
     * title trigram match, with the rounded similarity score for the latter. Expired rows stay untouched, and
     * unchanged links are not written, making repeated runs a no-op.
     */
    private function linkDuplicates(): int
    {
        return DB::update(<<<'SQL'
            update job_postings as posting
            set duplicate_of_id = links.duplicate_of_id,
                duplicate_reason = links.duplicate_reason,
                duplicate_similarity = links.duplicate_similarity
            from (
                with live as (
                    select id, normalized_company, normalized_title, location_key, seniority,
                        min(id) over (
                            partition by duplicate_key, case when duplicate_key is null then id end
                        ) as head_id
                    from job_postings
                    where expires_at is null or expires_at > now()
                ), heads as (
                    select live.*,
                        array(
                            select role
                            from unnest(array[
                                'frontend', 'backend', 'fullstack', 'mobile', 'ios', 'android',
                                'data', 'devops', 'platform', 'qa', 'security', 'machine',
                                'design', 'product', 'sales', 'support',
                                'intern', 'junior', 'senior', 'staff', 'principal', 'lead', 'head',
                                'manager', 'director'
                            ]) as role
                            where role = any(string_to_array(normalized_title, ' '))
                            order by role
                        ) as role_words
                    from live
                    where id = head_id
                ), mapped_heads as (
                    select head.id,
                        coalesce(matched.id, head.id) as canonical_id,
                        matched.id is not null as has_trigram_match,
                        matched.score
                    from heads as head
                    left join lateral (
                        select earlier.id,
                            round(similarity(earlier.normalized_title, head.normalized_title)::numeric, 3) as score
                        from heads as earlier
                        where earlier.id < head.id
                            and earlier.normalized_company = head.normalized_company
                            and earlier.location_key = head.location_key
                            and similarity(earlier.normalized_title, head.normalized_title) >= ?
                            and earlier.role_words = head.role_words
                            and (earlier.seniority = head.seniority
                                or earlier.seniority is null or head.seniority is null)
                        order by earlier.id
                        limit 1
                    ) as matched on true
                )
                select live.id,
                    nullif(mapped_heads.canonical_id, live.id) as duplicate_of_id,
                    case
                        when mapped_heads.canonical_id = live.id then null
                        when mapped_heads.has_trigram_match then 'trigram'
                        else 'exact'
                    end as duplicate_reason,
                    case
                        when mapped_heads.canonical_id <> live.id and mapped_heads.has_trigram_match
                            then mapped_heads.score
                        else null
                    end as duplicate_similarity
                from live
                join mapped_heads on mapped_heads.id = live.head_id
            ) as links
            where posting.id = links.id
                and (posting.duplicate_of_id is distinct from links.duplicate_of_id
                    or posting.duplicate_reason is distinct from links.duplicate_reason
                    or posting.duplicate_similarity is distinct from links.duplicate_similarity)
            SQL, [self::TITLE_SIMILARITY_THRESHOLD]);
    }
}
