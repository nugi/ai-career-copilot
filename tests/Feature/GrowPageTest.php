<?php

use App\Actions\Jobs\ProfileEmbeddingText;
use App\Ai\Agents\FitExplainer;
use App\Ai\Agents\LearningPlanner;
use App\Ai\Agents\RelevanceGrader;
use App\Models\JobPosting;
use App\Models\LearningPlan;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    $this->withoutVite();
    config(['ai-routing.global_daily_budget_usd' => null, 'ai-routing.daily_budget_usd' => null, 'skill-gaps.plan_every_hours' => 24]);
    LearningPlanner::fake()->preventStrayPrompts();
    FitExplainer::fake()->preventStrayPrompts();
    RelevanceGrader::fake()->preventStrayPrompts();
    Embeddings::fake()->preventStrayEmbeddings();
});

function growUserWithGap(): User
{
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->create([
        'country_code' => 'ID', 'target_roles' => ['Backend Engineer'],
    ]);
    $skill = Skill::factory()->unverified()->create(['name' => 'Docker']);
    $embeddingText = app(ProfileEmbeddingText::class);
    $vector = '['.implode(',', [1, ...array_fill(0, 1535, 0)]).']';
    $profile->match_embedding = $vector;
    $profile->match_embedding_model = config('ai-routing.embeddings.model');
    $profile->match_embedding_hash = $embeddingText->hash($embeddingText->text($profile));
    $profile->save();
    $posting = JobPosting::factory()->remote()->withEmbedding()->create([
        'title' => 'Backend Engineer', 'embedding' => $vector, 'expires_at' => null,
        'posted_at' => now()->subDays(2), 'skills_extracted_at' => now(),
    ]);
    $posting->skills()->attach($skill, ['is_required' => true]);

    return $profile->user;
}

test('guests are redirected to login', function (): void {
    $this->get(route('grow.show'))->assertRedirect(route('login'));
});

test('the stored plan is shown and gaps load as deferred props', function (): void {
    $this->travelTo('2026-11-15 12:00:00');
    $user = growUserWithGap();
    $plan = LearningPlan::factory()->for($user)->create(['generated_at' => now()->subHour()]);

    $this->actingAs($user)->get(route('grow.show'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('grow', false)
            ->where('plan.plan', $plan->plan)
            ->where('plan.generated_at', '2026-11-15T11:00:00+00:00')
            ->where('plan.available_at', '2026-11-16T11:00:00+00:00')
            ->missing('gaps')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('gaps.0.skill', 'Docker')
                ->where('gaps.0.required_jobs', 1)
                ->where('gaps.0.of', 1)));
});

test('a plan is generated and stored with a success toast', function (): void {
    $this->travelTo('2026-11-15 12:00:00');
    $user = growUserWithGap();
    LearningPlanner::fake([[
        'summary' => 'Practice Docker.',
        'weeks' => array_map(fn (int $week): array => [
            'week' => $week, 'goal' => 'Practice Docker', 'skills' => ['Docker'],
            'tasks' => ['Build an image.', 'Run a container.'],
        ], range(1, 4)),
        'project' => ['title' => 'Task API', 'description' => 'Package an API.', 'skills' => ['Docker']],
        'showcase' => ['resume_bullet' => 'Built an API.', 'linkedin' => 'I built an API.'],
    ]])->preventStrayPrompts();

    $this->actingAs($user)->post(route('grow.plan'))->assertRedirect(route('grow.show'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Your learning plan is ready.']);
    expect($user->learningPlan()->sole()->plan['summary'])->toBe('Practice Docker.');
    LearningPlanner::assertPromptedTimes(1);
});

test('a recent plan prevents another prompt and flashes the cooldown', function (): void {
    $this->travelTo('2026-11-15 12:00:00');
    $user = growUserWithGap();
    LearningPlan::factory()->for($user)->create(['generated_at' => now()->subHour()]);

    $this->actingAs($user)->post(route('grow.plan'))->assertRedirect(route('grow.show'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'You can make a new plan after 16 Nov 11:00 UTC.']);
    LearningPlanner::assertNeverPrompted();
});
