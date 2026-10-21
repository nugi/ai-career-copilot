<?php

namespace App\Ai;

use App\Enums\AiFeature;
use App\Enums\ModelTier;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;

/**
 * Resolves the tier, the models and the prices of an AI task from config/ai-routing.php.
 *
 * The config is the only place where a model is chosen, so moving a task to another model or another price
 * does not touch the agents. An agent asks for the models of its task, and a usage row asks for the cost.
 */
class ModelRouter
{
    public function __construct(private Repository $config) {}

    /**
     * Get the tier that the task runs on.
     *
     * @throws InvalidArgumentException When the config has no tier for the task.
     */
    public function tier(AiFeature $task): ModelTier
    {
        $tier = $this->config->get("ai-routing.tasks.{$task->value}");

        if (! is_string($tier) || ModelTier::tryFrom($tier) === null) {
            throw new InvalidArgumentException("The AI task [{$task->value}] has no model tier in config/ai-routing.php.");
        }

        return ModelTier::from($tier);
    }

    /**
     * Get the providers of the task's tier in failover order, mapped to their models.
     *
     * @return array<string, string>
     */
    public function models(AiFeature $task): array
    {
        /** @var array<string, string> $models */
        $models = $this->config->get('ai-routing.tiers.'.$this->tier($task)->value, []);

        return $models;
    }

    /**
     * Get the generation options of the model that the task's tier uses for the provider.
     *
     * A provider outside the tier has no options, so a caller does not have to check the tier first.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(AiFeature $task, Lab|string $provider): array
    {
        $provider = $provider instanceof Lab ? $provider->value : $provider;
        $model = $this->models($task)[$provider] ?? null;

        if ($model === null) {
            return [];
        }

        /** @var array<string, mixed> $options */
        $options = $this->pricesAndOptions()[$model]['options'] ?? [];

        return $options;
    }

    /**
     * Get the cost of the tokens in USD, with 6 decimals, or null when the model has no price in the config.
     *
     * The price is linear in the token counts, so the same model always costs the same for the same tokens.
     */
    public function cost(string $model, int $inputTokens, int $outputTokens): ?string
    {
        $price = $this->pricesAndOptions()[$model] ?? [];
        $inputPrice = $price['input_per_million'] ?? null;
        $outputPrice = $price['output_per_million'] ?? null;

        if (! is_numeric($inputPrice) || ! is_numeric($outputPrice)) {
            return null;
        }

        $cost = ($inputTokens * (float) $inputPrice + $outputTokens * (float) $outputPrice) / 1_000_000;

        return number_format($cost, 6, '.', '');
    }

    /**
     * Get the model prices and options, keyed by model name.
     *
     * The whole map is read at once because a model name can contain a dot, which the config repository would read as a path.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pricesAndOptions(): array
    {
        /** @var array<string, array<string, mixed>> $models */
        $models = $this->config->get('ai-routing.models', []);

        return $models;
    }
}
