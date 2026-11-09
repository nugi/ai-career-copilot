<?php

use App\Evals\Retrieval\RetrievalMetrics;

test('recall at 10 is the share of relevant openings in the first ten results', function () {
    expect(RetrievalMetrics::recallAt([1, 2, 3, 4], [2 => 2, 4 => 1, 9 => 2, 5 => 0], 10))->toBe(2 / 3)
        ->and(RetrievalMetrics::recallAt([1, 2], [2 => 2], 1))->toBe(0.0);
});

test('the reciprocal rank looks for the case top grade', function () {
    expect(RetrievalMetrics::reciprocalRank([1, 4, 2], [2 => 2, 4 => 1]))->toBe(1 / 3)
        ->and(RetrievalMetrics::reciprocalRank([1, 4, 2], [4 => 1]))->toBe(0.5)
        ->and(RetrievalMetrics::reciprocalRank([1, 3], [2 => 2]))->toBe(0.0);
});

test('nDCG at 10 uses gain 2 to the grade minus 1 and the ideal order of every label', function () {
    expect(round(RetrievalMetrics::ndcgAt([1, 2, 3, 4], [2 => 2, 4 => 1, 9 => 2], 10), 6))->toBe(0.430847)
        ->and(RetrievalMetrics::ndcgAt([2, 4], [2 => 2, 4 => 1], 10))->toBe(1.0)
        ->and(round(RetrievalMetrics::ndcgAt([4, 2], [2 => 2, 4 => 1], 10), 6))->toBe(0.796708);
});

test('a case without a relevant label has no score', function (array $grades) {
    expect(RetrievalMetrics::recallAt([1, 2], $grades, 10))->toBeNull()
        ->and(RetrievalMetrics::reciprocalRank([1, 2], $grades))->toBeNull()
        ->and(RetrievalMetrics::ndcgAt([1, 2], $grades, 10))->toBeNull();
})->with(['no labels' => [[]], 'only grade 0' => [[1 => 0, 2 => 0]]]);

test('the bootstrap is repeatable and its interval holds the mean', function () {
    $values = [0.2, 0.4, 0.5, 0.9, 0.7, 0.3];

    $first = RetrievalMetrics::bootstrap($values, 2000, 25);
    $second = RetrievalMetrics::bootstrap($values, 2000, 25);

    expect($first)->toBe($second)
        ->and(round($first['mean'], 6))->toBe(0.5)
        ->and($first['low'])->toBeLessThan(0.5)
        ->and($first['high'])->toBeGreaterThan(0.5);
});

test('a paired bootstrap of a variant that is always better excludes zero', function () {
    $baseline = [0.2, 0.4, 0.5, 0.9, 0.7, 0.3];
    $better = array_map(fn (float $value): float => $value + 0.1, $baseline);

    $difference = RetrievalMetrics::pairedBootstrap($better, $baseline, 2000, 25);

    expect(round($difference['mean'], 6))->toBe(0.1)
        ->and($difference['low'])->toBeGreaterThan(0.0);
});
