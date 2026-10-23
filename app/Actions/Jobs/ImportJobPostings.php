<?php

namespace App\Actions\Jobs;

use App\JobSources\Contracts\JobSource;
use App\JobSources\Normalization\PostingNormalizer;
use App\Models\JobPosting;
use Illuminate\Support\Facades\DB;

/**
 * Imports one source run: normalizes its postings, upserts them on (source, dedup_key) and links the rows of one job
 * across sources to a canonical row (ADR 0018). Running it again with the same data creates no rows and changes no
 * links.
 */
class ImportJobPostings
{
    /**
     * Test database pg_trgm similarity: Laravel/PHP = 0.8888889; Laravel/payments = 0.58536583.
     * Keep the cutoff between these pairs, rounded to a 0.05 increment.
     */
    private const float TITLE_SIMILARITY_THRESHOLD = 0.75;

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

        foreach (array_chunk($rows, 100) as $chunk) {
            JobPosting::query()->upsert(
                $chunk,
                uniqueBy: ['source', 'dedup_key'],
                update: array_values(array_diff(array_keys($chunk[0]), ['source', 'external_id'])),
            );
        }

        return new ImportReport(
            source: $source->key(),
            fetch: $fetch,
            normalized: count($rows),
            skipped: $skipped,
            stored: count($rows),
            linksChanged: $this->linkDuplicates(),
        );
    }

    /**
     * Group live rows by their exact key and choose the lowest ID in each group.
     * Missing keys stay separate until an import normalizes them. Each group head
     * maps to the earliest similar head with the same company, location and set of
     * role and level words ("senior", "staff", ...), unless their known seniorities
     * conflict. Level words matter because sources often leave seniority unset. Map only once, without
     * following chains. Set every live member's link to that mapped head, or null
     * for the head itself. Expired rows stay untouched, and unchanged links are
     * not written, making repeated runs a no-op.
     */
    private function linkDuplicates(): int
    {
        return DB::update(<<<'SQL'
            update job_postings as posting
            set duplicate_of_id = links.duplicate_of_id
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
                    select head.id, coalesce((
                        select min(earlier.id)
                        from heads as earlier
                        where earlier.id < head.id
                            and earlier.normalized_company = head.normalized_company
                            and earlier.location_key = head.location_key
                            and similarity(earlier.normalized_title, head.normalized_title) >= ?
                            and earlier.role_words = head.role_words
                            and (earlier.seniority = head.seniority
                                or earlier.seniority is null or head.seniority is null)
                    ), head.id) as canonical_id
                    from heads as head
                )
                select live.id, nullif(mapped_heads.canonical_id, live.id) as duplicate_of_id
                from live
                join mapped_heads on mapped_heads.id = live.head_id
            ) as links
            where posting.id = links.id
                and posting.duplicate_of_id is distinct from links.duplicate_of_id
            SQL, [self::TITLE_SIMILARITY_THRESHOLD]);
    }
}
