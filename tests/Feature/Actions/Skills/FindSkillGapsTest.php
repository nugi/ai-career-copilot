<?php

use App\Actions\Jobs\ProfileEmbeddingText;
use App\Actions\Skills\FindSkillGaps;
use App\Ai\Agents\FitExplainer;
use App\Ai\Agents\RelevanceGrader;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Support\Carbon;
use Laravel\Ai\Embeddings;

test('it returns skill gaps without saving matches or making AI calls', function (): void {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00'));
    Embeddings::fake()->preventStrayEmbeddings();
    FitExplainer::fake()->preventStrayPrompts();
    RelevanceGrader::fake()->preventStrayPrompts();
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->create([
        'country_code' => 'ID', 'target_roles' => ['Backend Engineer'],
    ]);
    $user = $profile->user;
    $known = Skill::factory()->unverified()->create(['name' => 'Laravel']);
    $missing = Skill::factory()->unverified()->create(['name' => 'PostgreSQL']);
    $user->skills()->attach($known, ['source' => 'resume']);
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
    $posting->skills()->attach([$known->id, $missing->id], ['is_required' => true]);

    $gaps = app(FindSkillGaps::class)->handle($user);

    expect(array_column($gaps, 'skill'))->toBe(['PostgreSQL']);
    expect(JobMatch::query()->count())->toBe(0);
    Embeddings::assertNothingGenerated();
    FitExplainer::assertNeverPrompted();
    RelevanceGrader::assertNeverPrompted();
});
