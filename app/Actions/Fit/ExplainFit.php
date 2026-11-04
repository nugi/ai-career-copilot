<?php

namespace App\Actions\Fit;

use App\Ai\Agents\FitExplainer;
use App\Ai\DailyBudget;
use App\Jobs\CheckFitFaithfulness;
use App\Models\JobPosting;
use App\Models\ResumeChunk;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Explain why a posting fits the user, citing the user's own resume (ADR 0034).
 *
 * The nearest chunks of the primary resume become numbered excerpts. The model's answer is validated, and then checked
 * in PHP: a citation of an excerpt that was not sent is removed, a reason left with no citation is dropped, and a fit
 * verdict with no grounded reason left is lowered to a weak fit. The answer is cached under the hash of everything the
 * model read, so it changes when the posting, the resume or the prompt changes.
 *
 * @phpstan-import-type Data from FitExplanation
 */
final class ExplainFit
{
    private const int MAX_REASONS = 5;

    private const int MAX_MISSING = 5;

    public function __construct(
        private RetrieveResumeExcerpts $retrieve,
        private DailyBudget $dailyBudget,
        private Cache $cache,
    ) {}

    /**
     * Explain the fit, or say why no explanation can be made. A provider failure gives a failed explanation, not an exception.
     */
    public function handle(User $user, JobPosting $posting): FitExplanation
    {
        $config = config('ai-routing.fit_explanations');
        $resume = $user->primaryResume;

        if ($resume === null || $resume->parsed_at === null) {
            return FitExplanation::unavailable('no_resume');
        }

        try {
            $chunks = $this->retrieve->handle($resume, $posting, $config['excerpts'])->values();
        } catch (Throwable $exception) {
            report($exception);

            return FitExplanation::unavailable('failed');
        }

        if ($chunks->isEmpty()) {
            return FitExplanation::unavailable('resume_not_ready');
        }

        $posting->loadMissing('skills');
        $skills = $posting->skills->map(fn (Skill $skill): string => $skill->name)->sort()->implode(', ');
        $description = mb_substr((string) $posting->description, 0, $config['description_characters']);
        $excerpts = array_values($chunks->map(fn (ResumeChunk $chunk): string => $chunk->content)->all());
        $key = 'fit:'.hash('sha256', json_encode([
            $user->getKey(), $posting->title, $posting->company_name, $skills, $description,
            $chunks->map(fn (ResumeChunk $chunk): array => [$chunk->id, $chunk->content])->all(),
            FitExplainer::PROMPT_VERSION,
        ], JSON_THROW_ON_ERROR));

        /** @var Data|null $cached */
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return FitExplanation::fromArray($cached);
        }

        if ($this->dailyBudget->isGloballyExceeded() || $this->dailyBudget->isExceeded($user)) {
            return FitExplanation::unavailable('budget');
        }

        $agent = new FitExplainer($user);

        try {
            $output = $this->ask($agent, $agent->message($posting->title, (string) $posting->company_name, $skills, $description, $excerpts));
            $validator = $this->validate($output);

            if ($validator->fails()) {
                $output = $this->ask($agent, $agent->message(
                    $posting->title, (string) $posting->company_name, $skills, $description, $excerpts, $this->errorsWithPaths($validator),
                ));
                $validator = $this->validate($output);
            }
        } catch (Throwable $exception) {
            report($exception);

            return FitExplanation::unavailable('failed');
        }

        if ($validator->fails()) {
            Log::warning('fit.explanation.invalid', ['job_posting_id' => $posting->getKey(), 'paths' => array_keys($validator->failed())]);

            return FitExplanation::unavailable('failed');
        }

        $explanation = $this->ground($validator->validated(), array_values($chunks->all()));
        $this->cache->put($key, $explanation->toArray(), now()->addHours($config['cache_hours']));

        Log::info('fit.explained', [
            'job_posting_id' => $posting->getKey(),
            'verdict' => $explanation->verdict,
            'reasons' => count($explanation->reasons),
            'dropped_reasons' => $explanation->droppedReasons,
            'downgraded' => $explanation->downgraded,
        ]);

        if ($explanation->reasons !== [] && $this->sampled((float) $config['faithfulness_sample_rate'])) {
            CheckFitFaithfulness::dispatch((int) $posting->getKey(), FitExplainer::PROMPT_VERSION, $explanation->reasons);
        }

        return $explanation;
    }

    /**
     * Keep each citation that names a sent excerpt, drop each reason left with none, and lower a fit verdict that has
     * no grounded reason left.
     *
     * @param  array<string, mixed>  $output  The validated output.
     * @param  list<ResumeChunk>  $chunks  The excerpts in the order they were numbered, from 1.
     */
    private function ground(array $output, array $chunks): FitExplanation
    {
        $reasons = [];
        $dropped = 0;

        foreach ($output['reasons'] as $reason) {
            $cited = [];

            foreach ($reason['excerpt_ids'] as $id) {
                $chunk = $chunks[(int) $id - 1] ?? null;

                if ($chunk !== null) {
                    $cited[$chunk->id] = ['id' => $chunk->id, 'section' => $chunk->section->value, 'content' => $chunk->content];
                }
            }

            if ($cited === [] || trim($reason['claim']) === '') {
                $dropped++;

                continue;
            }

            $reasons[] = ['claim' => trim($reason['claim']), 'excerpts' => array_values($cited)];
        }

        $reasons = array_slice($reasons, 0, self::MAX_REASONS);
        $verdict = $output['verdict'];
        $downgraded = $reasons === [] && $verdict !== 'weak_fit';

        return new FitExplanation(
            'ready',
            $downgraded ? 'weak_fit' : $verdict,
            trim($output['summary']),
            $reasons,
            array_slice(array_values(array_filter(array_map(trim(...), $output['missing_requirements']), fn (string $item): bool => $item !== '')), 0, self::MAX_MISSING),
            $dropped,
            $downgraded,
        );
    }

    /**
     * Send one message to the agent and return its structured output.
     *
     * @return array<mixed>
     */
    private function ask(FitExplainer $agent, string $message): array
    {
        /** @var StructuredAgentResponse $response */
        $response = $agent->prompt($message);

        return $response->toArray();
    }

    /**
     * Validate one output of the agent.
     *
     * @param  array<mixed>  $output
     */
    private function validate(array $output): ValidatorContract
    {
        return Validator::make($output, [
            'verdict' => ['required', 'in:strong_fit,partial_fit,weak_fit'],
            'summary' => ['required', 'string', 'max:600'],
            'reasons' => ['present', 'array', 'max:10'],
            'reasons.*.claim' => ['required', 'string', 'max:400'],
            'reasons.*.excerpt_ids' => ['present', 'array', 'max:10'],
            'reasons.*.excerpt_ids.*' => ['integer'],
            'missing_requirements' => ['present', 'array', 'max:10'],
            'missing_requirements.*' => ['string', 'max:200'],
        ]);
    }

    /**
     * List the validation errors for the next attempt, each with its field path.
     *
     * @return list<string>
     */
    private function errorsWithPaths(ValidatorContract $validator): array
    {
        $lines = [];

        foreach ($validator->errors()->messages() as $path => $messages) {
            foreach ($messages as $message) {
                $lines[] = str_contains($message, $path) ? $message : "{$path}: {$message}";
            }
        }

        return $lines;
    }

    /**
     * Decide whether this answer is in the faithfulness sample.
     */
    private function sampled(float $rate): bool
    {
        return $rate > 0 && mt_rand() / mt_getrandmax() < $rate;
    }
}
