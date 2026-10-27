<?php

namespace App\Http\Controllers;

use App\Actions\Jobs\SearchOpenings;
use App\Actions\Money\ExchangeRates;
use App\Http\Requests\ListJobsRequest;
use App\Http\Resources\JobListingResource;
use App\Models\JobPosting;
use App\Models\Skill;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;
use Locale;

class JobController extends Controller
{
    /**
     * Show the open openings with the user's filters, 20 at a time with a cursor.
     *
     * The profile and the rates go on the attributes of the application request, not of the form request, which is a
     * copy: JobListingResource reads them there, so it converts each pay without a query.
     */
    public function index(ListJobsRequest $request, SearchOpenings $searchOpenings, ExchangeRates $exchangeRates): Response
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
            'skills' => fn () => Skill::query()->select(['id', 'name'])->where('is_verified', true)
                ->withCount(['jobPostings as postings' => fn (Builder $postings) => $postings->live()])
                ->whereHas('jobPostings', fn (Builder $postings) => $postings->live())
                ->orderByDesc('postings')->orderBy('name')->limit(100)->get()
                ->map(fn (Skill $skill): array => ['id' => $skill->id, 'name' => $skill->name, 'postings' => (int) $skill->postings]),
            'countries' => fn () => $this->countries(),
            'floor' => $profile->min_salary === null ? null : [
                'amount' => $profile->min_salary,
                'currency' => $profile->salary_currency->value,
                'period' => $profile->salary_period->value,
            ],
        ]);
    }

    /**
     * List the countries that live postings name, and Indonesia, by their English name.
     *
     * @return list<array{code: string, name: string}>
     */
    private function countries(): array
    {
        $single = JobPosting::query()->live()->whereNotNull('country_code')->selectRaw('country_code as code')->toBase();
        $multiple = JobPosting::query()->live()->selectRaw('jsonb_array_elements_text(country_codes::jsonb) as code')->toBase();

        return $single->union($multiple)->pluck('code')->push('ID')->unique()
            ->map(fn (string $code): array => ['code' => $code, 'name' => Locale::getDisplayRegion('-'.$code, 'en')])
            ->sortBy('name')->values()->all();
    }
}
