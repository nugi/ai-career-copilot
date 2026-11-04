<?php

namespace App\Console\Commands;

use App\Actions\Jobs\EmbedProfile;
use App\Actions\Jobs\MatchOpenings;
use App\Actions\Jobs\ProfileEmbeddingText;
use App\Actions\Jobs\RerankOpenings;
use App\Ai\ModelRouter;
use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('eval:rerank {email : The user whose matches are compared} {--country= : A country code instead of the profile country; an empty value means any country} {--top=10 : The number of rows to compare} {--dry-run : Print the candidates and the estimated cost, and make no reranking call}')]
#[Description('Compare the hybrid matches with and without reranking: the order, the latency and the cost.')]
class RerankReportCommand extends Command
{
    /**
     * Run the hybrid ranking, rerank it once without the cache, and print both orders and their cost.
     */
    public function handle(
        EmbedProfile $embedProfile,
        ProfileEmbeddingText $profileText,
        MatchOpenings $matchOpenings,
        RerankOpenings $rerankOpenings,
        ModelRouter $router,
    ): int {
        $user = User::query()->where('email', $this->argument('email'))->with('profile')->first();

        if ($user === null || $user->profile === null) {
            $this->error('No user with a profile has that email.');

            return self::FAILURE;
        }

        $profile = $user->profile;
        $option = $this->option('country');
        $country = is_string($option) ? $option : $profile->country_code;
        $country = $country === '' || $country === null ? null : strtoupper($country);
        $top = max(1, (int) $this->option('top'));
        $model = (string) config('ai-routing.reranking.model');

        try {
            $vector = $embedProfile->handle($profile);
        } catch (Throwable $exception) {
            $this->error('Could not embed the profile: '.$exception->getMessage());

            return self::FAILURE;
        }

        $start = hrtime(true);
        $hybrid = $matchOpenings->handle($profile, $vector, $country, terms: $profileText->keywordTerms($profile));
        $hybridMs = intdiv(hrtime(true) - $start, 1_000_000);
        $candidates = min($hybrid->count(), (int) config('ai-routing.reranking.candidates'));

        $this->line('Country: '.($country ?? 'any'));
        $this->line("Hybrid openings: {$hybrid->count()}, reranked candidates: {$candidates}");

        if ($this->option('dry-run')) {
            $units = max(1, (int) ceil($candidates / 100));
            $this->line("Model: {$model}, estimated search units: {$units}, estimated cost: ".($router->cost($model, 0, 0, $units) ?? 'unpriced').' USD');

            return self::SUCCESS;
        }

        config(['ai-routing.reranking.enabled' => true]);
        $before = AiUsage::query()->where('feature', AiFeature::Reranking)->max('id') ?? 0;
        $start = hrtime(true);
        $result = $rerankOpenings->handle($profile, $hybrid, fresh: true);
        $rerankMs = intdiv(hrtime(true) - $start, 1_000_000);

        if (! $result->reranked) {
            $this->error('The reranking call failed or the global budget is spent; see the log.');

            return self::FAILURE;
        }

        $usage = AiUsage::query()->where('feature', AiFeature::Reranking)->where('id', '>', $before)->latest('id')->first();
        $hybridIds = $hybrid->pluck('id')->all();
        $rows = [];

        foreach ($result->openings->take($top)->values() as $position => $posting) {
            $rows[] = [
                $position + 1,
                $this->label($hybrid[$position] ?? null),
                $this->label($posting),
                (int) array_search($posting->id, $hybridIds, true) + 1,
                number_format((float) $posting->getAttribute('rerank_score'), 3),
            ];
        }

        $this->table(['#', 'Hybrid', 'Reranked', 'Hybrid rank', 'Rerank score'], $rows);
        $this->table(['Mode', 'Latency (ms)', 'Cost (USD)'], [
            ['Hybrid', $hybridMs, '0.000000'],
            ['Hybrid + rerank', $hybridMs + $rerankMs, $usage?->cost_usd ?? 'not recorded'],
        ]);
        $this->line('Rerank call latency recorded in ai_usages: '.($usage?->latency_ms ?? 'not recorded').' ms');

        return self::SUCCESS;
    }

    /**
     * Name a posting by its title and company.
     */
    private function label(?JobPosting $posting): string
    {
        return $posting === null ? '' : "{$posting->title} ({$posting->company_name})";
    }
}
