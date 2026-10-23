<?php

namespace App\JobSources;

use App\JobSources\Exceptions\JobSourceRateLimited;
use App\JobSources\Exceptions\JobSourceUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends the requests of one job source, with one shared policy for timeouts, retries and failures.
 *
 * It names the app in its User-Agent, waits 10 seconds for an answer and 5 seconds for a connection, and tries a
 * request at most 3 times. A connection error or a 5xx backs off with jitter, a 429 waits for Retry-After when that is
 * 30 seconds or less, and any other 4xx fails at once. A 429 with a longer wait becomes JobSourceRateLimited, and every
 * other failure becomes JobSourceUnavailable. See ADR 0016 "Fetch job sources with bounded retries and dated expiry".
 */
final class SourceHttpClient
{
    public const MAX_TRIES = 3;

    public const MAX_RETRY_AFTER_SECONDS = 30;

    /**
     * The 10-second timeout plus the 5-second connect timeout.
     */
    public const ATTEMPT_SECONDS = 15;

    private int $failures = 0;

    private int $retryDelay = 0;

    /**
     * Configure the source connection and optional run deadline.
     */
    public function __construct(
        private string $source,
        private string $baseUrl,
        private ?string $token = null,
        private ?RunDeadline $deadline = null,
    ) {}

    /**
     * Get the decoded JSON body of the given path, after the retry and failure policy has run.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<array-key, mixed>
     *
     * @throws JobSourceUnavailable
     * @throws JobSourceRateLimited
     */
    public function getJson(string $path, array $query = []): array
    {
        $this->failures = 0;

        $request = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withUserAgent(config()->string('job-sources.user_agent'))
            ->timeout(10)
            ->connectTimeout(5)
            ->retry(
                self::MAX_TRIES,
                fn (int $attempt, Throwable $exception): int => $this->retryDelay,
                fn (Throwable $exception): bool => $this->shouldRetry($exception),
            );

        if ($this->token !== null && trim($this->token) !== '') {
            $request->withToken($this->token);
        }

        $query = array_filter($query, fn (mixed $value): bool => $value !== null);

        try {
            $response = $request->get($path, $query);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 429) {
                throw new JobSourceRateLimited(
                    $this->source,
                    $this->retryAfterSeconds($exception->response) ?? 60,
                    $exception,
                );
            }

            throw new JobSourceUnavailable(
                $this->source,
                'the source answered with '.$exception->response->status(),
                $exception->response->status(),
                $exception,
            );
        } catch (ConnectionException $exception) {
            throw new JobSourceUnavailable($this->source, 'the connection failed', null, $exception);
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new JobSourceUnavailable($this->source, 'the response is not a JSON object');
        }

        return $data;
    }

    /**
     * Determine whether the failed request is worth another try.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        $this->failures++;
        $this->retryDelay = $this->delay($this->failures, $exception);

        if ($this->deadline !== null && ! $this->deadline->allows($this->retryDelay / 1000 + self::ATTEMPT_SECONDS)) {
            return false;
        }

        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        if ($status === 429) {
            $retryAfter = $this->retryAfterSeconds($exception->response);

            return $retryAfter === null || $retryAfter <= self::MAX_RETRY_AFTER_SECONDS;
        }

        return $status >= 500;
    }

    /**
     * Get the wait in milliseconds before the next try.
     */
    private function delay(int $attempt, Throwable $exception): int
    {
        if ($exception instanceof RequestException && $exception->response->status() === 429) {
            $retryAfter = $this->retryAfterSeconds($exception->response);

            if ($retryAfter !== null) {
                return $retryAfter * 1000;
            }
        }

        return (int) (1000 * 2 ** ($attempt - 1)) + random_int(0, 250);
    }

    /**
     * Get the seconds that the source asked the caller to wait, or null when it asked for no particular wait.
     */
    private function retryAfterSeconds(Response $response): ?int
    {
        $value = trim($response->header('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - CarbonImmutable::now()->getTimestamp());
    }
}
