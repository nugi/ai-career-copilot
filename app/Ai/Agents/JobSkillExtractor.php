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
 * Extract the skills and the experience level that one job posting asks for.
 *
 * The output is not trusted. The ExtractPostingSkills action validates it and checks it against the posting text before it is saved.
 */
#[Timeout(60)]
#[Strict]
class JobSkillExtractor implements Agent, HasPromptVersion, HasProviderOptions, HasStructuredOutput, TracksUsage
{
    use Promptable;

    public const PROMPT_VERSION = 'v1';

    /**
     * The most characters of the description that the model reads. Longer descriptions are cut, so one posting
     * cannot cost much more than the others.
     */
    public const MAX_DESCRIPTION_LENGTH = 12000;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return Instructions::load('job-skill-extractor', self::PROMPT_VERSION);
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
        return AiFeature::SkillExtraction;
    }

    /**
     * Get the user whose request caused the call. No user does, because a source run queues the extraction.
     */
    public function usageUser(): ?User
    {
        return null;
    }

    /**
     * Get the providers in failover order, mapped to their models.
     *
     * The models come from config/ai-routing.php for the agent's task, so a prompt version cannot change model on an SDK update.
     * A provider whose circuit is open moves to the end of the list.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return app(CircuitBreaker::class)->order(app(ModelRouter::class)->models($this->task()));
    }

    /**
     * Get the provider-specific generation options.
     *
     * They come from config/ai-routing.php for the model that the task's tier uses, because each model accepts its own options.
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
            'required_skills' => $schema->array()->items($schema->string())->required(),
            'nice_to_have_skills' => $schema->array()->items($schema->string())->required(),
            'seniority' => $schema->string()->enum(['junior', 'mid', 'senior', 'lead'])->nullable()->required(),
            'years_required' => $schema->integer()->nullable()->required(),
        ];
    }

    /**
     * Build the user message for one job posting.
     *
     * The title and the description are escaped, so they cannot close their block. The description is cut to
     * MAX_DESCRIPTION_LENGTH characters, so one very long posting cannot cost much more than the others.
     *
     * @param  list<string>  $errors  The validation errors of the previous output, with their field paths.
     */
    public function message(string $title, string $description, array $errors = []): string
    {
        $description = mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH);

        $message = "<job_posting>\n<title>{$this->escape($title)}</title>\n<description>\n{$this->escape($description)}\n</description>\n</job_posting>";

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
