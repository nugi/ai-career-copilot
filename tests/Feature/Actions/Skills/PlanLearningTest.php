<?php

use App\Actions\Skills\PlanLearning;
use App\Actions\Skills\SkillGap;
use App\Ai\Agents\LearningPlanner;
use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\LearningPlan;
use App\Models\User;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function (): void {
    config([
        'ai-routing.global_daily_budget_usd' => null,
        'ai-routing.daily_budget_usd' => null,
        'skill-gaps.plan_gaps' => 5,
        'skill-gaps.plan_every_hours' => 24,
    ]);
});

/**
 * A complete planner output, with optional replacements of its top-level fields.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function learningPlanOutput(array $overrides = []): array
{
    return [
        'summary' => 'Practice Docker through a backend project.',
        'weeks' => array_map(fn (int $week): array => [
            'week' => $week,
            'goal' => 'Run and inspect the project in Docker.',
            'skills' => ['Docker'],
            'tasks' => ['Write a container definition.', 'Run the service and inspect its logs.'],
        ], range(1, 4)),
        'project' => [
            'title' => 'Containerized task API',
            'description' => 'Package a task API and document how to run it locally.',
            'skills' => ['Docker'],
        ],
        'showcase' => [
            'resume_bullet' => 'Built a containerized task API with a documented local setup.',
            'linkedin' => 'I practiced Docker by packaging a task API. The project includes setup notes.',
        ],
        ...$overrides,
    ];
}

test('a valid plan is grounded and stored with the gaps and prompt version', function (): void {
    $this->travelTo('2026-11-15 12:00:00');
    $user = User::factory()->onboarded()->create();
    $gaps = [new SkillGap('Docker', 3.5, 4, 1, 5, 10)];
    $output = learningPlanOutput(['summary' => '  Practice Docker through a backend project.  ']);
    $output['weeks'][0]['skills'] = [' docker ', 'Kubernetes'];
    $output['project']['skills'] = ['DOCKER'];
    $output['weeks'] = array_reverse($output['weeks']);
    LearningPlanner::fake([$output])->preventStrayPrompts();

    $result = app(PlanLearning::class)->handle($user, $gaps);

    expect($result->status)->toBe('ready')
        ->and($result->availableAt)->toBeNull();
    $stored = $user->learningPlan()->sole();
    $this->assertModelExists($result->plan);
    expect($result->plan->id)->toBe($stored->id);
    expect($stored->plan['weeks'][0]['skills'])->toBe(['Docker'])
        ->and(array_column($stored->plan['weeks'], 'week'))->toBe([1, 2, 3, 4])
        ->and($stored->plan['project']['skills'])->toBe(['Docker'])
        ->and($stored->plan['summary'])->toBe('Practice Docker through a backend project.')
        ->and($stored->plan['dropped_skills'])->toBe(1)
        ->and($stored->gaps)->toEqual([$gaps[0]->toArray()])
        ->and($stored->prompt_version)->toBe('v1')
        ->and($stored->generated_at->toDateTimeString())->toBe('2026-11-15 12:00:00');
    LearningPlanner::assertPromptedTimes(1);
    LearningPlanner::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Docker: required in 4 of 10 top matches; nice-to-have in 1'));
});

test('an invalid output is retried once with field errors and the valid output is stored', function (): void {
    $user = User::factory()->onboarded()->create();
    $gaps = [new SkillGap('Docker', 3.5, 4, 1, 5, 10)];
    LearningPlanner::fake([
        learningPlanOutput(['summary' => str_repeat('x', 601)]),
        learningPlanOutput(),
    ])->preventStrayPrompts();

    $result = app(PlanLearning::class)->handle($user, $gaps);

    expect($result->status)->toBe('ready');
    $this->assertModelExists($result->plan);
    expect($user->learningPlan()->sole()->plan['summary'])->toBe('Practice Docker through a backend project.');
    LearningPlanner::assertPromptedTimes(2);
    LearningPlanner::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('<validation_errors>') && $prompt->contains('summary'));
});

test('two invalid outputs fail without storing a plan', function (): void {
    $user = User::factory()->onboarded()->create();
    $gaps = [new SkillGap('Docker', 3.5, 4, 1, 5, 10)];
    LearningPlanner::fake([
        learningPlanOutput(['weeks' => []]),
        learningPlanOutput(['weeks' => []]),
    ])->preventStrayPrompts();

    $result = app(PlanLearning::class)->handle($user, $gaps);

    expect($result->status)->toBe('failed')
        ->and($result->plan)->toBeNull();
    $this->assertDatabaseEmpty('learning_plans');
    LearningPlanner::assertPromptedTimes(2);
});

test('a plan from 23 hours ago is kept and one from 25 hours ago is replaced', function (): void {
    $this->travelTo('2026-11-15 12:00:00');
    $user = User::factory()->onboarded()->create();
    $gaps = [new SkillGap('Docker', 3.5, 4, 1, 5, 10)];
    $current = LearningPlan::factory()->for($user)->create(['generated_at' => now()->subHours(23)]);
    LearningPlanner::fake([learningPlanOutput(['summary' => 'A replacement plan.'])])->preventStrayPrompts();
    $action = app(PlanLearning::class);

    $tooSoon = $action->handle($user, $gaps);

    expect($tooSoon->status)->toBe('too_soon')
        ->and($tooSoon->plan->id)->toBe($current->id)
        ->and($tooSoon->availableAt->toDateTimeString())->toBe('2026-11-15 13:00:00');
    expect($current->fresh()->generated_at->toDateTimeString())->toBe('2026-11-14 13:00:00');
    LearningPlanner::assertNeverPrompted();

    $current->update(['generated_at' => now()->subHours(25)]);

    $ready = $action->handle($user, $gaps);

    expect($ready->status)->toBe('ready')
        ->and($ready->plan->id)->toBe($current->id)
        ->and($ready->availableAt)->toBeNull();
    $this->assertDatabaseCount('learning_plans', 1);
    expect($current->fresh()->plan['summary'])->toBe('A replacement plan.')
        ->and($current->fresh()->generated_at->toDateTimeString())->toBe('2026-11-15 12:00:00');
    LearningPlanner::assertPromptedTimes(1);
});

test('an empty gap list returns no gaps without prompting the agent', function (): void {
    $user = User::factory()->onboarded()->create();
    LearningPlanner::fake()->preventStrayPrompts();

    $result = app(PlanLearning::class)->handle($user, []);

    expect($result->status)->toBe('no_gaps')
        ->and($result->plan)->toBeNull();
    $this->assertDatabaseEmpty('learning_plans');
    LearningPlanner::assertNeverPrompted();
});

test('a spent per-user budget returns budget without prompting the agent', function (): void {
    $this->travelTo('2026-11-15 12:00:00');
    config(['ai-routing.daily_budget_usd' => 0.01]);
    $user = User::factory()->onboarded()->create();
    $gaps = [new SkillGap('Docker', 3.5, 4, 1, 5, 10)];
    AiUsage::factory()->for($user)->create(['feature' => AiFeature::Chat, 'cost_usd' => 0.02, 'created_at' => now()]);
    LearningPlanner::fake()->preventStrayPrompts();

    $result = app(PlanLearning::class)->handle($user, $gaps);

    expect($result->status)->toBe('budget')
        ->and($result->plan)->toBeNull();
    $this->assertDatabaseEmpty('learning_plans');
    LearningPlanner::assertNeverPrompted();
});
