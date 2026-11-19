<?php

namespace App\Ai\Agents;

use App\Actions\Skills\SkillGap;
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
 * Write a four-week learning plan for the user's highest-priority skill gaps.
 *
 * The output is not trusted. The PlanLearning action validates it and keeps only skills from the gaps that were sent.
 */
#[Timeout(30)]
#[Strict]
class LearningPlanner implements Agent, HasPromptVersion, HasProviderOptions, HasStructuredOutput, TracksUsage
{
    use Promptable;

    public const PROMPT_VERSION = 'v1';

    public function __construct(public User $user) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return Instructions::load('learning-planner', self::PROMPT_VERSION);
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
        return AiFeature::SkillGapPlan;
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
            'summary' => $schema->string()->required(),
            'weeks' => $schema->array()->items($schema->object([
                'week' => $schema->integer()->required(),
                'goal' => $schema->string()->required(),
                'skills' => $schema->array()->items($schema->string())->required(),
                'tasks' => $schema->array()->items($schema->string())->required(),
            ]))->required(),
            'project' => $schema->object([
                'title' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'skills' => $schema->array()->items($schema->string())->required(),
            ])->required(),
            'showcase' => $schema->object([
                'resume_bullet' => $schema->string()->required(),
                'linkedin' => $schema->string()->required(),
            ])->required(),
        ];
    }

    /**
     * Build the user message: target roles, current skills, then the gaps in priority order.
     *
     * Every text is escaped, so it cannot close its block.
     *
     * @param  list<SkillGap>  $gaps
     * @param  list<string>  $targetRoles
     * @param  list<string>  $userSkills
     * @param  list<string>  $errors  The validation errors of the previous output, with their field paths.
     */
    public function message(array $gaps, array $targetRoles, array $userSkills, array $errors = []): string
    {
        $roles = array_map(fn (string $role): string => '- '.$this->escape($role), $targetRoles);
        $skills = array_map(fn (string $skill): string => '- '.$this->escape($skill), $userSkills);
        $lines = array_map(fn (SkillGap $gap): string => '- '.$this->escape($gap->skill)
            .": required in {$gap->requiredJobs} of {$gap->of} top matches; nice-to-have in {$gap->niceJobs}", $gaps);

        $message = "<target_roles>\n".implode("\n", $roles)."\n</target_roles>\n\n"
            ."<current_skills>\n".implode("\n", $skills)."\n</current_skills>\n\n"
            ."<skill_gaps>\n".implode("\n", $lines)."\n</skill_gaps>";

        if ($errors === []) {
            return $message;
        }

        $errors = array_map(fn (string $error): string => '- '.$this->escape($error), $errors);

        return $message."\n\n<validation_errors>\n".implode("\n", $errors)."\n</validation_errors>";
    }

    /**
     * Escape the angle brackets of text that goes into a block.
     */
    private function escape(string $value): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $value);
    }
}
