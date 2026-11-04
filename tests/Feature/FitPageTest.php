<?php

use App\Ai\Agents\FitExplainer;
use App\Enums\ResumeSection;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\ResumeChunk;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    config(['ai-routing.global_daily_budget_usd' => null, 'ai-routing.daily_budget_usd' => null, 'ai-routing.fit_explanations.faithfulness_sample_rate' => 0]);
});

test('guests are redirected to the login page', function () {
    $posting = JobPosting::factory()->create(['expires_at' => null]);

    $this->get(route('matches.fit', $posting->id))->assertRedirect(route('login'));
});

test('the page draws the posting first and loads the explanation as a deferred prop', function () {
    $vector = '['.implode(',', [1, ...array_fill(0, 1535, 0)]).']';
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->for($user)->primary()->parsed()->create();
    $chunk = ResumeChunk::factory()->for($resume)->create([
        'position' => 0, 'section' => ResumeSection::Experience, 'content' => 'Built Laravel APIs.',
        'embedding' => $vector, 'embedding_model' => 'text-embedding-3-small', 'embedded_at' => now(),
    ]);
    $posting = JobPosting::factory()->withEmbedding()->create(['title' => 'Laravel Engineer', 'expires_at' => null, 'embedding' => $vector]);
    FitExplainer::fake([[
        'verdict' => 'strong_fit',
        'summary' => 'You build Laravel APIs.',
        'reasons' => [['claim' => 'You built Laravel APIs.', 'excerpt_ids' => [1]]],
        'missing_requirements' => [],
    ]]);

    $this->actingAs($user)->get(route('matches.fit', $posting->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fit', false)
            ->where('posting.id', $posting->id)
            ->where('posting.title', 'Laravel Engineer')
            ->missing('explanation')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('explanation.status', 'ready')
                ->where('explanation.verdict', 'strong_fit')
                ->where('explanation.reasons.0.claim', 'You built Laravel APIs.')
                ->where('explanation.reasons.0.excerpts.0.id', $chunk->id)
                ->where('explanation.reasons.0.excerpts.0.content', 'Built Laravel APIs.')
            )
        );
});

test('an expired posting is not found', function () {
    $user = User::factory()->onboarded()->create();
    $posting = JobPosting::factory()->expired()->create();

    $this->actingAs($user)->get(route('matches.fit', $posting->id))->assertNotFound();
});
