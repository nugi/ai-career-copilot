<?php

namespace App\Actions\Jobs;

use App\JobSources\Contracts\JobSource;
use App\JobSources\Normalization\NormalizedPosting;
use App\JobSources\Normalization\PostingNormalizer;
use App\Models\JobPosting;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Imports one source run: normalizes its postings, upserts them on (source, dedup_key) and links the rows of one job
 * across sources to one opening (ADR 0019). Running it again with the same data creates no rows and changes no links. A
 * later run that sends no salary, employment type or seniority keeps the stored value, and a new salary replaces the
 * whole salary group. Before it computes the content hash it fills those missing values from the stored row, so the hash
 * describes the row that is stored and a run that changes nothing counts as unchanged. first_seen_at never changes,
 * last_seen_at moves on every run, and updated_at moves only when the content hash changes. The writes and the link step
 * run in one transaction under an advisory lock, so a stored posting always has an opening.
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
            $rows[] = $attributes;
        }

        $fetch = $generator->getReturn();
        $seenAt = now();

        $created = 0;
        $updated = 0;
        $unchanged = 0;

        $linksChanged = DB::transaction(function () use ($source, $rows, $seenAt, &$created, &$updated, &$unchanged): int {
            DB::select("select pg_advisory_xact_lock(hashtext('import_job_postings'))");

            foreach (array_chunk($rows, 100) as $chunk) {
                $stored = JobPosting::query()
                    ->toBase()
                    ->where('source', $source->key())
                    ->whereIn('dedup_key', array_column($chunk, 'external_id'))
                    ->select([
                        'dedup_key', 'content_hash', 'salary_min', 'salary_max', 'salary_currency', 'salary_period',
                        'annual_salary_min', 'annual_salary_max', 'salary_normalization_version', 'employment_type', 'seniority',
                    ])
                    ->get()
                    ->keyBy('dedup_key');

                foreach ($chunk as $index => $row) {
                    $key = $row['external_id'];
                    $existing = $stored->get($key);

                    if ($existing !== null) {
                        $row = self::withKeptValues($row, $existing);
                    }

                    $hashInput = $row;
                    unset($hashInput['content_hash'], $hashInput['text_hash']);
                    $row['content_hash'] = NormalizedPosting::contentHash($hashInput);

                    if ($existing === null) {
                        $created++;
                    } elseif ($existing->content_hash === $row['content_hash']) {
                        $unchanged++;
                    } else {
                        $updated++;
                    }

                    $row['country_codes'] = json_encode($row['country_codes'], JSON_THROW_ON_ERROR);
                    $row['first_seen_at'] = $seenAt;
                    $row['last_seen_at'] = $seenAt;
                    $chunk[$index] = $row;
                }

                JobPosting::query()->upsert(
                    $chunk,
                    uniqueBy: ['source', 'dedup_key'],
                    update: self::updates(array_keys($chunk[0])),
                );
            }

            return $this->linkOpenings();
        });

        return new ImportReport(
            source: $source->key(),
            fetch: $fetch,
            normalized: count($rows),
            skipped: $skipped,
            created: $created,
            updated: $updated,
            unchanged: $unchanged,
            linksChanged: $linksChanged,
        );
    }

    /**
     * Fill the salary group, the employment type and the seniority from the stored row when this run sends no value,
     * so the hash of the row is the hash of what stays stored.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function withKeptValues(array $row, stdClass $stored): array
    {
        if ($row['salary_min'] === null && $row['salary_max'] === null) {
            $row['salary_min'] = self::storedInt($stored->salary_min);
            $row['salary_max'] = self::storedInt($stored->salary_max);
            $row['salary_currency'] = $stored->salary_currency;
            $row['salary_period'] = $stored->salary_period;
            $row['annual_salary_min'] = self::storedInt($stored->annual_salary_min);
            $row['annual_salary_max'] = self::storedInt($stored->annual_salary_max);
            $row['salary_normalization_version'] = self::storedInt($stored->salary_normalization_version);
        }

        if ($row['employment_type'] === null) {
            $row['employment_type'] = $stored->employment_type;
        }

        if ($row['seniority'] === null) {
            $row['seniority'] = $stored->seniority;
        }

        return $row;
    }

    /**
     * Cast a stored integer column, so the JSON of the hash matches the JSON of an incoming row.
     */
    private static function storedInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * Build the upsert update list. An existing row keeps its first-seen time, and its updated time moves only when the
     * content hash changes.
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
     * following chains. Each opening is claimed by the group that holds its oldest live posting, a group keeps the
     * lowest opening it claims and merges the others into it, and a group that claims no opening gets a new one. A
     * posting stored after it expired gets an opening of its own, and expired postings otherwise keep their opening.
     * Every live member's opening, reason and score are set to the group's, and only changed rows are written, making
     * repeated runs a no-op. The count leaves out new postings that get an opening of their own.
     */
    private function linkOpenings(): int
    {
        DB::statement(<<<'SQL'
            create temporary table posting_links on commit drop as
            with live as (
                select id, normalized_company, normalized_title, location_key, seniority,
                    min(id) over (
                        partition by duplicate_key, case when duplicate_key is null then id end
                    ) as head_id
                from job_postings
                where expires_at is null or expires_at > ?
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
            select live.id as posting_id,
                mapped_heads.canonical_id as group_id,
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
            SQL, [now(), self::TITLE_SIMILARITY_THRESHOLD]);

        DB::statement(<<<'SQL'
            create temporary table group_openings on commit drop as
            select distinct on (posting.opening_id) posting.opening_id, links.group_id
            from posting_links as links
            join job_postings as posting on posting.id = links.posting_id
            where posting.opening_id is not null
            order by posting.opening_id, posting.id
            SQL);

        DB::statement(<<<'SQL'
            create temporary table group_targets on commit drop as
            select groups.group_id,
                case when min(claims.opening_id) is null
                    then nextval(pg_get_serial_sequence('openings', 'id'))
                    else min(claims.opening_id) end as opening_id,
                min(claims.opening_id) is null as is_new
            from (select distinct group_id from posting_links) as groups
            left join group_openings as claims on claims.group_id = groups.group_id
            group by groups.group_id
            SQL);

        DB::statement(<<<'SQL'
            insert into openings (id, created_at, updated_at)
            select opening_id, now(), now() from group_targets where is_new
            SQL);

        DB::statement(<<<'SQL'
            update openings
            set merged_into_id = targets.opening_id, updated_at = now()
            from group_openings as claims
            join group_targets as targets on targets.group_id = claims.group_id
            where claims.opening_id <> targets.opening_id
                and (openings.id = claims.opening_id or openings.merged_into_id = claims.opening_id)
            SQL);

        DB::statement(<<<'SQL'
            update job_postings as posting
            set opening_id = targets.opening_id
            from group_openings as claims
            join group_targets as targets on targets.group_id = claims.group_id
            where claims.opening_id <> targets.opening_id
                and posting.opening_id = claims.opening_id
                and not exists (select 1 from posting_links as links where links.posting_id = posting.id)
            SQL);

        $changed = (int) DB::scalar(<<<'SQL'
            select count(*) as changed
            from posting_links as links
            join group_targets as targets on targets.group_id = links.group_id
            join job_postings as posting on posting.id = links.posting_id
            where (posting.opening_id is not null and posting.opening_id <> targets.opening_id)
                or posting.duplicate_reason is distinct from links.duplicate_reason
                or posting.duplicate_similarity is distinct from links.duplicate_similarity
            SQL);

        DB::statement(<<<'SQL'
            update job_postings as posting
            set opening_id = targets.opening_id,
                duplicate_reason = links.duplicate_reason,
                duplicate_similarity = links.duplicate_similarity
            from posting_links as links
            join group_targets as targets on targets.group_id = links.group_id
            where posting.id = links.posting_id
                and (posting.opening_id is distinct from targets.opening_id
                    or posting.duplicate_reason is distinct from links.duplicate_reason
                    or posting.duplicate_similarity is distinct from links.duplicate_similarity)
            SQL);

        DB::statement(<<<'SQL'
            with orphans as (
                select id, nextval(pg_get_serial_sequence('openings', 'id')) as opening_id
                from job_postings where opening_id is null
            ), created as (
                insert into openings (id, created_at, updated_at)
                select opening_id, now(), now() from orphans
            )
            update job_postings as posting
            set opening_id = orphans.opening_id
            from orphans
            where posting.id = orphans.id
            SQL);

        DB::statement('drop table posting_links, group_openings, group_targets');

        return $changed;
    }
}
