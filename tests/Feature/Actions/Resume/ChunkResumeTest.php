<?php

use App\Actions\Resume\ChunkResume;
use App\Enums\ResumeSection;
use App\Models\Resume;
use Illuminate\Support\Facades\DB;

test('parsed sections become ordered self-contained chunks with their periods', function () {
    $data = parsedResume();
    $data['experiences'][] = [
        'title' => 'Lead Engineer', 'company' => 'Example', 'start' => '2022', 'end' => null,
        'bullets' => ['Led the platform team'],
    ];

    $chunks = app(ChunkResume::class)->chunks($data);

    expect(array_column($chunks, 'section'))->toBe([
        ResumeSection::Summary, ResumeSection::Experience, ResumeSection::Experience,
        ResumeSection::Skills, ResumeSection::Education,
    ])
        ->and($chunks[1]['content'])->toBe("Experience as Backend Engineer at Acme Corp (2019-01–2021-12):\n- Designed a PostgreSQL schema for 2M orders")
        ->and($chunks[2]['content'])->toBe("Experience as Lead Engineer at Example (2022–present):\n- Led the platform team")
        ->and([$chunks[1]['period_start'], $chunks[1]['period_end']])->toBe(['2019-01', '2021-12'])
        ->and([$chunks[2]['period_start'], $chunks[2]['period_end']])->toBe(['2022', null])
        ->and([$chunks[4]['period_start'], $chunks[4]['period_end']])->toBe(['2014', '2018']);

    $withoutOptionalSections = app(ChunkResume::class)->chunks(parsedResume([
        'summary' => null, 'skills' => [], 'education' => [],
    ]));
    expect(array_column($withoutOptionalSections, 'section'))->toBe([ResumeSection::Experience]);
});

test('long experiences split consecutive bullets without losing context or exceeding the token limit', function () {
    $bullets = array_map(fn (int $index): string => "Bullet {$index}: ".str_repeat('Work ', 30), range(1, 8));
    $experience = parsedResume()['experiences'][0];
    $experience['bullets'] = $bullets;

    $chunks = app(ChunkResume::class)->chunks(parsedResume([
        'summary' => null, 'skills' => [], 'education' => [], 'experiences' => [$experience],
    ]));

    expect(count($chunks))->toBeGreaterThanOrEqual(3);
    $savedBullets = [];
    foreach ($chunks as $chunk) {
        expect($chunk['section'])->toBe(ResumeSection::Experience)
            ->and($chunk['content'])->toStartWith('Experience as Backend Engineer at Acme Corp (2019-01–2021-12):')
            ->and($chunk['token_count'])->toBeLessThanOrEqual(120);
        array_push($savedBullets, ...array_slice(explode("\n- ", $chunk['content']), 1));
    }
    expect($savedBullets)->toBe($bullets);

    $longBullet = str_repeat('A', 1000);
    $experience['bullets'] = [$longBullet];

    $chunks = app(ChunkResume::class)->chunks(parsedResume([
        'summary' => null, 'skills' => [], 'education' => [], 'experiences' => [$experience],
    ]));

    expect(count($chunks))->toBe(1)
        ->and($chunks[0]['content'])->toBe("Experience as Backend Engineer at Acme Corp (2019-01–2021-12):\n- ".$longBullet);
});

test('rebuilding chunks preserves unchanged vectors and only reuses vectors belonging to the same user', function () {
    $resume = Resume::factory()->create(['parsed_data' => parsedResume()]);
    $chunkResume = app(ChunkResume::class);
    $vector = '['.implode(',', array_fill(0, 1536, 0.1)).']';
    $current = ['embedding' => $vector, 'embedding_model' => config('ai-routing.embeddings.model'), 'embedded_at' => now()];
    DB::transaction(fn () => $chunkResume->sync($resume));
    $resume->chunks()->update($current);
    $data = parsedResume();
    $data['experiences'][0]['bullets'][0] = 'Built a new billing service';
    $resume->update(['parsed_data' => $data]);

    DB::transaction(fn () => $chunkResume->sync($resume));

    foreach ($resume->chunks()->get() as $chunk) {
        expect($chunk->embedding)->toBe($chunk->section === ResumeSection::Experience ? null : $vector);
    }
    $resume->chunks()->whereNull('embedding')->update($current);
    $second = Resume::factory()->for($resume->user)->create(['parsed_data' => $data]);

    DB::transaction(fn () => $chunkResume->sync($second));

    expect($second->chunks()->count())->toBe(4);
    foreach ($second->chunks()->get() as $chunk) {
        expect($chunk->embedding)->toBe($vector)
            ->and($chunk->embedding_model)->toBe(config('ai-routing.embeddings.model'));
    }
    $other = Resume::factory()->create(['parsed_data' => $data]);

    DB::transaction(fn () => $chunkResume->sync($other));

    expect($other->chunks()->count())->toBe(4)
        ->and($other->chunks()->whereNotNull('embedding')->count())->toBe(0);
});
