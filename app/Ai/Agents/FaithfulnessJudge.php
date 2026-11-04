<?php

namespace App\Ai\Agents;

use App\Ai\CircuitBreaker;
use App\Ai\Contracts\HasPromptVersion;
use App\Ai\Contracts\TracksUsage;
use App\Ai\ModelRouter;
use App\Ai\Prompts\Instructions;
use App\Enums\AiFeature;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Check whether each claim of a fit explanation is supported by the excerpts it cites (ADR 0034).
 *
 * It runs on a sample of new explanations, after the answer was shown, so the user never waits for it and never pays
 * for it: the call has no user and counts only in the global and batch sums.
 */
#[Timeout(60)]
#[Strict]
class FaithfulnessJudge implements Agent, HasPromptVersion, HasProviderOptions, HasStructuredOutput, TracksUsage
{
    use Promptable;

    public const PROMPT_VERSION = 'v1';

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return Instructions::load('faithfulness-judge', self::PROMPT_VERSION);
    }

    /**
     * Get the version of the prompt file that the instructions come from.
     */
    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    /**
     * Get the task that the agent does, which decides its model tier and its usage feature.
     */
    public function task(): AiFeature
    {
        return AiFeature::FitExplanation;
    }

    /**
     * Get the user whose request caused the call. None: the check is the app's own quality measure.
     */
    public function usageUser(): ?User
    {
        return null;
    }

    /**
     * Get the providers in failover order, mapped to their models. A provider whose circuit is open moves to the end of the list.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return app(CircuitBreaker::class)->order(app(ModelRouter::class)->models($this->task()));
    }

    /**
     * Get the provider-specific generation options for the model that the task's tier uses.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return app(ModelRouter::class)->providerOptions($this->task(), $provider);
    }

    /**
     * Get the structured output schema: one verdict per claim, in the order of the claims.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'claims' => $schema->array()->items($schema->object([
                'claim_id' => $schema->integer()->required(),
                'supported' => $schema->boolean()->required(),
            ]))->required(),
        ];
    }

    /**
     * Build the user message: each claim with the full text of the excerpts that it cites.
     *
     * @param  list<array{claim: string, excerpts: list<string>}>  $claims
     */
    public function message(array $claims): string
    {
        $blocks = [];

        foreach ($claims as $index => $claim) {
            $number = $index + 1;
            $excerpts = implode("\n", array_map(fn (string $excerpt): string => "<excerpt>\n{$this->escape($excerpt)}\n</excerpt>", $claim['excerpts']));
            $blocks[] = "<claim id=\"{$number}\">\n<text>{$this->escape($claim['claim'])}</text>\n{$excerpts}\n</claim>";
        }

        return "<claims>\n".implode("\n", $blocks)."\n</claims>";
    }

    /**
     * Escape the angle brackets of text that goes into a block.
     */
    private function escape(string $value): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $value);
    }
}
