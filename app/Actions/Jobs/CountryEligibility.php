<?php

namespace App\Actions\Jobs;

use App\Enums\JobRegion;
use App\Enums\WorkArrangement;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Builder;

final class CountryEligibility
{
    /**
     * Select country eligibility on the representative posting and remove only postings whose source excludes the country.
     *
     * The legacy rule is preserved exactly for every posting that is not source silent; only source silent postings
     * use the grounded reading, or remain unclear when neither eligibility list has a value. A reading that excludes
     * the country labels the posting as restricted and does not hide it: the reading comes from a model and can be
     * wrong, and a hidden job is a loss the user never sees (ADR 0029, drawback 1).
     *
     * @param  Builder<JobPosting>  $query
     */
    public function apply(Builder $query, string $code): void
    {
        $regions = array_values(array_map(
            fn (JobRegion $region): string => $region->value,
            array_filter(JobRegion::cases(), fn (JobRegion $region): bool => $region->includes($code)),
        ));
        $regionPlaceholders = implode(', ', array_fill(0, count($regions), '?'));
        $country = json_encode([$code], JSON_THROW_ON_ERROR);

        $legacyPredicate = <<<SQL
            job_postings.country_code = ?
            or job_postings.country_codes @> ?::jsonb
            or (job_postings.work_arrangement = ?
                and jsonb_array_length(job_postings.country_codes) = 0
                and (job_postings.region is null or job_postings.region in ({$regionPlaceholders})))
            SQL;

        $case = <<<SQL
            case
                when job_postings.work_arrangement = ?
                    and job_postings.country_code is null
                    and jsonb_array_length(job_postings.country_codes) = 0
                    and coalesce(job_postings.location, '') ~ '^[[:space:]]*$'
                then case
                    when jsonb_array_length(job_postings.eligible_countries) = 0
                        and jsonb_array_length(job_postings.eligible_regions) = 0 then 'unclear'
                    when job_postings.eligible_countries @> ?::jsonb
                        or jsonb_exists_any(job_postings.eligible_regions, array[{$regionPlaceholders}]::text[]) then 'open'
                    else 'restricted'
                end
                when ({$legacyPredicate}) then 'open'
                else 'not_open'
            end
            SQL;
        $bindings = [WorkArrangement::Remote->value, $country, ...$regions, $code, $country, WorkArrangement::Remote->value, ...$regions];

        $query->selectRaw($case.' as eligibility', $bindings)
            ->whereRaw('('.$case.") <> 'not_open'", $bindings);
    }
}
