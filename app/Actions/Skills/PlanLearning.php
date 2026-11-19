<?php

namespace App\Actions\Skills;

use App\Ai\Agents\LearningPlanner;
use App\Ai\DailyBudget;
use App\Enums\AiFeature;
use App\Models\LearningPlan;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

/**
 * Validate, ground and store one learning plan per user, at most once per configured interval.
 *
 * The caller supplies ranked gaps. No matches or gaps are recomputed here. An invalid output gets one retry with its
 * field errors. A failure keeps the current plan and its generation time, so the user can try again at once.
 */
final class PlanLearning
{
    public function __construct(private DailyBudget $dailyBudget) {}

    /**
     * Make a plan, or say why the current plan should be kept without paying for another call.
     *
     * @param  list<SkillGap>  $gaps
     */
    public function handle(User $user, array $gaps): LearningPlanResult
    {
        $current = $user->learningPlan()->first();
        $tooSoon = $this->tooSoon($current);

        if ($tooSoon !== null) {
            return $tooSoon;
        }

        $gaps = array_slice($gaps, 0, config('skill-gaps.plan_gaps'));

        if ($gaps === []) {
            return new LearningPlanResult('no_gaps', $current);
        }

        if ($this->dailyBudget->isGloballyExceeded()
            || ($this->dailyBudget->isNewAccount($user) && $this->dailyBudget->isNewAccountReserveExceeded())
            || $this->dailyBudget->isExceeded($user, AiFeature::SkillGapPlan)) {
            return new LearningPlanResult('budget', $current);
        }

        $lock = Cache::lock('learning-plan:'.$user->getKey(), 120);

        if (! $lock->get()) {
            return new LearningPlanResult('busy', $current);
        }

        try {
            $current = $user->learningPlan()->first();
            $tooSoon = $this->tooSoon($current);

            if ($tooSoon !== null) {
                return $tooSoon;
            }

            return $this->generate($user, $gaps, $current);
        } finally {
            $lock->release();
        }
    }

    /**
     * Read the cooldown from the stored generation time, not a cached relation.
     */
    private function tooSoon(?LearningPlan $current): ?LearningPlanResult
    {
        if ($current === null) {
            return null;
        }

        $availableAt = $current->generated_at->addHours(config('skill-gaps.plan_every_hours'));

        return $availableAt->isFuture() ? new LearningPlanResult('too_soon', $current, $availableAt) : null;
    }

    /**
     * Prompt and validate inside the lock, then store only the grounded fields.
     *
     * @param  list<SkillGap>  $gaps
     */
    private function generate(User $user, array $gaps, ?LearningPlan $current): LearningPlanResult
    {
        $user->loadMissing(['profile', 'skills']);
        $targetRoles = $user->profile->target_roles ?? [];
        $userSkills = array_values($user->skills->map(fn (Skill $skill): string => $skill->name)->all());
        $agent = new LearningPlanner($user);

        try {
            $output = $this->ask($agent, $agent->message($gaps, $targetRoles, $userSkills));
            $validator = $this->validate($output);

            if ($validator->fails()) {
                $output = $this->ask($agent, $agent->message($gaps, $targetRoles, $userSkills, $this->errorsWithPaths($validator)));
                $validator = $this->validate($output);
            }
        } catch (Throwable $exception) {
            report($exception);

            return new LearningPlanResult('failed', $current);
        }

        if ($validator->fails()) {
            Log::warning('skills.plan.invalid', ['user_id' => $user->getKey(), 'paths' => array_keys($validator->failed())]);

            return new LearningPlanResult('failed', $current);
        }

        $plan = $this->ground($validator->validated(), $gaps);
        $hasSkills = $plan['project']['skills'] !== [];

        foreach ($plan['weeks'] as $week) {
            $hasSkills = $hasSkills || $week['skills'] !== [];
        }

        if (! $hasSkills) {
            Log::warning('skills.plan.ungrounded', ['user_id' => $user->getKey()]);

            return new LearningPlanResult('failed', $current);
        }

        $stored = LearningPlan::query()->updateOrCreate(['user_id' => $user->getKey()], [
            'gaps' => array_map(fn (SkillGap $gap): array => $gap->toArray(), $gaps),
            'plan' => $plan,
            'prompt_version' => LearningPlanner::PROMPT_VERSION,
            'generated_at' => now(),
        ]);

        Log::info('skills.plan.ready', ['user_id' => $user->getKey(), 'dropped_skills' => $plan['dropped_skills']]);

        return new LearningPlanResult('ready', $stored);
    }

    /**
     * Keep only sent gap names, use their spelling, remove duplicates and trim all text.
     *
     * @param  array<string, mixed>  $output  The validated output.
     * @param  list<SkillGap>  $gaps
     * @return array{summary: string, weeks: list<array{week: int, goal: string, skills: list<string>, tasks: list<string>}>, project: array{title: string, description: string, skills: list<string>}, showcase: array{resume_bullet: string, linkedin: string}, dropped_skills: int}
     */
    private function ground(array $output, array $gaps): array
    {
        $names = [];

        foreach ($gaps as $gap) {
            $names[mb_strtolower(trim($gap->skill))] = trim($gap->skill);
        }

        $dropped = 0;
        $weeks = [];

        foreach ($output['weeks'] as $week) {
            $weeks[] = [
                'week' => (int) $week['week'],
                'goal' => trim($week['goal']),
                'skills' => $this->groundSkills($week['skills'], $names, $dropped),
                'tasks' => array_values(array_map(trim(...), $week['tasks'])),
            ];
        }

        usort($weeks, fn (array $left, array $right): int => $left['week'] <=> $right['week']);
        $projectSkills = $this->groundSkills($output['project']['skills'], $names, $dropped);

        return [
            'summary' => trim($output['summary']),
            'weeks' => $weeks,
            'project' => [
                'title' => trim($output['project']['title']),
                'description' => trim($output['project']['description']),
                'skills' => $projectSkills,
            ],
            'showcase' => [
                'resume_bullet' => trim($output['showcase']['resume_bullet']),
                'linkedin' => trim($output['showcase']['linkedin']),
            ],
            'dropped_skills' => $dropped,
        ];
    }

    /**
     * Count and remove names outside the sent gaps and duplicate names within each skill list.
     *
     * @param  list<string>  $skills
     * @param  array<string, string>  $names
     * @return list<string>
     */
    private function groundSkills(array $skills, array $names, int &$dropped): array
    {
        $kept = [];

        foreach ($skills as $skill) {
            $key = mb_strtolower(trim($skill));

            if (! array_key_exists($key, $names) || array_key_exists($key, $kept)) {
                $dropped++;

                continue;
            }

            $kept[$key] = $names[$key];
        }

        return array_values($kept);
    }

    /**
     * Send one message to the agent and return its structured output.
     *
     * @return array<mixed>
     */
    private function ask(LearningPlanner $agent, string $message): array
    {
        /** @var StructuredAgentResponse $response */
        $response = $agent->prompt($message);

        return $response->toArray();
    }

    /**
     * Validate one output of the agent.
     *
     * @param  array<mixed>  $output
     */
    private function validate(array $output): ValidatorContract
    {
        return Validator::make($output, [
            'summary' => ['required', 'string', 'max:600'],
            'weeks' => ['required', 'array', 'size:4'],
            'weeks.*.week' => ['required', 'integer', 'between:1,4', 'distinct'],
            'weeks.*.goal' => ['required', 'string', 'max:300'],
            'weeks.*.skills' => ['present', 'array', 'max:5'],
            'weeks.*.skills.*' => ['string', 'max:100'],
            'weeks.*.tasks' => ['required', 'array', 'min:2', 'max:4'],
            'weeks.*.tasks.*' => ['string', 'max:300'],
            'project.title' => ['required', 'string', 'max:150'],
            'project.description' => ['required', 'string', 'max:800'],
            'project.skills' => ['present', 'array', 'max:8'],
            'project.skills.*' => ['string', 'max:100'],
            'showcase.resume_bullet' => ['required', 'string', 'max:300'],
            'showcase.linkedin' => ['required', 'string', 'max:600'],
        ]);
    }

    /**
     * List the validation errors for the next attempt, each with its field path.
     *
     * @return list<string>
     */
    private function errorsWithPaths(ValidatorContract $validator): array
    {
        $lines = [];

        foreach ($validator->errors()->messages() as $path => $messages) {
            foreach ($messages as $message) {
                $lines[] = str_contains($message, $path) ? $message : "{$path}: {$message}";
            }
        }

        return $lines;
    }
}
