<?php

namespace App\Console\Commands;

use App\JobSources\Contracts\JobSource;
use App\JobSources\Exceptions\JobSourceUnavailable;
use App\JobSources\JobPostingData;
use App\JobSources\JobSourceRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Samples source searches daily, reporting hard failures and repeated low field shares.
 */
#[Signature('jobs:check-source {source? : The source key; checks every registered source when omitted}')]
#[Description('Sample job source searches and fail on hard problems or repeated low field shares')]
class CheckJobSourceCommand extends Command
{
    /**
     * Sample each chosen source and fail on hard problems or fields low in two consecutive runs.
     *
     * The command prints counts and field names only. It never prints a posting, a job description or the API key.
     */
    public function handle(JobSourceRegistry $registry): int
    {
        $sources = $this->sources($registry);

        if ($sources === null) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($sources as $source) {
            if (! $this->check($source)) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Get the sources to check, or null when the named source is not registered.
     *
     * @return list<JobSource>|null
     */
    private function sources(JobSourceRegistry $registry): ?array
    {
        $key = $this->argument('source');

        if ($key === null) {
            return array_values($registry->all());
        }

        if (! array_key_exists($key, $registry->all())) {
            $this->error("Unknown job source [{$key}].");

            return null;
        }

        return [$registry->get($key)];
    }

    /**
     * Read one page per sampled query and report field shares, first misses and failures.
     */
    private function check(JobSource $source): bool
    {
        $key = $source->key();

        config([
            'job-sources.max_queries' => config()->integer('job-sources.check_pages'),
            "job-sources.{$key}.pages_per_query" => 1,
        ]);

        /** @var list<JobPostingData> $postings */
        $postings = [];

        try {
            $generator = $source->fetch();

            foreach ($generator as $posting) {
                $postings[] = $posting;
            }

            $report = $generator->getReturn();
        } catch (JobSourceUnavailable $exception) {
            Log::error('jobs.source.unavailable', [
                'source' => $key,
                'message' => $exception->getMessage(),
            ]);

            $this->error("{$key}: {$exception->getMessage()}");

            return false;
        }

        $problems = $this->problems($key, $postings, $report->yielded, $report->skipped, $report->droppedFields);

        if ($problems === []) {
            $this->info("{$key}: {$report->yielded} postings, fields look normal.");

            return true;
        }

        Log::error('jobs.source.schema_drift', [
            'source' => $key,
            'problems' => $problems,
            'yielded' => $report->yielded,
            'skipped' => $report->skipped,
            'dropped_fields' => $report->droppedFields,
        ]);

        foreach ($problems as $problem) {
            $this->error("{$key}: {$problem}");
        }

        return false;
    }

    /**
     * Get hard problems and repeated low fields, caching this run's low fields and logging its shares.
     *
     * @param  list<JobPostingData>  $postings
     * @return list<string>
     */
    private function problems(string $key, array $postings, int $yielded, int $skipped, int $droppedFields): array
    {
        $problems = [];
        $read = $yielded + $skipped;

        if ($postings === [] || $read === 0) {
            $problems[] = 'no postings';
        }

        if ($read > 0 && $skipped / $read > 0.1) {
            $problems[] = "{$skipped} of {$read} postings skipped";
        }

        if ($droppedFields > 0.1 * $yielded) {
            $problems[] = "{$droppedFields} fields dropped";
        }

        $cacheKey = "jobs:check-source:{$key}:low-fields";

        /** @var list<string> $previousLowFields */
        $previousLowFields = Cache::get($cacheKey, []);
        $lowFields = [];
        $suspectedFields = [];
        $shares = [];

        if ($postings !== []) {
            foreach ($this->expectedFields($key) as $field => $minimum) {
                $share = $this->shareSet($postings, $field);
                $shares[$field] = round($share, 2);

                if ($share >= $minimum) {
                    continue;
                }

                $lowFields[] = $field;
                $message = sprintf(
                    '%s set on %d%% of postings, expected at least %d%%',
                    $field,
                    (int) round($share * 100),
                    (int) round($minimum * 100),
                );

                if (in_array($field, $previousLowFields, true)) {
                    $problems[] = $message;
                } else {
                    $this->warn("{$key}: {$message} (first miss)");
                    $suspectedFields[$field] = $share;
                }
            }

            Log::info('jobs.source.field_shares', [
                'source' => $key,
                'postings' => count($postings),
                'shares' => $shares,
            ]);
        }

        Cache::put($cacheKey, $lowFields, now()->addDays(3));

        if ($suspectedFields !== []) {
            Log::warning('jobs.source.schema_drift_suspected', [
                'source' => $key,
                'fields' => $suspectedFields,
            ]);
        }

        return $problems;
    }

    /**
     * Get the share of the postings that each field must be set on.
     *
     * @return array<string, float>
     */
    private function expectedFields(string $key): array
    {
        $fields = [];

        $configured = config("job-sources.{$key}.expected_fields", []);

        if (! is_array($configured)) {
            return $fields;
        }

        foreach ($configured as $field => $minimum) {
            if (is_string($field) && (is_int($minimum) || is_float($minimum))) {
                $fields[$field] = (float) $minimum;
            }
        }

        return $fields;
    }

    /**
     * Get the share of the postings that have the given field filled in.
     *
     * @param  list<JobPostingData>  $postings
     */
    private function shareSet(array $postings, string $field): float
    {
        if ($postings === []) {
            return 0.0;
        }

        $set = 0;

        foreach ($postings as $posting) {
            $value = get_object_vars($posting)[$field] ?? null;

            if ($value !== null && $value !== '') {
                $set++;
            }
        }

        return $set / count($postings);
    }
}
