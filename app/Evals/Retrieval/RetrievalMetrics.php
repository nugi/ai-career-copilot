<?php

namespace App\Evals\Retrieval;

/**
 * The ranking metrics of the retrieval evaluation (ADR 0035), on lists of opening IDs and graded labels.
 *
 * A grade is 0 (not relevant), 1 (partly relevant) or 2 (clearly relevant). An opening without a grade counts 0. Each
 * metric returns null when the case has no relevant label, so such a case does not pull the mean towards zero.
 */
final class RetrievalMetrics
{
    /**
     * The share of the relevant openings (grade 1 or 2) that the first $k results contain.
     *
     * @param  list<int>  $ranked
     * @param  array<int, int>  $grades  Grade by opening ID.
     */
    public static function recallAt(array $ranked, array $grades, int $k): ?float
    {
        $relevant = array_keys(array_filter($grades, fn (int $grade): bool => $grade >= 1));

        if ($relevant === []) {
            return null;
        }

        return count(array_intersect(array_slice($ranked, 0, $k), $relevant)) / count($relevant);
    }

    /**
     * 1 over the rank of the first result with the case's top grade: grade 2, or grade 1 when no opening has grade 2.
     * A list that never reaches it scores 0.
     *
     * @param  list<int>  $ranked
     * @param  array<int, int>  $grades
     */
    public static function reciprocalRank(array $ranked, array $grades): ?float
    {
        $top = $grades === [] ? 0 : max($grades);

        if ($top < 1) {
            return null;
        }

        foreach ($ranked as $index => $opening) {
            if (($grades[$opening] ?? 0) >= $top) {
                return 1 / ($index + 1);
            }
        }

        return 0.0;
    }

    /**
     * Normalized discounted cumulative gain of the first $k results, with gain 2^grade - 1 and discount log2(rank + 1).
     * The ideal list is every labelled opening sorted by grade.
     *
     * @param  list<int>  $ranked
     * @param  array<int, int>  $grades
     */
    public static function ndcgAt(array $ranked, array $grades, int $k): ?float
    {
        $ideal = array_values(array_filter($grades, fn (int $grade): bool => $grade >= 1));
        rsort($ideal);
        $idealGain = self::dcg(array_slice($ideal, 0, $k));

        if ($idealGain == 0.0) {
            return null;
        }

        return self::dcg(array_map(fn (int $opening): int => $grades[$opening] ?? 0, array_slice($ranked, 0, $k))) / $idealGain;
    }

    /**
     * The mean of the values and its 95% percentile interval over resamples of the cases, with a fixed seed so a run
     * can be repeated.
     *
     * @param  list<float>  $values
     * @return array{mean: float, low: float, high: float}
     */
    public static function bootstrap(array $values, int $samples, int $seed): array
    {
        return self::resample($values, array_fill(0, count($values), 0.0), $samples, $seed);
    }

    /**
     * The mean difference between two variants on the same cases, and its 95% interval. The cases are resampled in
     * pairs, so a case that is hard for both variants does not widen the interval. An interval that excludes 0 means
     * the difference is unlikely to be noise.
     *
     * @param  list<float>  $values
     * @param  list<float>  $baseline
     * @return array{mean: float, low: float, high: float}
     */
    public static function pairedBootstrap(array $values, array $baseline, int $samples, int $seed): array
    {
        return self::resample($values, $baseline, $samples, $seed);
    }

    /**
     * @param  list<float>  $values
     * @param  list<float>  $baseline
     * @return array{mean: float, low: float, high: float}
     */
    private static function resample(array $values, array $baseline, int $samples, int $seed): array
    {
        $count = count($values);

        if ($count === 0 || $count !== count($baseline)) {
            return ['mean' => 0.0, 'low' => 0.0, 'high' => 0.0];
        }

        $differences = array_map(fn (float $value, float $base): float => $value - $base, $values, $baseline);
        mt_srand($seed);
        $means = [];

        for ($sample = 0; $sample < $samples; $sample++) {
            $sum = 0.0;

            for ($draw = 0; $draw < $count; $draw++) {
                $sum += $differences[mt_rand(0, $count - 1)];
            }

            $means[] = $sum / $count;
        }

        sort($means);
        mt_srand();

        return [
            'mean' => array_sum($differences) / $count,
            'low' => $means[(int) floor(0.025 * ($samples - 1))],
            'high' => $means[(int) ceil(0.975 * ($samples - 1))],
        ];
    }

    /**
     * @param  list<int>  $grades
     */
    private static function dcg(array $grades): float
    {
        $sum = 0.0;

        foreach ($grades as $index => $grade) {
            $sum += (2 ** $grade - 1) / log($index + 2, 2);
        }

        return $sum;
    }
}
