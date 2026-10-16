<?php

namespace App\Ai\Agents;

use App\Ai\Contracts\HasPromptVersion;
use App\Ai\Prompts\Instructions;
use App\Enums\WorkArrangement;
use App\Models\Skill;
use App\Models\User;
use App\Models\UserSkill;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider([
    Lab::OpenAI->value => 'gpt-6-luna',
    Lab::Anthropic->value => 'claude-haiku-5-5',
])]
#[Timeout(30)]
class CareerAdvisor implements Agent, HasPromptVersion
{
    use Promptable;

    public const PROMPT_VERSION = 'v1';

    public function __construct(public User $user) {}

    /**
     * Get the instructions that the agent should follow.
     *
     * The fixed prompt comes first, so providers can cache it across users.
     */
    public function instructions(): Stringable|string
    {
        return Instructions::load('career-advisor', self::PROMPT_VERSION)."\n".$this->aboutUser();
    }

    /**
     * Get the version of the prompt file that the instructions come from.
     */
    public function promptVersion(): string
    {
        return self::PROMPT_VERSION;
    }

    /**
     * Describe the user's job preferences and skills.
     *
     * Only the fields the advice depends on are sent to the providers. The headline
     * and the salary stay out: the headline is free text that can name a person or
     * an employer, and the salary is the most sensitive preference.
     */
    private function aboutUser(): string
    {
        $this->user->loadMissing(['profile', 'skills']);
        $profile = $this->user->profile;

        $skills = $this->user->skills
            ->sortBy('name')
            ->map(function (Skill $skill): string {
                /** @var UserSkill $pivot */
                $pivot = $skill->getRelation('pivot');

                return "{$skill->name} ({$pivot->proficiency->label()})";
            });

        $lines = [
            'Target roles' => $profile->target_roles ?? [],
            'Preferred locations' => $profile->preferred_locations ?? [],
            'Work arrangements' => $profile?->work_arrangements->map(fn (WorkArrangement $arrangement): string => $arrangement->label())->all() ?? [],
            'Skills' => $skills->all(),
        ];

        return collect($lines)
            ->map(fn (array $values, string $label): string => "- {$label}: ".($values === [] ? 'not set' : implode(', ', $values)))
            ->prepend("## About this user\n")
            ->implode("\n")."\n";
    }
}
