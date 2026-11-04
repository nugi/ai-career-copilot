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
 * Explain why one job posting fits the user, citing numbered excerpts of the user's resume (ADR 0034).
 *
 * The output is not trusted. The ExplainFit action validates it, drops citations of excerpts that were not sent, and
 * drops every reason that cites nothing.
 */
#[Timeout(30)]
#[Strict]
class FitExplainer implements Agent, HasPromptVersion, HasProviderOptions, HasStructuredOutput, TracksUsage
{
    use Promptable;

    public const PROMPT_VERSION = 'v1';

    public function __construct(public User $user) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return Instructions::load('fit-explainer', self::PROMPT_VERSION);
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
     * Get the user whose request caused the call, so the call counts in that user's daily budget.
     */
    public function usageUser(): ?User
    {
        return $this->user;
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
     * Get the structured output schema. Strict mode requires every property to be required.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'verdict' => $schema->string()->enum(['strong_fit', 'partial_fit', 'weak_fit'])->required(),
            'summary' => $schema->string()->required(),
            'reasons' => $schema->array()->items($schema->object([
                'claim' => $schema->string()->required(),
                'excerpt_ids' => $schema->array()->items($schema->integer())->required(),
            ]))->required(),
            'missing_requirements' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /**
     * Build the user message: the posting, then the excerpts numbered from 1.
     *
     * Every text is escaped, so it cannot close its block.
     *
     * @param  list<string>  $excerpts
     * @param  list<string>  $errors  The validation errors of the previous output, with their field paths.
     */
    public function message(string $title, string $company, string $skills, string $description, array $excerpts, array $errors = []): string
    {
        $blocks = [];

        foreach ($excerpts as $index => $excerpt) {
            $number = $index + 1;
            $blocks[] = "<excerpt id=\"{$number}\">\n{$this->escape($excerpt)}\n</excerpt>";
        }

        $message = "<job_posting>\n<title>{$this->escape($title)}</title>\n<company>{$this->escape($company)}</company>\n"
            ."<skills>{$this->escape($skills)}</skills>\n<description>\n{$this->escape($description)}\n</description>\n</job_posting>\n\n"
            ."<resume_excerpts>\n".implode("\n", $blocks)."\n</resume_excerpts>";

        if ($errors === []) {
            return $message;
        }

        $lines = array_map(fn (string $error): string => '- '.$this->escape($error), $errors);

        return $message."\n\n<validation_errors>\n".implode("\n", $lines)."\n</validation_errors>";
    }

    /**
     * Escape the angle brackets of text that goes into a block.
     */
    private function escape(string $value): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $value);
    }
}
