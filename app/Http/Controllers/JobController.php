<?php

namespace App\Http\Controllers;

use App\Actions\Jobs\ListCountries;
use App\Actions\Jobs\SearchOpenings;
use App\Actions\Money\ExchangeRates;
use App\Http\Requests\ListJobsRequest;
use App\Http\Resources\JobListingResource;
use App\Models\JobPosting;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class JobController extends Controller
{
    /**
     * Show the open openings with the user's filters, 20 at a time with a cursor.
     *
     * The profile and the rates go on the attributes of the application request, not of the form request, which is a
     * copy: JobListingResource reads them there, so it converts each pay without a query.
     */
    public function index(ListJobsRequest $request, SearchOpenings $searchOpenings, ExchangeRates $exchangeRates, ListCountries $listCountries): Response
    {
        $profile = $request->user()->loadMissing('profile')->profile;
        $search = $request->toSearch();
        $paginator = $searchOpenings->handle($search, $profile);
        request()->attributes->set('jobs.profile', $profile);
        request()->attributes->set('jobs.rates', $profile->salary_currency === null
            ? [] : $exchangeRates->latestFrom($profile->salary_currency->value));

        return Inertia::render('jobs', [
            'jobs' => Inertia::scroll(fn () => JobListingResource::collection($paginator)),
            'filters' => [
                'arrangement' => array_map(fn ($arrangement): string => $arrangement->value, $search->arrangements),
                'country' => $search->country,
                'skill' => $search->skill?->id,
                'floor' => $search->meetsFloor,
            ],
            'skills' => fn () => $this->skills(),
            'countries' => fn () => $listCountries->handle($search->country),
            'floor' => $profile->min_salary === null ? null : [
                'amount' => $profile->min_salary,
                'currency' => $profile->salary_currency->value,
                'period' => $profile->salary_period->value,
            ],
        ]);
    }

    /**
     * List the 100 verified skills that most live postings ask for.
     *
     * @return Collection<int, stdClass>
     */
    private function skills(): Collection
    {
        return Skill::query()->select(['id', 'name'])->where('is_verified', true)
            ->withCount(['jobPostings as postings' => $this->live(...)])
            ->whereHas('jobPostings', $this->live(...))
            ->orderByDesc('postings')->orderBy('name')->limit(100)->toBase()->get();
    }

    /**
     * Keep the live postings.
     *
     * @param  Builder<JobPosting>  $postings
     */
    private function live(Builder $postings): void
    {
        $postings->live();
    }
}
