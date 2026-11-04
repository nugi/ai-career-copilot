<?php

namespace App\Actions\Jobs;

use App\Ai\DailyBudget;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use Throwable;

/**
 * Reorder the first openings of the hybrid ranking by a reranking model that reads the profile query and each posting
 * together (ADR 0033).
 *
 * The result is cached under the hash of the query and the documents, so it goes stale exactly when the profile text or
 * one of the candidate postings changes. A disabled flag, a spent global budget, a timeout or a provider failure keeps
 * the hybrid order: the page never fails because of the reranker.
 */
final class RerankOpenings
{
    public function __construct(
        private ProfileEmbeddingText $profileText,
        private DailyBudget $dailyBudget,
        private Cache $cache,
    ) {}

    /**
     * Rerank the first candidates and keep the rest of the list after them in hybrid order.
     *
     * @param  Collection<int, JobPosting>  $openings  The hybrid ranking, with skills loaded.
     */
    public function handle(Profile $profile, Collection $openings, bool $fresh = false): RerankResult
    {
        $config = config('ai-routing.reranking');

        if (! $config['enabled'] || $openings->count() < 2) {
            return new RerankResult($openings, false);
        }

        $candidates = $openings->take($config['candidates'])->values();
        $rest = $openings->slice($config['candidates'])->values();
        $query = $this->query($profile);
        $documents = $candidates->map(fn (JobPosting $posting): string => $this->document($posting, $config['description_characters']))->all();
        $key = 'rerank:'.hash('sha256', json_encode([$config['provider'], $config['model'], $query, $documents], JSON_THROW_ON_ERROR));

        $start = hrtime(true);
        /** @var list<array{int, float}>|null $order */
        $order = $fresh ? null : $this->cache->get($key);
        $cached = $order !== null;

        if ($order === null) {
            if ($this->dailyBudget->isGloballyExceeded()) {
                Log::info('matches.rerank.skipped', ['reason' => 'budget']);

                return new RerankResult($openings, false);
            }

            try {
                $response = Reranking::of($documents)
                    ->timeout($config['timeout_seconds'])
                    ->rerank($query, $config['provider'], $config['model']);
            } catch (Throwable $exception) {
                report($exception);

                return new RerankResult($openings, false);
            }

            $order = array_values(array_map(
                fn (RankedDocument $result): array => [$result->index, $result->score],
                $response->results,
            ));
            $this->cache->put($key, $order, now()->addHours($config['cache_hours']));
        }

        $latencyMs = intdiv(hrtime(true) - $start, 1_000_000);
        $reranked = $this->reorder($candidates, $order);

        Log::info('matches.reranked', [
            'candidates' => $candidates->count(),
            'cached' => $cached,
            'latency_ms' => $latencyMs,
        ]);

        return new RerankResult(new Collection([...$reranked, ...$rest]), true, $cached, $latencyMs);
    }

    /**
     * Put the candidates in the reranker's order with their scores. An index that is out of range or repeated is
     * ignored, and a candidate that the reranker left out keeps its hybrid place after the ranked ones.
     *
     * @param  Collection<int, JobPosting>  $candidates
     * @param  list<array{int, float}>  $order
     * @return list<JobPosting>
     */
    private function reorder(Collection $candidates, array $order): array
    {
        $ranked = [];

        foreach ($order as [$index, $score]) {
            $posting = $candidates->get($index);

            if ($posting === null || isset($ranked[$index])) {
                continue;
            }

            $posting->setAttribute('rerank_score', $score);
            $ranked[$index] = $posting;
        }

        foreach ($candidates as $index => $posting) {
            $ranked[$index] ??= $posting;
        }

        return array_values($ranked);
    }

    /**
     * Phrase the profile as a request for a job, with the same roles and skills as the vector query.
     */
    private function query(Profile $profile): string
    {
        return "A job for this candidate.\nTarget roles: ".$this->profileText->text($profile);
    }

    /**
     * Describe a posting by its title, company, skills and the start of its description, the parts a reader would
     * judge the fit by. The description is cut, so a long posting does not slow the call.
     */
    private function document(JobPosting $posting, int $descriptionCharacters): string
    {
        $skills = $posting->skills->map(fn (Skill $skill): string => $skill->name)->sort()->implode(', ');

        return implode("\n", array_filter([
            "Title: {$posting->title}",
            "Company: {$posting->company_name}",
            $skills === '' ? null : "Skills: {$skills}",
            Str::limit(trim((string) $posting->description), $descriptionCharacters, ''),
        ], fn (?string $line): bool => $line !== null && $line !== ''));
    }
}
