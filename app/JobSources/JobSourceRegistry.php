<?php

namespace App\JobSources;

use App\JobSources\Contracts\JobSource;
use Illuminate\Container\Attributes\Tag;
use InvalidArgumentException;
use LogicException;

/**
 * The job sources that the app is configured with, found by their key.
 *
 * The container hands over every class that AppServiceProvider tags with "job-sources", so a caller asks the registry
 * for a source instead of resolving the class itself.
 */
final class JobSourceRegistry
{
    /**
     * @var array<string, JobSource>
     */
    private array $sources = [];

    /**
     * @param  iterable<JobSource>  $sources
     */
    public function __construct(#[Tag('job-sources')] iterable $sources)
    {
        foreach ($sources as $source) {
            $key = $source->key();

            if (isset($this->sources[$key])) {
                throw new LogicException("Two job sources use the key [{$key}].");
            }

            $this->sources[$key] = $source;
        }
    }

    /**
     * Get every source, keyed by its own key.
     *
     * @return array<string, JobSource>
     */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * Get one source by its key.
     */
    public function get(string $key): JobSource
    {
        if (! isset($this->sources[$key])) {
            throw new InvalidArgumentException("Unknown job source [{$key}].");
        }

        return $this->sources[$key];
    }
}
