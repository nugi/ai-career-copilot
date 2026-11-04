<?php

namespace App\Http\Controllers;

use App\Actions\Jobs\EmbedProfile;
use App\Actions\Jobs\ListCountries;
use App\Actions\Jobs\MatchOpenings;
use App\Actions\Jobs\ProfileEmbeddingText;
use App\Actions\Jobs\RerankOpenings;
use App\Actions\Money\ExchangeRates;
use App\Enums\SearchMode;
use App\Http\Requests\ListMatchesRequest;
use App\Http\Resources\JobListingResource;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MatchController extends Controller
{
    /**
     * Rank eligible openings by keyword, semantic similarity, or both, falling back to keywords in hybrid mode.
     *
     * Hybrid mode then reranks its first openings when the reranking flag is on (ADR 0033). Keyword and semantic mode
     * stay unreranked, so they remain the baselines that the reranked order is compared with.
     */
    public function index(
        ListMatchesRequest $request,
        EmbedProfile $embedProfile,
        ProfileEmbeddingText $profileEmbeddingText,
        MatchOpenings $matchOpenings,
        RerankOpenings $rerankOpenings,
        ExchangeRates $exchangeRates,
        ListCountries $listCountries,
    ): Response {
        $profile = $request->user()->loadMissing('profile')->profile;
        $profile->makeHidden(['match_embedding', 'match_embedding_model', 'match_embedding_hash']);
        $country = $request->country();
        $mode = $request->mode();
        request()->attributes->set('jobs.profile', $profile);
        request()->attributes->set('jobs.rates', $profile->salary_currency === null
            ? [] : $exchangeRates->latestFrom($profile->salary_currency->value));

        $failed = false;
        $reranked = false;
        $matches = [];
        $vector = null;

        try {
            $terms = $mode->usesKeywords() ? $profileEmbeddingText->keywordTerms($profile) : [];

            if ($mode->usesVector()) {
                try {
                    $vector = $embedProfile->handle($profile);
                } catch (Throwable $exception) {
                    report($exception);
                    if (! $mode->usesKeywords()) {
                        $failed = true;
                    }
                }
            }

            if ($vector === null && $terms === []) {
                $failed = true;
            } elseif (! $failed) {
                $matches = $matchOpenings->handle($profile, $vector, $country, terms: $terms);

                if ($mode === SearchMode::Hybrid) {
                    $result = $rerankOpenings->handle($profile, $matches);
                    $matches = $result->openings;
                    $reranked = $result->reranked;
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $failed = true;
        }

        return Inertia::render('matches', [
            'matches' => JobListingResource::collection($matches),
            'filters' => ['country' => $country, 'mode' => $mode->value],
            'countries' => $listCountries->handle($country),
            'failed' => $failed,
            'reranked' => $reranked,
        ]);
    }
}
