<?php

namespace App\Ai\Agents;

use App\Ai\CircuitBreaker;
use App\Ai\Contracts\HasPromptVersion;
use App\Ai\Prompts\Instructions;
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
class ResumeParser implements Agent, HasPromptVersion, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public const PROMPT_VERSION = 'v1';

    /**
     * A year, or a year and a month, such as 2021 or 2021-06.
     */
    public const DATE_PATTERN = '^\d{4}(-(0[1-9]|1[0-2]))?$';

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
     * Get the providers in failover order, mapped to their models.
     *
     * The models are pinned, so an SDK update cannot change the model that a prompt version runs on.
     * A provider whose circuit is open moves to the end of the list.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return app(CircuitBreaker::class)->order([
            Lab::OpenAI->value => 'gpt-6-luna',
            Lab::Anthropic->value => 'claude-haiku-5-5',
        ]);
    }

    /**
     * Get the provider-specific generation options.
     *
     * Parsing does not need extended reasoning, and both models bill it as output tokens.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::OpenAI => ['reasoning' => ['effort' => 'none']],
            Lab::Anthropic => ['thinking' => ['type' => 'disabled']],
            default => [],
        };
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
