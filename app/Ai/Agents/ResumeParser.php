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
 * Extract the structured data of one resume.
 *
 * The output is not trusted. The ParseResume action validates it and checks it against the resume text before it is saved.
 */
#[Timeout(90)]
#[Strict]
class ResumeParser implements Agent, HasPromptVersion, HasProviderOptions, HasStructuredOutput, TracksUsage
{
    use Promptable;

    public const PROMPT_VERSION = 'v1';

    /**
     * A year, or a year and a month, such as 2021 or 2021-06.
     */
    public const DATE_PATTERN = '^\d{4}(-(0[1-9]|1[0-2]))?$';

    /**
     * The user whose resume is parsed, when there is one. It is only used for the usage row.
     */
    public function __construct(public ?User $user = null) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return Instructions::load('resume-parser', self::PROMPT_VERSION);
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
        return AiFeature::ResumeParsing;
    }

    /**
     * Get the user whose resume is parsed, when there is one.
     */
    public function usageUser(): ?User
    {
        return $this->user;
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
            'summary' => $schema->string()->nullable()->required(),
            'skills' => $schema->array()->items($schema->string())->required(),
            'years_of_experience' => $schema->number()->nullable()->required(),
            'experiences' => $schema->array()->items($schema->object([
                'title' => $schema->string()->required(),
                'company' => $schema->string()->required(),
                'start' => $schema->string()->pattern(self::DATE_PATTERN)->required(),
                'end' => $schema->string()->pattern(self::DATE_PATTERN)->nullable()->required(),
                'bullets' => $schema->array()->items($schema->string())->required(),
            ]))->required(),
            'education' => $schema->array()->items($schema->object([
                'institution' => $schema->string()->required(),
                'degree' => $schema->string()->nullable()->required(),
                'field' => $schema->string()->nullable()->required(),
                'start' => $schema->string()->pattern(self::DATE_PATTERN)->nullable()->required(),
                'end' => $schema->string()->pattern(self::DATE_PATTERN)->nullable()->required(),
            ]))->required(),
        ];
    }

    /**
     * Build the user message for one resume.
     *
     * The resume text and the validation errors are escaped, so they cannot close their block.
     *
     * @param  list<string>  $errors  The validation errors of the previous output, with their field paths.
     */
    public function message(string $content, array $errors = []): string
    {
        $message = "<resume>\n{$this->escape($content)}\n</resume>";

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
