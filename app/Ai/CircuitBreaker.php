<?php

namespace App\Ai;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

/**
 * Sends traffic away from an AI provider that keeps failing, so each request does not wait for its timeout first.
 *
 * A provider's circuit opens after THRESHOLD failures in a row within WINDOW_SECONDS and stays open for COOLDOWN_SECONDS.
 * After the cooldown the provider is tried again: one more failure opens the circuit at once, and one success closes it.
 */
class CircuitBreaker
{
    public const THRESHOLD = 3;

    public const WINDOW_SECONDS = 60;

    public const COOLDOWN_SECONDS = 120;

    /**
     * How long a provider whose circuit opened stays on probation after the cooldown.
     */
    public const PROBATION_SECONDS = 3600;

    public function __construct(private Repository $cache) {}

    /**
     * Order the providers so that those with an open circuit come last.
     *
     * An open provider is moved, not removed, so a request still has every provider to try, even when every circuit is open.
     *
     * @param  array<string, string>  $models  The provider names in failover order, mapped to their models.
     * @return array<string, string>
     */
    public function order(array $models): array
    {
        $open = array_filter($models, fn (string $model, string $provider): bool => $this->isOpen($provider), ARRAY_FILTER_USE_BOTH);

        return array_diff_key($models, $open) + $open;
    }

    /**
     * Determine whether traffic is being kept away from the provider.
     */
    public function isOpen(string $provider): bool
    {
        return $this->cache->has($this->key($provider, 'open'));
    }

    /**
     * Count a failure of the provider, and open its circuit when it fails too often.
     */
    public function recordFailure(string $provider): void
    {
        if ($this->isOpen($provider)) {
            return;
        }

        $failures = $this->key($provider, 'failures');
        $this->cache->add($failures, 0, self::WINDOW_SECONDS);
        $count = (int) $this->cache->increment($failures);

        if ($count >= self::THRESHOLD || $this->cache->has($this->key($provider, 'probation'))) {
            $this->open($provider, $count);
        }
    }

    /**
     * Close the provider's circuit after it answered.
     */
    public function recordSuccess(string $provider): void
    {
        $this->cache->forget($this->key($provider, 'failures'));

        if ($this->cache->pull($this->key($provider, 'probation')) !== null) {
            Log::info('ai.circuit.closed', ['provider' => $provider]);
        }
    }

    /**
     * Keep traffic away from the provider for the cooldown.
     */
    private function open(string $provider, int $failures): void
    {
        $this->cache->put($this->key($provider, 'open'), true, self::COOLDOWN_SECONDS);
        $this->cache->put($this->key($provider, 'probation'), true, self::COOLDOWN_SECONDS + self::PROBATION_SECONDS);
        $this->cache->forget($this->key($provider, 'failures'));

        Log::warning('ai.circuit.opened', [
            'provider' => $provider,
            'failures' => $failures,
            'cooldown_seconds' => self::COOLDOWN_SECONDS,
        ]);
    }

    private function key(string $provider, string $name): string
    {
        return "ai.circuit.{$provider}.{$name}";
    }
}
