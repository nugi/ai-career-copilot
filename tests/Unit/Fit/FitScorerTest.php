<?php

use App\Actions\Fit\FitInput;
use App\Actions\Fit\FitScore;
use App\Actions\Fit\FitScorer;

/**
 * @param  array<string, mixed>  $overrides
 */
function scoringInput(array $overrides = []): FitInput
{
    return new FitInput(...[
        'requiredSkills' => ['PHP', 'Laravel'], 'niceSkills' => ['PostgreSQL'],
        'userSkills' => ['PHP', 'Laravel', 'PostgreSQL'], 'yearsOfExperience' => 5.0,
        'yearsRequired' => 5, 'seniority' => 'senior', 'salaryFloor' => 100_000,
        'salaryMax' => 100_000, 'arrangementMatches' => true, 'eligibility' => 'open',
        ...$overrides,
    ]);
}

function pureFitScore(FitInput $input): FitScore
{
    $config = require __DIR__.'/../../../config/fit-scoring.php';

    return (new FitScorer)->score($input, $config['weights'], $config, $config['version']);
}

test('the same input always gives the same score', function (): void {
    $input = scoringInput();

    expect(pureFitScore($input)->toArray())->toBe(pureFitScore($input)->toArray());
});

test('a perfect match scores 100', function (): void {
    expect(pureFitScore(scoringInput())->score)->toBe(100);
});

test('an unknown factor is left out and its weight is shared', function (): void {
    $score = pureFitScore(scoringInput(['salaryMax' => null, 'yearsOfExperience' => null, 'userSkills' => ['PHP']]));

    expect($score->score)->toBe(54)
        ->and($score->breakdown['salary']['value'])->toBeNull()
        ->and($score->breakdown['seniority']['value'])->toBeNull();
});

test('required skills are matched without case or spaces', function (): void {
    $score = pureFitScore(scoringInput(['userSkills' => [' php ', 'LARAVEL', 'postgresql']]));

    expect($score->matchedSkills)->toBe(['PHP', 'Laravel', 'PostgreSQL'])
        ->and($score->missingSkills)->toBe([])
        ->and($score->score)->toBe(100);
});

test('seniority uses years required before the level', function (): void {
    $score = pureFitScore(scoringInput(['yearsOfExperience' => 2.0, 'yearsRequired' => 4, 'seniority' => 'lead']));

    expect($score->breakdown['seniority'])->toBe([
        'value' => 0.5, 'weight' => 20, 'detail' => ['years' => 2.0, 'needed' => 4, 'from' => 'years_required'],
    ]);
});

test('salary is linear between 80% of the floor and the floor', function (int $maximum, float $value): void {
    expect(pureFitScore(scoringInput(['salaryMax' => $maximum]))->breakdown['salary']['value'])->toBe($value);
})->with([[79_000, 0.0], [80_000, 0.0], [90_000, 0.5], [100_000, 1.0]]);

test('a wrong arrangement scores location 0', function (): void {
    expect(pureFitScore(scoringInput(['arrangementMatches' => false]))->breakdown['location']['value'])->toBe(0.0);
});

test('the breakdown explains the score', function (): void {
    $score = pureFitScore(scoringInput(['userSkills' => ['PHP'], 'yearsOfExperience' => 1.6666, 'salaryMax' => 91_234]));
    $weighted = 0;
    $knownWeight = 0;

    foreach ($score->breakdown as $factor) {
        if ($factor['value'] !== null) {
            $weighted += $factor['value'] * $factor['weight'];
            $knownWeight += $factor['weight'];
        }
    }

    expect($score->score)->toBe((int) round(100 * $weighted / $knownWeight))
        ->and($score->missingSkills)->toBe(['Laravel'])
        ->and($score->breakdown['seniority']['value'])->toBe(0.333);
});
