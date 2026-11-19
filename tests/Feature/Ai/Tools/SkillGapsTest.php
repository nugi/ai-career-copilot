<?php

use App\Actions\Jobs\ProfileEmbeddingText;
use App\Ai\Agents\FitExplainer;
use App\Ai\Agents\RelevanceGrader;
use App\Ai\Tools\SkillGaps;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00'));
    Embeddings::fake()->preventStrayEmbeddings();
    FitExplainer::fake()->preventStrayPrompts();
    RelevanceGrader::fake()->preventStrayPrompts();
});

test('it returns mapped gaps and respects the limit', function (): void {
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->create([
        'country_code' => 'ID', 'target_roles' => ['Backend Engineer'],
    ]);
    $required = Skill::factory()->unverified()->create(['name' => 'PostgreSQL']);
    $nice = Skill::factory()->unverified()->create(['name' => 'Redis']);
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
    $posting->skills()->attach($required, ['is_required' => true]);
    $posting->skills()->attach($nice, ['is_required' => false]);
    $tool = app()->make(SkillGaps::class, ['user' => $profile->user]);

    $all = json_decode($tool->handle(new Request([])), true, flags: JSON_THROW_ON_ERROR);
    $limited = json_decode($tool->handle(new Request(['limit' => 1])), true, flags: JSON_THROW_ON_ERROR);

    expect($all)->toBe(['gaps' => [
        ['skill' => 'PostgreSQL', 'required_in' => 1, 'nice_to_have_in' => 0, 'of' => 1],
        ['skill' => 'Redis', 'required_in' => 0, 'nice_to_have_in' => 1, 'of' => 1],
    ]])->and($limited)->toBe(['gaps' => [$all['gaps'][0]]]);
});

test('it rejects a limit above ten', function (): void {
    $tool = app()->make(SkillGaps::class, ['user' => User::factory()->create()]);

    expect(fn () => $tool->handle(new Request(['limit' => 11])))->toThrow(ValidationException::class);
});

test('it returns a note when there are no gaps', function (): void {
    $tool = app()->make(SkillGaps::class, ['user' => User::factory()->create()]);

    expect(json_decode($tool->handle(new Request([])), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['gaps' => [], 'note' => 'No skill gaps were found in the top matches.']);
});
