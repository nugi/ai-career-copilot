<?php

namespace App\Console\Commands;

use App\Actions\Jobs\JobSearch;
use App\Actions\Jobs\MatchOpenings;
use App\Actions\Jobs\ProfileEmbeddingText;
use App\Actions\Jobs\RerankOpenings;
use App\Actions\Jobs\SearchOpenings;
use App\Ai\Agents\RelevanceGrader;
use App\Ai\DailyBudget;
use App\Ai\EmbedTexts;
use App\Enums\AiFeature;
use App\Enums\KeywordWeighting;
use App\Evals\Retrieval\GoldenProfile;
use App\Evals\Retrieval\RetrievalMetrics;
use App\Models\AiUsage;
use App\Models\JobPosting;
use App\Models\Profile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Throwable;

#[Signature('eval:retrieval {--labels=tests/Evals/retrieval.json : The golden set} {--weighting=* : Keyword weightings to compare: count, idf, idf_proficiency (default all)} {--with-rerank : Add a reranked variant of each hybrid ranking (calls Cohere)} {--pool : Add every unlabelled posting from the top 10 of any variant to the golden set, with a null grade, and stop} {--grade : Fill each null grade with the relevance grader, save after each case, and stop} {--bootstrap=0 : Resamples for the 95% intervals of nDCG@10; 0 turns them off} {--save : Write the results as a JSON file} {--output=docs/evals/retrieval : The directory that --save writes to}')]
#[Description('Measure recall@10, MRR and nDCG@10 of each ranking mode on the golden set of profiles.')]
class RetrievalEvalCommand extends Command
{
    private const int K = 10;

    private const int LIST_SIZE = 30;

    private const int SEED = 25;

    private const string BASELINE = 'hybrid:count';

    /**
     * Run every case through every variant, then pool the unlabelled results or score the variants.
     */
    public function handle(
        EmbedTexts $embedTexts,
        ProfileEmbeddingText $profileText,
        MatchOpenings $matchOpenings,
        RerankOpenings $rerankOpenings,
        SearchOpenings $searchOpenings,
    ): int {
        $path = (string) $this->option('labels');

        try {
            /** @var array{cases: list<array{id: string, description: string, profile: array{target_roles: list<string>, skills: array<string, string>, work_arrangements: list<string>, country_code: string|null, min_salary: int|null, salary_currency: string|null, salary_period: string|null}, labels: list<array{posting: string, grade: int|null}>}>} $golden */
            $golden = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->error("Could not read the golden set {$path}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $weightings = $this->weightings();

        if ($weightings === null) {
            return self::FAILURE;
        }

        if ($this->option('grade')) {
            return $this->grade($path, $golden);
        }

        if ($this->option('with-rerank')) {
            config(['ai-routing.reranking.enabled' => true]);
        }

        $openingByKey = $this->openingsByKey();
        $runs = [];

        foreach ($golden['cases'] as $case) {
            $profile = GoldenProfile::make($case['profile']);

            try {
                $runs[$case['id']] = $this->rank($case['profile']['country_code'], $profile, $weightings, $embedTexts, $profileText, $matchOpenings, $rerankOpenings);
            } catch (Throwable $exception) {
                $this->error("Case {$case['id']} failed: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        if ($this->option('pool')) {
            return $this->pool($path, $golden, $runs, $openingByKey);
        }

        return $this->score($golden, $runs, $openingByKey, $searchOpenings, $weightings);
    }

    /**
     * Rank one case in every variant, as lists of opening IDs with the posting that represented each opening.
     *
     * @param  list<KeywordWeighting>  $weightings
     * @return array<string, array{openings: list<int>, postings: array<int, JobPosting>}>
     */
    private function rank(
        ?string $country,
        Profile $profile,
        array $weightings,
        EmbedTexts $embedTexts,
        ProfileEmbeddingText $profileText,
        MatchOpenings $matchOpenings,
        RerankOpenings $rerankOpenings,
    ): array {
        $vector = $embedTexts->handle([$profileText->text($profile)])['vectors'][0];
        $terms = $profileText->keywordTerms($profile);
        $multipliers = $profileText->keywordMultipliers($profile);
        $runs = ['semantic' => $matchOpenings->handle($profile, $vector, $country, self::LIST_SIZE)];

        foreach ($weightings as $weighting) {
            $runs["keyword:{$weighting->value}"] = $matchOpenings->handle($profile, null, $country, self::LIST_SIZE, $terms, $weighting, $multipliers);
            $hybrid = $matchOpenings->handle($profile, $vector, $country, self::LIST_SIZE, $terms, $weighting, $multipliers);
            $runs["hybrid:{$weighting->value}"] = $hybrid;

            if ($this->option('with-rerank')) {
                $result = $rerankOpenings->handle($profile, $hybrid);

                if (! $result->reranked) {
                    throw new RuntimeException('The reranking call failed or the global budget is spent.');
                }

                $runs["hybrid:{$weighting->value}+rerank"] = $result->openings;
            }
        }

        $lists = [];

        foreach ($runs as $variant => $postings) {
            $openings = [];
            $byOpening = [];

            foreach ($postings as $posting) {
                if ($posting->source === 'manual') {
                    continue;
                }

                $opening = (int) $posting->opening_id;
                $openings[] = $opening;
                $byOpening[$opening] = $posting;
            }

            $lists[$variant] = ['openings' => $openings, 'postings' => $byOpening];
        }

        return $lists;
    }

    /**
     * Fill only null labels, persisting each answered case so interrupted runs can resume.
     *
     * @param  array<string, mixed>  $golden
     */
    private function grade(string $path, array $golden): int
    {
        $grader = new RelevanceGrader;
        $budget = app(DailyBudget::class);
        $graded = 0;
        $calls = 0;
        $notLive = 0;
        $invalid = 0;
        $invocations = [];

        foreach ($golden['cases'] as $caseIndex => $case) {
            $selected = [];

            foreach ($case['labels'] as $labelIndex => $label) {
                if ($label['grade'] === null) {
                    $selected[$labelIndex] = $label['posting'];
                }
            }

            if ($selected === []) {
                continue;
            }

            $live = JobPosting::query()->live()->where('source', '!=', 'manual')
                ->whereIn(DB::raw("source || ':' || external_id"), array_values($selected))
                ->with('skills')->orderBy('id')->get()
                ->keyBy(fn (JobPosting $posting): string => "{$posting->source}:{$posting->external_id}");
            $pending = [];

            foreach ($selected as $labelIndex => $key) {
                if (! isset($live[$key])) {
                    $notLive++;

                    continue;
                }

                $pending[] = ['label' => $labelIndex, 'posting' => $live[$key]];
            }

            if ($pending === []) {
                continue;
            }

            $pending = (new Randomizer(new Mt19937(self::SEED)))->shuffleArray($pending);
            $postings = [];

            foreach ($pending as $index => $entry) {
                $postings[$index + 1] = $entry['posting'];
            }

            if ($budget->isBatchExceeded()) {
                $this->error('The batch budget is spent. Earlier cases stay saved.');

                return self::FAILURE;
            }

            try {
                $calls++;
                $response = $grader->prompt($grader->message($case['profile'], $postings));

                if (! $response instanceof StructuredAgentResponse) {
                    throw new RuntimeException('The relevance grader returned no structured answer.');
                }

                $invocations[] = $response->invocationId;
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Case {$case['id']} failed: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $profileField = $response['profile_field'] ?? null;
            $profileLevel = $response['profile_level'] ?? null;

            if (! in_array($profileField, RelevanceGrader::PROFILE_FIELDS, true)
                || ! in_array($profileLevel, RelevanceGrader::LEVELS, true)) {
                $invalid += count($postings);

                continue;
            }

            $accepted = [];
            $entries = $response['postings'] ?? null;

            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $number = $entry['posting_id'] ?? null;
                $reason = $entry['reason'] ?? null;
                $mainWork = $entry['main_work'] ?? null;
                $postingLevel = $entry['posting_level'] ?? null;
                $matchesTargetRole = $entry['matches_target_role'] ?? null;

                if (! is_int($number) || ! isset($postings[$number]) || isset($accepted[$number])
                    || ! is_string($reason) || ! in_array($mainWork, RelevanceGrader::MAIN_WORK, true)
                    || ! in_array($postingLevel, RelevanceGrader::LEVELS, true) || ! is_bool($matchesTargetRole)) {
                    continue;
                }

                $labelIndex = $pending[$number - 1]['label'];
                $golden['cases'][$caseIndex]['labels'][$labelIndex]['grade'] = RelevanceGrader::grade($profileField, $profileLevel, $mainWork, $postingLevel, $matchesTargetRole);
                $golden['cases'][$caseIndex]['labels'][$labelIndex]['grader'] = 'relevance-grader/'.RelevanceGrader::PROMPT_VERSION;
                $golden['cases'][$caseIndex]['labels'][$labelIndex]['reason'] = trim($reason);
                $golden['cases'][$caseIndex]['labels'][$labelIndex]['facts'] = [
                    'main_work' => $mainWork,
                    'posting_level' => $postingLevel,
                    'profile_level' => $profileLevel,
                    'matches_target_role' => $matchesTargetRole,
                ];
                $accepted[$number] = true;
                $graded++;
            }

            $invalid += count($postings) - count($accepted);

            if ($accepted !== []) {
                $golden['labelled_on'] = now()->utc()->format('Y-m-d');
                $golden['labelled_by'] = 'relevance-grader/'.RelevanceGrader::PROMPT_VERSION;
                File::put($path, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            }
        }

        $cost = (float) AiUsage::query()->where('feature', AiFeature::RelevanceGrading->value)
            ->whereIn('invocation_id', $invocations)->sum('cost_usd');
        $this->info(sprintf('Graded: %d. Calls: %d. Not live: %d. Invalid: %d. Cost: $%.6f', $graded, $calls, $notLive, $invalid, $cost));

        return self::SUCCESS;
    }

    /**
     * Add the unlabelled postings of every variant's top 10 to the golden set with a null grade, so they can be graded by
     * hand. The labels already graded stay as they are.
     *
     * @param  array<string, mixed>  $golden
     * @param  array<string, array<string, array{openings: list<int>, postings: array<int, JobPosting>}>>  $runs
     * @param  array<string, int>  $openingByKey
     */
    private function pool(string $path, array $golden, array $runs, array $openingByKey): int
    {
        $added = 0;

        foreach ($golden['cases'] as $index => $case) {
            $labelled = [];

            foreach ($case['labels'] as $label) {
                if (isset($openingByKey[$label['posting']])) {
                    $labelled[$openingByKey[$label['posting']]] = true;
                }
            }

            foreach ($runs[$case['id']] as $run) {
                foreach (array_slice($run['openings'], 0, self::K) as $opening) {
                    if (isset($labelled[$opening])) {
                        continue;
                    }

                    $posting = $run['postings'][$opening];
                    $golden['cases'][$index]['labels'][] = [
                        'posting' => "{$posting->source}:{$posting->external_id}",
                        'title' => $posting->title,
                        'company' => $posting->company_name,
                        'grade' => null,
                    ];
                    $labelled[$opening] = true;
                    $added++;
                }
            }
        }

        File::put($path, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        $this->info("Added {$added} postings to grade in {$path}. Set each null grade to 0, 1 or 2.");

        return self::SUCCESS;
    }

    /**
     * Score every variant on every case and print the means, the intervals and the coverage.
     *
     * @param  array<string, mixed>  $golden
     * @param  array<string, array<string, array{openings: list<int>, postings: array<int, JobPosting>}>>  $runs
     * @param  array<string, int>  $openingByKey
     * @param  list<KeywordWeighting>  $weightings
     *
     * @throws JsonException
     */
    private function score(array $golden, array $runs, array $openingByKey, SearchOpenings $searchOpenings, array $weightings): int
    {
        $perCase = [];
        $judged = 0;
        $live = 0;
        $unjudged = [];

        foreach ($golden['cases'] as $case) {
            $profile = GoldenProfile::make($case['profile']);
            $eligible = array_flip($searchOpenings->query(
                new JobSearch(array_values($profile->work_arrangements->all()), $case['profile']['country_code'], null, true),
                $profile,
            )->pluck('opening_id')->map(fn (mixed $id): int => (int) $id)->all());
            $grades = [];

            foreach ($case['labels'] as $label) {
                if ($label['grade'] === null) {
                    continue;
                }

                $judged++;
                $opening = $openingByKey[$label['posting']] ?? null;

                if ($opening !== null) {
                    $live++;

                    if (isset($eligible[$opening])) {
                        $grades[$opening] = max($grades[$opening] ?? 0, (int) $label['grade']);
                    }
                }
            }

            $known = array_flip(array_filter(array_map(fn (array $label): ?int => $label['grade'] === null ? null : ($openingByKey[$label['posting']] ?? null), $case['labels'])));

            foreach ($runs[$case['id']] as $variant => $run) {
                $top = array_slice($run['openings'], 0, self::K);
                $perCase[$variant][$case['id']] = [
                    'recall_at_10' => RetrievalMetrics::recallAt($run['openings'], $grades, self::K),
                    'mrr' => RetrievalMetrics::reciprocalRank($run['openings'], $grades),
                    'ndcg_at_10' => RetrievalMetrics::ndcgAt($run['openings'], $grades, self::K),
                ];
                $unjudged[$variant] = ($unjudged[$variant] ?? 0) + count(array_filter($top, fn (int $opening): bool => ! isset($known[$opening])));
            }
        }

        $coverage = $judged === 0 ? 0.0 : $live / $judged;
        $samples = max(0, (int) $this->option('bootstrap'));
        $summary = [];

        foreach ($perCase as $variant => $cases) {
            $ndcg = array_values(array_filter(array_column($cases, 'ndcg_at_10'), fn (?float $value): bool => $value !== null));
            $row = [
                'recall_at_10' => $this->mean(array_column($cases, 'recall_at_10')),
                'mrr' => $this->mean(array_column($cases, 'mrr')),
                'ndcg_at_10' => $this->mean(array_column($cases, 'ndcg_at_10')),
                'cases' => count($ndcg),
                'unjudged_in_top_10' => $unjudged[$variant] ?? 0,
            ];

            if ($samples > 0 && $ndcg !== []) {
                $row['ndcg_interval'] = RetrievalMetrics::bootstrap($ndcg, $samples, self::SEED);
            }

            if ($samples > 0 && $variant !== self::BASELINE && isset($perCase[self::BASELINE])) {
                [$values, $baseline] = $this->paired($cases, $perCase[self::BASELINE]);

                if ($values !== []) {
                    $row['ndcg_vs_baseline'] = RetrievalMetrics::pairedBootstrap($values, $baseline, $samples, self::SEED);
                }
            }

            $summary[$variant] = $row;
        }

        $scored = max(array_column($summary, 'cases') ?: [0]);

        if ($scored === 0) {
            $this->warn('No case has a graded relevant label among its eligible openings. Run --pool and grade the labels first.');

            return self::FAILURE;
        }

        $this->line(sprintf('Cases scored: %d of %d. Label coverage: %.0f%% of %d graded labels are live.', $scored, count($golden['cases']), 100 * $coverage, $judged));

        if ($coverage < 0.8) {
            $this->warn('Under 80% of the graded labels are live. Pool and grade again before trusting a trend.');
        }

        $this->table(['Variant', 'Recall@10', 'MRR', 'nDCG@10', '95% interval', 'vs '.self::BASELINE, 'Unjudged in top 10'], array_map(
            fn (string $variant, array $row): array => [
                $variant,
                $this->format($row['recall_at_10']),
                $this->format($row['mrr']),
                $this->format($row['ndcg_at_10']),
                isset($row['ndcg_interval']) ? sprintf('%.3f–%.3f', $row['ndcg_interval']['low'], $row['ndcg_interval']['high']) : '',
                isset($row['ndcg_vs_baseline']) ? sprintf('%+.3f (%+.3f to %+.3f)', $row['ndcg_vs_baseline']['mean'], $row['ndcg_vs_baseline']['low'], $row['ndcg_vs_baseline']['high']) : '',
                $row['unjudged_in_top_10'],
            ],
            array_keys($summary),
            $summary,
        ));

        if ($this->option('save')) {
            $this->save($summary, $perCase, $coverage, $judged, $weightings, $samples);
        }

        return self::SUCCESS;
    }

    /**
     * Write the run to the output directory with the date, the git SHA and the settings that produced it.
     *
     * @param  array<string, array<string, mixed>>  $summary
     * @param  array<string, array<string, array<string, float|null>>>  $perCase
     * @param  list<KeywordWeighting>  $weightings
     *
     * @throws JsonException
     */
    private function save(array $summary, array $perCase, float $coverage, int $judged, array $weightings, int $samples): void
    {
        $sha = trim(Process::run('git rev-parse --short HEAD')->output()) ?: 'unknown';
        $date = now()->utc();
        $directory = (string) $this->option('output');
        $directory = preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $directory) === 1 ? $directory : base_path($directory);
        $file = $directory.'/'.$date->format('Y-m-d-His')."-{$sha}.json";

        File::ensureDirectoryExists(dirname($file));
        File::put($file, json_encode([
            'date' => $date->toIso8601String(),
            'git_sha' => $sha,
            'settings' => [
                'embedding_model' => config('ai-routing.embeddings.model'),
                'rerank_model' => $this->option('with-rerank') ? config('ai-routing.reranking.model') : null,
                'rerank_candidates' => $this->option('with-rerank') ? config('ai-routing.reranking.candidates') : null,
                'rrf_k' => MatchOpenings::RRF_K,
                'weightings' => array_map(fn (KeywordWeighting $weighting): string => $weighting->value, $weightings),
                'k' => self::K,
                'bootstrap_samples' => $samples,
                'bootstrap_seed' => self::SEED,
            ],
            'corpus' => ['live_postings' => JobPosting::query()->live()->count()],
            'labels' => ['graded' => $judged, 'live_share' => round($coverage, 3)],
            'summary' => $summary,
            'cases' => $perCase,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");

        $this->info('Saved '.str_replace(base_path().'/', '', $file));
    }

    /**
     * Map each live posting's source:external_id to its opening, so a label survives the rebuild of opening IDs.
     *
     * @return array<string, int>
     */
    private function openingsByKey(): array
    {
        $lookup = [];

        foreach (JobPosting::query()->live()->where('source', '!=', 'manual')->whereNotNull('opening_id')->get(['source', 'external_id', 'opening_id']) as $posting) {
            $lookup["{$posting->source}:{$posting->external_id}"] = (int) $posting->opening_id;
        }

        return $lookup;
    }

    /**
     * Read the weighting options, or every weighting when none is given.
     *
     * @return list<KeywordWeighting>|null
     */
    private function weightings(): ?array
    {
        /** @var list<string> $options */
        $options = $this->option('weighting');

        if ($options === []) {
            return KeywordWeighting::cases();
        }

        $weightings = [];

        foreach ($options as $option) {
            $weighting = KeywordWeighting::tryFrom($option);

            if ($weighting === null) {
                $this->error("Unknown weighting {$option}. Use count, idf or idf_proficiency.");

                return null;
            }

            $weightings[] = $weighting;
        }

        return $weightings;
    }

    /**
     * Pair the nDCG@10 of a variant with the baseline's on the cases that both scored.
     *
     * @param  array<string, array<string, float|null>>  $cases
     * @param  array<string, array<string, float|null>>  $baseline
     * @return array{list<float>, list<float>}
     */
    private function paired(array $cases, array $baseline): array
    {
        $values = [];
        $base = [];

        foreach ($cases as $id => $metrics) {
            $value = $metrics['ndcg_at_10'];
            $other = $baseline[$id]['ndcg_at_10'] ?? null;

            if ($value !== null && $other !== null) {
                $values[] = $value;
                $base[] = $other;
            }
        }

        return [$values, $base];
    }

    /**
     * The mean of the non-null values, or null when every value is null.
     *
     * @param  array<int|string, float|null>  $values
     */
    private function mean(array $values): ?float
    {
        $values = array_filter($values, fn (?float $value): bool => $value !== null);

        return $values === [] ? null : array_sum($values) / count($values);
    }

    private function format(?float $value): string
    {
        return $value === null ? '' : number_format($value, 3);
    }
}
