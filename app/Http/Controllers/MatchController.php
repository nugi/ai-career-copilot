<?php

namespace App\Http\Controllers;

use App\Actions\Jobs\EmbedProfile;
use App\Actions\Jobs\ListCountries;
use App\Actions\Jobs\MatchOpenings;
use App\Actions\Money\ExchangeRates;
use App\Http\Requests\ListMatchesRequest;
use App\Http\Resources\JobListingResource;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MatchController extends Controller
{
    /**
     * Show the nearest eligible openings, keeping resource context on the application request.
     */
    public function index(
        ListMatchesRequest $request,
        EmbedProfile $embedProfile,
        MatchOpenings $matchOpenings,
        ExchangeRates $exchangeRates,
        ListCountries $listCountries,
    ): Response {
        $profile = $request->user()->loadMissing('profile')->profile;
        $profile->makeHidden(['match_embedding', 'match_embedding_model', 'match_embedding_hash']);
        $country = $request->country();
        request()->attributes->set('jobs.profile', $profile);
        request()->attributes->set('jobs.rates', $profile->salary_currency === null
            ? [] : $exchangeRates->latestFrom($profile->salary_currency->value));

        $failed = false;

        try {
            $vector = $embedProfile->handle($profile);
            $matches = $matchOpenings->handle($profile, $vector, $country);
        } catch (Throwable $exception) {
            report($exception);
            $failed = true;
            $matches = [];
        }

        return Inertia::render('matches', [
            'matches' => JobListingResource::collection($matches),
            'filters' => ['country' => $country],
            'countries' => $listCountries->handle($country),
            'failed' => $failed,
        ]);
    }
}
