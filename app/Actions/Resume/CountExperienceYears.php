<?php

namespace App\Actions\Resume;

use Carbon\CarbonImmutable;

class CountExperienceYears
{
    /**
     * Count the months that the experiences cover, so overlapping jobs count once, and express them in years.
     *
     * A date with only a year counts as July, for a start and for an end. A null end counts as the month of $asOf.
     * ParseResume reads a year alone as January for a start and December for an end. That loose reading is for the
     * validation only, so a correct pair such as 2020-09 to 2020 is not rejected. The two readings differ on purpose.
     *
     * @param  array<mixed>  $experiences
     */
    public function handle(array $experiences, ?CarbonImmutable $asOf = null): ?float
    {
        if ($experiences === []) {
            return null;
        }

        $currentMonth = ($asOf ?? CarbonImmutable::now())->startOfMonth();

        $intervals = array_map(function (array $experience) use ($currentMonth): array {
            $end = $experience['end'] === null
                ? $this->monthNumber($currentMonth)
                : $this->monthNumber($this->monthOf($experience['end']));

            // A year-only start can fall after a month-precise end in the same year. The interval then ends where it starts.
            $start = min($this->monthNumber($this->monthOf($experience['start'])), $end);

            return [$start, $end];
        }, $experiences);

        usort($intervals, fn (array $first, array $second): int => $first[0] <=> $second[0]);

        $merged = [];

        foreach ($intervals as [$start, $end]) {
            $last = array_key_last($merged);

            if ($last !== null && $start <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        $months = array_sum(array_map(fn (array $interval): int => $interval[1] - $interval[0] + 1, $merged));

        return round($months / 12, 1);
    }

    /**
     * Read a date as a month. A year alone counts as July.
     */
    private function monthOf(string $date): CarbonImmutable
    {
        $parts = explode('-', $date);
        $month = isset($parts[1]) ? (int) $parts[1] : 7;

        return CarbonImmutable::createFromDate((int) $parts[0], $month, 1)->startOfMonth();
    }

    /**
     * Give each month one number, so that months can be compared and counted.
     */
    private function monthNumber(CarbonImmutable $month): int
    {
        return $month->year * 12 + $month->month;
    }
}
