<?php

namespace App\Ai\Middleware;

use App\Enums\WorkArrangement;
use App\Models\Skill;
use App\Models\User;
use App\Models\UserSkill;
use Closure;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\PendingStep;
use RuntimeException;

/**
 * Put the user's job preferences and skills in front of their question.
 *
 * The profile is text the user typed, so it goes in the user's message and not in the instructions,
 * which the model trusts more. Only the fields the advice depends on are sent. The headline and the
 * salary stay out: the headline is free text that can name a person or an employer, and the salary
 * is the most sensitive preference. User-typed text has `<` and `>` escaped and profile values are
 * kept on one line. This stops a user from closing a block or adding a false profile line.
 */
class AttachUserProfile
{
    public function __construct(private User $user) {}

    /**
     * Handle the pending generation step.
     *
     * @param  Closure(PendingStep): StepResult  $next
     */
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        if (! $step->isFirstStep()) {
            return $next($step);
        }

        $messages = $step->messages;
        $last = array_key_last($messages);

        if ($last === null || ! $messages[$last] instanceof UserMessage) {
            return $next($step);
        }

        $messages[$last] = new UserMessage(
            $this->profile()."\n\n<question>\n{$this->escape($messages[$last]->content)}\n</question>",
            $messages[$last]->attachments,
        );

        return $next($step->withMessages($messages));
    }

    /**
     * Describe the user's job preferences and skills.
     */
    public function profile(): string
    {
        $this->user->loadMissing(['profile', 'skills']);
        $profile = $this->user->profile;

        $skills = $this->user->skills
            ->sortBy('name')
            ->map(function (Skill $skill): string {
                /** @var UserSkill $pivot */
                $pivot = $skill->getRelation('pivot');

                $level = $pivot->proficiency?->label() ?? 'level not set';

                return "{$this->profileValue($skill->name)} ({$level})";
            });

        $fields = [
            'Target roles' => array_map(fn (string $value): string => $this->profileValue($value), $profile->target_roles ?? []),
            'Preferred locations' => array_map(fn (string $value): string => $this->profileValue($value), $profile->preferred_locations ?? []),
            'Work arrangements' => $profile?->work_arrangements->map(fn (WorkArrangement $arrangement): string => $this->profileValue($arrangement->label()))->all() ?? [],
            'Skills' => $skills->all(),
        ];

        return collect($fields)
            ->map(fn (array $values, string $label): string => "- {$label}: ".($values === [] ? 'not set' : implode(', ', $values)))
            ->prepend('<user_profile>')
            ->push('</user_profile>')
            ->implode("\n");
    }

    /**
     * Escape user-typed text so it cannot close a message block.
     */
    private function escape(string $value): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $value);
    }

    /**
     * Escape a profile value and keep it on one line.
     *
     * @throws RuntimeException when the value cannot be put on one line, so it is never sent with its line breaks
     */
    private function profileValue(string $value): string
    {
        $singleLine = preg_replace('/\s+/u', ' ', mb_scrub($value, 'UTF-8'));

        if ($singleLine === null) {
            throw new RuntimeException('A profile value could not be put on one line: '.preg_last_error_msg());
        }

        return $this->escape(trim($singleLine));
    }
}
