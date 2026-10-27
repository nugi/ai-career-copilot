<?php

namespace App\Actions\Jobs;

use App\Actions\Money\ExchangeRates;
use App\Enums\Currency;
use App\Enums\JobRegion;
use App\Enums\WorkArrangement;
use App\JobSources\JobSourceRegistry;
use App\Models\JobPosting;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\CursorPaginator;

/**
 * List the open openings, one row each, through their representative posting (ADR 0025).
 *
 * The representative posting is the live posting of the most preferred source, then the newest. The pay posting is the
 * first live posting in the same order that has an amount, so an opening keeps its pay when the preferred board leaves
 * it out. Every filter reads the representative posting, except the floor filter, which reads the pay posting.
 */
final class SearchOpenings
{
    public function __construct(
        private JobSourceRegistry $sources,
        private ExchangeRates $exchangeRates,
    ) {}

    /**
     * Choose each opening's representative and pay before applying any filters.
     *
     * @return CursorPaginator<int, JobPosting>
     */
    public function handle(JobSearch $search, Profile $profile): CursorPaginator
    {
        $sources = '{'.implode(',', array_map(fn (string $source): string => '"'.$source.'"', array_keys($this->sources->all()))).'}';

        $ranked = JobPosting::query()->live()->whereNotNull('opening_id')
            ->select(['id', 'opening_id'])
            ->selectRaw('row_number() over (partition by opening_id order by coalesce(array_position(?::text[], source::text), 2147483647), posted_at desc, id desc) as representative_rank', [$sources])
            ->selectRaw('row_number() over (partition by opening_id order by (salary_min is null and salary_max is null), coalesce(array_position(?::text[], source::text), 2147483647), posted_at desc, id desc) as pay_rank', [$sources]);

        $query = JobPosting::query()
            ->select('job_postings.*')
            ->joinSub($ranked, 'representative', fn (JoinClause $join) => $join
                ->on('representative.id', '=', 'job_postings.id')->where('representative.representative_rank', 1))
            ->joinSub($ranked, 'pay_choice', fn (JoinClause $join) => $join
                ->on('pay_choice.opening_id', '=', 'job_postings.opening_id')->where('pay_choice.pay_rank', 1))
            ->join('job_postings as pay', 'pay.id', '=', 'pay_choice.id')
            ->with('skills');

        foreach (['salary_min', 'salary_max', 'salary_currency', 'salary_period', 'annual_salary_min', 'annual_salary_max'] as $column) {
            $query->addSelect('pay.'.$column.' as pay_'.$column);
        }

        if ($search->arrangements !== []) {
            $query->whereIn('job_postings.work_arrangement', $search->arrangements);
        }

        if ($search->country !== null) {
            $this->filterCountry($query, $search->country);
        }

        if ($search->skill !== null) {
            $query->whereHas('skills', fn (Builder $skills) => $skills->whereKey($search->skill->id));
        }

        if ($search->meetsFloor && $profile->min_salary !== null && $profile->salary_currency !== null) {
            $this->filterFloor($query, $profile);
        }

        return $query->orderByDesc('job_postings.posted_at')->orderByDesc('job_postings.id')
            ->cursorPaginate(20)->withQueryString();
    }

    /**
     * Keep the postings open to a person who lives in the country: the posting names the country, or it is remote and
     * open worldwide or to a region that includes the country.
     *
     * @param  Builder<JobPosting>  $query
     */
    private function filterCountry(Builder $query, string $code): void
    {
        $regions = array_filter(JobRegion::cases(), fn (JobRegion $region): bool => $region->includes($code));

        $query->where(fn (Builder $countries) => $countries
            ->where('job_postings.country_code', $code)
            ->orWhereJsonContains('job_postings.country_codes', $code)
            ->orWhere(fn (Builder $remote) => $remote
                ->where('job_postings.work_arrangement', WorkArrangement::Remote)
                ->whereJsonLength('job_postings.country_codes', 0)
                ->where(fn (Builder $region) => $region
                    ->whereNull('job_postings.region')
                    ->orWhereIn('job_postings.region', array_map(fn (JobRegion $region): string => $region->value, $regions)))));
    }

    /**
     * Remove only the pay that is certainly below the floor (ADR 0004, point 6).
     *
     * The annual floor is converted once per search into each currency with a rate, and 95% of it is compared with the
     * stored annual top of the range (ADR 0017). Pay without a currency, a rate, a period or a top stays in the list.
     *
     * @param  Builder<JobPosting>  $query
     */
    private function filterFloor(Builder $query, Profile $profile): void
    {
        $rates = $this->exchangeRates->latestFrom($profile->salary_currency->value);
        $factor = $profile->salary_period?->annualFactor($profile->salary_currency === Currency::Idr ? 'ID' : null) ?? 1;
        $annualFloor = $profile->min_salary * $factor;

        $query->where(function (Builder $pay) use ($rates, $annualFloor): void {
            $pay->whereNull('pay.salary_currency')
                ->orWhereNotIn('pay.salary_currency', array_keys($rates))
                ->orWhereNull('pay.salary_period')
                ->orWhereNull('pay.annual_salary_max');

            foreach ($rates as $currency => $rate) {
                $threshold = (int) floor(0.95 * $annualFloor * $rate->value);
                $pay->orWhere(fn (Builder $range) => $range
                    ->where('pay.salary_currency', $currency)
                    ->where('pay.annual_salary_max', '>=', $threshold));
            }
        });
    }
}
