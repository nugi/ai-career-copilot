<?php

namespace App\Console\Commands;

use App\Ai\ModelRouter;
use App\Models\JobPosting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;
use Throwable;

#[Signature('eval:embeddings {--config=* : A model and dimension size, such as text-embedding-3-small:512} {--text=full : The posting text to embed: full or short} {--labels=tests/Evals/embedding-benchmark.json : The labelled queries} {--details : Print the rank of each relevant opening for each query} {--dry-run : Print the corpus size and the estimated cost, and make no call}')]
#[Description('Compare embedding models on labelled job openings.')]
class EmbeddingBenchmarkCommand extends Command
{
    /**
     * Run the benchmark or estimate its cost.
     */
    public function handle(ModelRouter $router): int
    {
        $variant = $this->option('text');
        if (! in_array($variant, ['full', 'short'], true)) {
            $this->error('The text variant must be full or short.');

            return self::FAILURE;
        }

        $configurations = $this->option('config') ?: ['text-embedding-3-small:1536', 'text-embedding-3-small:512', 'text-embedding-3-large:1024', 'text-embedding-3-large:3072'];
        foreach ($configurations as $configuration) {
            if (! preg_match('/^([^:]+):([1-9][0-9]*)$/', $configuration)) {
                $this->error("Invalid configuration: {$configuration}");

                return self::FAILURE;
            }
        }

        $postings = JobPosting::query()->live()->whereNotNull('opening_id')->where('source', '!=', 'manual')->with('skills')->get();
        try {
            $labels = json_decode(File::get($this->option('labels')), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($labels) || ! isset($labels['queries']) || ! is_array($labels['queries'])) {
                throw new InvalidArgumentException('The labels file must contain queries.');
            }
            $queries = $this->resolveQueries($labels['queries'], $postings);
        } catch (Throwable $exception) {
            $this->error('Could not read labels: '.$exception->getMessage());

            return self::FAILURE;
        }

        $texts = $postings->map(fn (JobPosting $posting): string => $this->postingText($posting, $variant))->all();
        $this->line("Text variant: {$variant}");
        if ($this->option('dry-run')) {
            $characters = array_sum(array_map(mb_strlen(...), $texts));
            $tokens = (int) ceil($characters / 4);
            $this->line('Postings: '.$postings->count());
            $this->line('Openings: '.$postings->pluck('opening_id')->unique()->count());
            $this->line('Queries: '.count($queries));
            $this->line("Posting characters: {$characters}");
            $this->line("Estimated tokens: {$tokens}");
            $rows = [];
            foreach ($configurations as $configuration) {
                [$model] = explode(':', $configuration, 2);
                $rows[] = [$configuration, $router->cost($model, $tokens, 0) ?? 'unpriced'];
            }
            $this->table(['Configuration', 'Estimated cost (USD)'], $rows);

            return self::SUCCESS;
        }

        $rows = [];
        $details = [];
        foreach ($configurations as $configuration) {
            [$model, $dimensions] = explode(':', $configuration, 2);
            $tokens = 0;
            $vectors = [];
            foreach (array_chunk($texts, 50) as $chunk) {
                $response = Embeddings::for($chunk)->dimensions((int) $dimensions)->cache()->generate(Lab::OpenAI, $model);
                array_push($vectors, ...$response->embeddings);
                $tokens += $response->usage->inputTokens;
            }
            $queryVectors = [];
            if ($queries !== []) {
                $response = Embeddings::for(array_column($queries, 'query'))->dimensions((int) $dimensions)->cache()->generate(Lab::OpenAI, $model);
                $queryVectors = $response->embeddings;
                $tokens += $response->usage->inputTokens;
            }
            [$metrics, $ranks] = $this->evaluate($postings, $vectors, $queries, $queryVectors);
            $rows[] = [$configuration, ...$metrics, $tokens, $router->cost($model, $tokens, 0) ?? 'unpriced'];
            $details[$configuration] = $ranks;
        }
        $this->table(['Configuration', 'Hit@5', 'Recall@10', 'MRR', 'Recall@10 en', 'Recall@10 id', 'Tokens', 'Cost (USD)'], $rows);
        if ($this->option('details')) {
            foreach ($details as $configuration => $queryRanks) {
                $this->line($configuration);
                foreach ($queryRanks as $queryRank) {
                    $this->line($queryRank);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * Build the selected posting text.
     */
    private function postingText(JobPosting $posting, string $variant): string
    {
        $text = $posting->title;
        $skills = $posting->skills->pluck('name')->sort()->values()->all();
        if ($skills !== []) {
            $text .= "\nSkills: ".implode(', ', $skills);
        }
        if ($variant === 'full') {
            $text .= "\n\n".mb_substr($posting->description, 0, 12000);
        }

        return $text;
    }

    /**
     * Resolve posting labels to unique live opening IDs.
     *
     * @param  array<int, array{query: string, language: string, relevant: list<string>}>  $labels
     * @param  Collection<int, JobPosting>  $postings
     * @return list<array{query: string, language: string, relevant: list<int>}>
     */
    private function resolveQueries(array $labels, Collection $postings): array
    {
        $lookup = [];
        foreach ($postings as $posting) {
            $lookup[$posting->source.':'.$posting->external_id] = $posting->opening_id;
        }
        $queries = [];
        foreach ($labels as $label) {
            $relevant = [];
            foreach ($label['relevant'] as $item) {
                [$source, $externalId] = array_pad(explode(':', $item, 2), 2, null);
                $opening = $externalId === null ? null : ($lookup[$source.':'.$externalId] ?? null);
                if ($opening === null) {
                    $this->warn("Label not found in the live corpus: {$item}");
                } else {
                    $relevant[$opening] = $opening;
                }
            }
            if ($relevant === []) {
                $this->warn('Query has no relevant opening in the live corpus: '.$label['query']);

                continue;
            }
            $queries[] = ['query' => $label['query'], 'language' => $label['language'], 'relevant' => array_values($relevant)];
        }

        return $queries;
    }

    /**
     * Rank openings and calculate quality metrics.
     *
     * @param  Collection<int, JobPosting>  $postings
     * @param  array<int, array<int, float>>  $vectors
     * @param  list<array{query: string, language: string, relevant: list<int>}>  $queries
     * @param  array<int, array<int, float>>  $queryVectors
     * @return array{list<string>, list<string>}
     */
    private function evaluate(Collection $postings, array $vectors, array $queries, array $queryVectors): array
    {
        $hit = 0;
        $recall = 0;
        $mrr = 0;
        $languageRecall = ['en' => [], 'id' => []];
        $details = [];
        foreach ($queries as $index => $query) {
            $scores = [];
            foreach ($postings as $i => $posting) {
                $id = $posting->opening_id;
                if ($id === null) {
                    continue;
                }
                $score = $this->cosine($vectors[$i], $queryVectors[$index]);
                $scores[$id] = isset($scores[$id]) ? max($scores[$id], $score) : $score;
            }
            uksort($scores, fn (int $a, int $b): int => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
            $rankOf = array_flip(array_keys($scores));
            $relevantRanks = [];
            $first = PHP_INT_MAX;
            foreach ($query['relevant'] as $id) {
                $relevantRanks[$id] = $rankOf[$id] + 1;
                $first = min($first, $relevantRanks[$id]);
            }
            $hit += $first <= 5 ? 1 : 0;
            $mrr += 1 / $first;
            $queryRecall = count(array_filter($relevantRanks, fn (int $rank): bool => $rank <= 10)) / count($relevantRanks);
            $recall += $queryRecall;
            if (isset($languageRecall[$query['language']])) {
                $languageRecall[$query['language']][] = $queryRecall;
            }
            asort($relevantRanks);
            $parts = [];
            foreach ($relevantRanks as $id => $rank) {
                $parts[] = "{$id}: #{$rank}";
            }
            $details[] = $query['query'].': '.implode(' ', $parts);
        }
        $count = count($queries);
        $average = fn (array $values): float => $values === [] ? 0 : array_sum($values) / count($values);
        $metrics = [$count ? $hit / $count : 0, $count ? $recall / $count : 0, $count ? $mrr / $count : 0, $average($languageRecall['en']), $average($languageRecall['id'])];

        return [array_map(fn (float $value): string => number_format($value, 2, '.', ''), $metrics), $details];
    }

    /**
     * Calculate cosine similarity for two vectors.
     *
     * @param  array<int, float>  $left
     * @param  array<int, float>  $right
     */
    private function cosine(array $left, array $right): float
    {
        $dot = 0;
        $leftLength = 0;
        $rightLength = 0;
        foreach ($left as $i => $value) {
            $dot += $value * $right[$i];
            $leftLength += $value * $value;
            $rightLength += $right[$i] * $right[$i];
        }
        $denominator = sqrt($leftLength * $rightLength);

        return $denominator == 0 ? 0.0 : $dot / $denominator;
    }
}
