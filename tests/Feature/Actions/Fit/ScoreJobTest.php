<?php

use App\Actions\Fit\ScoreJob;
use App\Ai\Agents\FitExplainer;
use App\Enums\Currency;
use App\Enums\SalaryPeriod;
use App\Enums\SeniorityLevel;
use App\Enums\WorkArrangement;
use App\Models\ExchangeRate;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeChunk;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    Cache::flush();
    Queue::fake();
    Embeddings::fake()->preventStrayEmbeddings();
    FitExplainer::fake()->preventStrayPrompts();
    config([
        'ai-routing.global_daily_budget_usd' => null,
        'ai-routing.daily_budget_usd' => null,
        'ai-routing.fit_explanations.faithfulness_sample_rate' => 0,
    ]);
});

/**
 * @return array{User, JobPosting, Resume}
 */
function scoreJobFixture(): array
{
    $profile = Profile::factory()->withoutSalary()->create(['country_code' => 'ID', 'work_arrangements' => [WorkArrangement::Remote]]);
    $user = $profile->user;
    $resume = Resume::factory()->for($user)->primary()->parsed()->create();
    $vector = '['.implode(',', [1, ...array_fill(0, 1535, 0)]).']';
    ResumeChunk::factory()->for($resume)->create([
        'content' => 'Built Laravel APIs.', 'embedding' => $vector,
        'embedding_model' => 'text-embedding-3-small', 'embedded_at' => now(),
    ]);
    $posting = JobPosting::factory()->remote()->create([
        'expires_at' => null, 'country_code' => null, 'country_codes' => [], 'region' => null,
        'location' => null, 'eligible_countries' => ['ID'], 'eligible_regions' => [],
        'years_required' => 2, 'extracted_seniority' => SeniorityLevel::Senior,
        'salary_currency' => 'USD', 'annual_salary_min' => 90_000, 'annual_salary_max' => 100_000,
        'embedding' => $vector, 'embedding_model' => 'text-embedding-3-small',
        'embedded_at' => now(), 'embedding_text_hash' => hash('sha256', 'Posting'), 'embedded_text' => 'Posting',
    ]);
    $skill = Skill::factory()->create(['name' => 'Laravel']);
    $user->skills()->attach($skill, ['source' => 'resume']);
    $posting->skills()->attach($skill, ['is_required' => true]);

    return [$user, $posting, $resume];
}

test('it stores the score, breakdown, inputs and version', function (): void {
    [$user, $posting, $resume] = scoreJobFixture();
    FitExplainer::fake([[
        'verdict' => 'strong_fit', 'summary' => 'You built APIs.',
        'reasons' => [['claim' => 'You built Laravel APIs.', 'excerpt_ids' => [1]]],
        'missing_requirements' => [],
    ]])->preventStrayPrompts();

    $match = app(ScoreJob::class)->handle($user, $posting)->fresh();

    expect($match->score)->toBe(100)
        ->and($match->scoring_version)->toBe('v1')
        ->and($match->profile_version)->toBe($user->profile->version)
        ->and($match->resume_id)->toBe($resume->id)
        ->and($match->inputs['requiredSkills'])->toBe(['Laravel'])
        ->and($match->inputs['seniority'])->toBe('senior')
        ->and((float) $match->inputs['yearsOfExperience'])->toBe($resume->yearsOfExperience())
        ->and($match->breakdown['required_skills']['detail'])->toBe(['of' => 1, 'matched' => 1])
        ->and($match->matched_skills)->toBe(['Laravel'])
        ->and($match->missing_skills)->toBe([])
        ->and($match->reasons)->toBe(['You built Laravel APIs.'])
        ->and($match->scored_at)->not->toBeNull();
});

test('a floor in another currency is converted before scoring', function (): void {
    [$user, $posting] = scoreJobFixture();
    ExchangeRate::factory()->create(['currency' => 'IDR', 'rate' => 20_000]);
    ExchangeRate::factory()->create(['currency' => 'USD', 'rate' => 1.0]);
    $user->profile->update(['min_salary' => 100_000_000, 'salary_currency' => Currency::Idr, 'salary_period' => SalaryPeriod::Monthly]);
    $posting->update(['annual_salary_min' => 50_000, 'annual_salary_max' => 58_500]);

    $match = app(ScoreJob::class)->handle($user, $posting, false);

    expect($match->inputs['salaryFloor'])->toBe(65_000)
        ->and($match->breakdown['salary']['value'])->toBe(0.5);
});

test('the score does not change when the reasons are unavailable', function (): void {
    [$user, $posting] = scoreJobFixture();
    $action = app(ScoreJob::class);
    $score = $action->handle($user, $posting, false)->score;
    config(['ai-routing.daily_budget_usd' => 0]);

    $match = $action->handle($user, $posting);

    expect($match->score)->toBe($score)->and($match->reasons)->toBe([]);
    FitExplainer::assertNeverPrompted();
});

test('scoring again updates the same row', function (): void {
    [$user, $posting] = scoreJobFixture();
    $action = app(ScoreJob::class);
    $first = $action->handle($user, $posting, false);
    $user->skills()->detach();

    $second = $action->handle($user, $posting, false);

    expect($second->id)->toBe($first->id)
        ->and($second->score)->toBeLessThan($first->score)
        ->and($second->missing_skills)->toBe(['Laravel'])
        ->and(JobMatch::query()->count())->toBe(1);
});
