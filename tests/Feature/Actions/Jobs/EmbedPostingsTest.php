<?php

use App\Actions\Jobs\EmbedPostings;
use App\Actions\Jobs\QueueEmbeddings;
use App\JobSources\Normalization\NormalizedPosting;
use App\Models\JobPosting;
use App\Models\Skill;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

/**
 * A live posting whose skill extraction has settled for its current text.
 *
 * @param  array<string, mixed>  $attributes
 */
function settledForEmbeddingPosting(string $title, array $attributes = []): JobPosting
{
    $hash = NormalizedPosting::textHash($title, 'Work with our team.');

    return JobPosting::factory()->create([
        'title' => $title,
        'description' => 'Work with our team.',
        'text_hash' => $hash,
        'skills_text_hash' => $hash,
        'expires_at' => now()->addDays(20),
        ...$attributes,
    ]);
}

test('settled postings are embedded together and current postings are skipped', function () {
    $requests = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$requests): array {
        $requests[] = $prompt->inputs;

        return array_map(fn (string $input): array => array_fill(0, 1536, 0.1), $prompt->inputs);
    });
    $first = settledForEmbeddingPosting('Backend Engineer');
    $second = settledForEmbeddingPosting('Data Engineer');
    $skills = collect(['B', 'A'])->map(fn (string $name): Skill => Skill::factory()->create(['name' => $name]));
    $first->skills()->attach($skills->pluck('id')->all());
    $second->skills()->attach($skills->pluck('id')->all());
    $ids = [$first->id, $second->id];

    expect(app(EmbedPostings::class)->handle($ids))->toBe(['embedded' => 2, 'copied' => 0, 'skipped' => 0]);

    $first->refresh();
    $text = "Backend Engineer\nSkills: A, B";
    expect($requests)->toBe([[$text, "Data Engineer\nSkills: A, B"]])
        ->and($first->embedded_text)->toBe($text)
        ->and($first->embedding_text_hash)->toBe(hash('sha256', $text))
        ->and($first->embedding_model)->toBe(config('ai-routing.embeddings.model'))
        ->and($first->embedding)->toBe('['.implode(',', array_fill(0, 1536, 0.1)).']')
        ->and($first->embedded_at)->not->toBeNull();

    expect(app(EmbedPostings::class)->handle($ids))->toBe(['embedded' => 0, 'copied' => 0, 'skipped' => 2])
        ->and($requests)->toHaveCount(1);
});

test('a changed skill relation makes an embedded posting pending again', function () {
    $requests = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$requests): array {
        $requests[] = $prompt->inputs;

        return array_map(fn (string $input): array => array_fill(0, 1536, 0.1), $prompt->inputs);
    });
    $posting = settledForEmbeddingPosting('Backend Engineer');
    app(EmbedPostings::class)->handle([$posting->id]);
    $posting->skills()->attach(Skill::factory()->create(['name' => 'PHP']));

    expect(app(QueueEmbeddings::class)->pendingIds())->toBe([$posting->id])
        ->and(app(EmbedPostings::class)->handle([$posting->id]))->toBe(['embedded' => 1, 'copied' => 0, 'skipped' => 0])
        ->and($requests)->toBe([['Backend Engineer'], ["Backend Engineer\nSkills: PHP"]])
        ->and($posting->fresh()->embedded_text)->toBe("Backend Engineer\nSkills: PHP");
});

test('identical texts share one generated vector and later postings copy it', function () {
    $requests = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$requests): array {
        $requests[] = $prompt->inputs;

        return array_map(fn (string $input): array => array_fill(0, 1536, 0.1), $prompt->inputs);
    });
    $first = settledForEmbeddingPosting('Backend Engineer');
    $second = settledForEmbeddingPosting('Backend Engineer');
    $skill = Skill::factory()->create(['name' => 'PHP']);
    $first->skills()->attach($skill);
    $second->skills()->attach($skill);

    expect(app(EmbedPostings::class)->handle([$first->id, $second->id]))->toBe(['embedded' => 2, 'copied' => 0, 'skipped' => 0])
        ->and($requests)->toBe([["Backend Engineer\nSkills: PHP"]])
        ->and($first->fresh()->embedding)->toBe($second->fresh()->embedding);

    $third = settledForEmbeddingPosting('Backend Engineer');
    $third->skills()->attach($skill);

    expect(app(EmbedPostings::class)->handle([$third->id]))->toBe(['embedded' => 0, 'copied' => 1, 'skipped' => 0])
        ->and($requests)->toHaveCount(1)
        ->and($third->fresh()->embedding)->toBe($first->fresh()->embedding)
        ->and($third->fresh()->embedded_text)->toBe("Backend Engineer\nSkills: PHP");
});

test('a live posting with unsettled skills is neither pending nor embedded', function () {
    Embeddings::fake();
    $posting = settledForEmbeddingPosting('Backend Engineer', ['skills_text_hash' => null]);

    expect(app(QueueEmbeddings::class)->pendingIds())->toBe([])
        ->and(app(EmbedPostings::class)->handle([$posting->id]))->toBe(['embedded' => 0, 'copied' => 0, 'skipped' => 0])
        ->and($posting->fresh()->embedding)->toBeNull();
    Embeddings::assertNothingGenerated();
});
