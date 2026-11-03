<?php

namespace App\Actions\Jobs;

use App\Models\JobPosting;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;

final class MatchOpenings
{
    public function __construct(private SearchOpenings $searchOpenings) {}

    /**
     * Materialize the filtered representatives so cosine distance is sorted exactly, without HNSW.
     *
     * @return Collection<int, JobPosting>
     */
    public function handle(Profile $profile, string $vector, ?string $country, int $limit = 30): Collection
    {
        $search = new JobSearch($profile->work_arrangements->all(), $country, null, true);
        $query = $this->searchOpenings->query($search, $profile)->whereNotNull('job_postings.embedding');

        $sql = <<<SQL
            WITH filtered_openings AS MATERIALIZED (
                {$query->toSql()}
            )
            SELECT filtered_openings.*, embedding <=> ?::vector AS distance
            FROM filtered_openings
            ORDER BY distance, id DESC
            LIMIT ?
            SQL;

        return JobPosting::fromQuery($sql, [...$query->getBindings(), $vector, $limit])->load('skills');
    }
}
