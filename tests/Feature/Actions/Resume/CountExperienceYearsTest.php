<?php

use App\Actions\Resume\CountExperienceYears;
use Carbon\CarbonImmutable;

/**
 * An experience with a start and an end.
 *
 * @return array<string, string|null>
 */
function experienceBetween(string $start, ?string $end): array
{
    return ['start' => $start, 'end' => $end];
}

test('overlapping and touching experiences are merged so each month counts once', function () {
    $overlapping = [
        experienceBetween('2018-01', '2020-12'),
        experienceBetween('2020-06', '2022-12'),
    ];
    $touching = [
        experienceBetween('2018-01', '2019-12'),
        experienceBetween('2020-01', '2021-12'),
    ];

    expect(app(CountExperienceYears::class)->handle($overlapping))->toBe(5.0)
        ->and(app(CountExperienceYears::class)->handle($touching))->toBe(4.0);
});

test('a year alone counts as July, for a start and for an end', function () {
    expect(app(CountExperienceYears::class)->handle([experienceBetween('2018', '2020')]))->toBe(2.1);
});

test('a null end counts up to the given date', function () {
    $asOf = CarbonImmutable::parse('2026-10-20 09:00:00');

    expect(app(CountExperienceYears::class)->handle([experienceBetween('2019-01', null)], $asOf))->toBe(7.8);
});

test('a year-only start after a month-precise end in the same year gives one month, not a negative interval', function () {
    expect(app(CountExperienceYears::class)->handle([experienceBetween('2020', '2020-03')]))->toBe(0.1);
});

test('no experiences give null', function () {
    expect(app(CountExperienceYears::class)->handle([]))->toBeNull();
});
