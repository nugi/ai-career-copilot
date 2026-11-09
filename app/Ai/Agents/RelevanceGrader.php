<?php

namespace App\Ai\Agents;

use App\Ai\CircuitBreaker;
use App\Ai\Contracts\HasPromptVersion;
use App\Ai\Contracts\TracksUsage;
use App\Ai\ModelRouter;
use App\Ai\Prompts\Instructions;
use App\Enums\AiFeature;
use App\Models\JobPosting;
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
 * Grade the relevance of shuffled postings to a golden profile (ADR 0035).
 */
#[Timeout(120)]
#[Strict]
class RelevanceGrader implements Agent, HasPromptVersion, HasProviderOptions, HasStructuredOutput, TracksUsage
{
    use Promptable;

    public const PROMPT_VERSION = 'v4';

    public const PROFILE_FIELDS = ['engineering', 'design'];

    public const LEVELS = ['intern', 'junior', 'mid', 'senior', 'lead'];

    public const MAIN_WORK = [
        'backend', 'frontend', 'full_stack', 'mobile', 'data', 'machine_learning',
        'devops', 'security', 'testing', 'engineering_management', 'design', 'other',
    ];

    public function instructions(): Stringable|string
    {
        return Instructions::load('relevance-grader', self::PROMPT_VERSION);
    }

    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    public function task(): AiFeature
    {
        return AiFeature::RelevanceGrading;
    }

    public function usageUser(): ?User
    {
        return null;
    }

    /**
     * Prefer Anthropic: embeddings use OpenAI and reranking uses Cohere, so the grader comes from a different model family.
     * Providers with an open circuit still move to the end.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        $models = app(ModelRouter::class)->models($this->task());
        $preferred = isset($models['anthropic']) ? ['anthropic' => $models['anthropic']] : [];

        return app(CircuitBreaker::class)->order($preferred + $models);
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return app(ModelRouter::class)->providerOptions($this->task(), $provider);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'profile_field' => $schema->string()->enum(self::PROFILE_FIELDS)->required(),
            'profile_level' => $schema->string()->enum(self::LEVELS)->required(),
            'postings' => $schema->array()->items($schema->object([
                'posting_id' => $schema->integer()->required(),
                'reason' => $schema->string()->required(),
                'main_work' => $schema->string()->enum(self::MAIN_WORK)->required(),
                'posting_level' => $schema->string()->enum(self::LEVELS)->required(),
                'matches_target_role' => $schema->boolean()->required(),
            ]))->required(),
        ];
    }

    /**
     * Compute the relevance grade from the grader's facts (ADR 0035).
     */
    public static function grade(string $profileField, string $profileLevel, string $mainWork, string $postingLevel, bool $matchesTargetRole): int
    {
        if (abs(array_search($profileLevel, self::LEVELS, true) - array_search($postingLevel, self::LEVELS, true)) >= 2) {
            return 0;
        }

        if ($profileField === 'engineering' && in_array($mainWork, ['design', 'other'], true)) {
            return 0;
        }

        if ($profileField === 'design') {
            return match ($mainWork) {
                'design' => 2,
                'frontend' => 1,
                default => 0,
            };
        }

        return $matchesTargetRole ? 2 : 1;
    }

    /**
     * @param  array{target_roles: list<string>, skills: array<string, string>}  $profile
     * @param  array<int, JobPosting>  $postings  Postings keyed by their shuffled, one-based numbers.
     */
    public function message(array $profile, array $postings): string
    {
        $roles = implode(', ', array_map($this->escape(...), $profile['target_roles']));
        $skills = [];

        foreach ($profile['skills'] as $name => $proficiency) {
            $skills[] = $this->escape($name).': '.$this->escape($proficiency);
        }

        $blocks = [];

        foreach ($postings as $number => $posting) {
            $postingSkills = implode(', ', array_map($this->escape(...), $posting->skills->pluck('name')->all()));
            $description = $this->escape(mb_substr($posting->description, 0, 2000));
            $blocks[] = "<posting id=\"{$number}\">\n<title>{$this->escape($posting->title)}</title>\n<company>{$this->escape($posting->company_name)}</company>\n<skills>{$postingSkills}</skills>\n<description>{$description}</description>\n</posting>";
        }

        return "<profile>\n<target_roles>{$roles}</target_roles>\n<skills>".implode("\n", $skills)."</skills>\n</profile>\n<postings>\n".implode("\n", $blocks)."\n</postings>";
    }

    /**
     * Escape the angle brackets of text that goes into a block.
     */
    private function escape(string $value): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $value);
    }
}
