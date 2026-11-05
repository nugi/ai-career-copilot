<?php

use App\Actions\Jobs\MatchOpenings;
use App\Enums\KeywordWeighting;
use App\Enums\WorkArrangement;
use App\Models\ExchangeRate;
use App\Models\JobPosting;
use App\Models\Opening;
use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;

test('openings are ordered by cosine distance with numeric distances and descending ids for ties', function () {
    $this->freezeTime();
    $zeros = array_fill(0, 1534, 0);
    $vector = '['.implode(',', [1, 0, ...$zeros]).']';
    $postings = JobPosting::factory()->remote()->withEmbedding();
    $farthest = $postings->create([
        'embedding' => '['.implode(',', [0, 1, ...$zeros]).']', 'expires_at' => null,
    ]);
    $closest = $postings->create(['embedding' => $vector, 'expires_at' => null]);
    $middle = $postings->create([
        'embedding' => '['.implode(',', [3, 4, ...$zeros]).']', 'expires_at' => null,
    ]);
    $skill = Skill::factory()->create();
    $tied = $postings->hasAttached($skill, ['is_required' => true])->create([
        'embedding' => $vector, 'expires_at' => null,
    ]);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();

    $results = app(MatchOpenings::class)->handle($profile, $vector, null);

    expect($results->pluck('id')->all())->toBe([$tied->id, $closest->id, $middle->id, $farthest->id]);
    foreach ($results as $result) {
        expect($result)->toBeInstanceOf(JobPosting::class);
        expect($result->getAttribute('distance'))->toBeNumeric();
        expect($result->relationLoaded('skills'))->toBeTrue();
    }
    expect((float) $results[0]->getAttribute('distance'))->toBe(0.0);
    expect((float) $results[2]->getAttribute('distance'))->toBeBetween(0.39999, 0.40001);
    expect((float) $results[3]->getAttribute('distance'))->toBe(1.0);
    expect($results[0]->skills->pluck('id')->all())->toBe([$skill->id]);
});

test('matching respects the profile arrangement salary floor expiry and given country', function () {
    $this->freezeTime();
    ExchangeRate::factory()->create(['currency' => 'USD', 'rate' => 1.1]);
    $vector = '['.implode(',', [1, ...array_fill(0, 1535, 0)]).']';
    $postings = JobPosting::factory()->remote()->withEmbedding()->state([
        'embedding' => $vector, 'expires_at' => null, 'salary_min' => 80_000, 'salary_max' => 100_000,
        'annual_salary_min' => 80_000, 'annual_salary_max' => 100_000,
    ]);
    $included = $postings->create();
    $postings->create(['work_arrangement' => WorkArrangement::Onsite]);
    $postings->expired()->create();
    $postings->create([
        'salary_min' => 10_000, 'salary_max' => 20_000, 'annual_salary_min' => 10_000, 'annual_salary_max' => 20_000,
    ]);
    $postings->create(['country_code' => 'US', 'country_codes' => ['US']]);
    $profile = Profile::factory()->remoteOnly()->make(['min_salary' => 60_000, 'country_code' => 'US']);

    $results = app(MatchOpenings::class)->handle($profile, $vector, 'ID');

    expect($results->pluck('id')->all())->toBe([$included->id]);
    expect($results[0]->getAttribute('eligibility'))->toBe('open');
});

test('postings without vectors are excluded and an opening with two live postings appears once', function () {
    $this->freezeTime();
    $zeros = array_fill(0, 1534, 0);
    $vector = '['.implode(',', [1, 0, ...$zeros]).']';
    JobPosting::factory()->remote()->create(['embedding' => null, 'expires_at' => null]);
    $opening = Opening::factory()->create();
    $representative = JobPosting::factory()->for($opening)->remote()->withoutSalary()->withEmbedding()->create([
        'source' => 'himalayas', 'embedding' => '['.implode(',', [0, 1, ...$zeros]).']',
        'expires_at' => null, 'posted_at' => now()->subDays(2),
    ]);
    $pay = JobPosting::factory()->for($opening)->remote()->withEmbedding()->create([
        'source' => 'jobicy', 'embedding' => $vector, 'expires_at' => null, 'posted_at' => now()->subDay(),
    ]);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();

    $results = app(MatchOpenings::class)->handle($profile, $vector, null);

    expect($results->pluck('id')->all())->toBe([$representative->id]);
    expect((float) $results[0]->getAttribute('distance'))->toBe(1.0);
    foreach (['salary_min', 'salary_max', 'salary_currency', 'salary_period', 'annual_salary_min', 'annual_salary_max'] as $column) {
        expect($results[0]->getAttribute('pay_'.$column))->toEqual($pay->getRawOriginal($column));
    }
});

test('matching returns at most thirty openings by default and respects a custom limit', function () {
    $this->freezeTime();
    $vector = '['.implode(',', [1, ...array_fill(0, 1535, 0)]).']';
    $postings = JobPosting::factory()->remote()->withEmbedding()->count(31)->create([
        'embedding' => $vector, 'expires_at' => null,
    ]);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();
    $action = app(MatchOpenings::class);

    $defaultResults = $action->handle($profile, $vector, null);
    $limitedResults = $action->handle($profile, $vector, null, 2);

    expect($defaultResults)->toHaveCount(30);
    expect($limitedResults->pluck('id')->all())->toBe($postings->reverse()->take(2)->pluck('id')->all());
});

test('keyword matching orders postings by distinct matched terms', function () {
    $postings = JobPosting::factory()->remote()->state(['description' => 'Nothing else', 'expires_at' => null]);
    $one = $postings->create(['title' => 'Laravel developer']);
    $two = $postings->create(['title' => 'Laravel PHP developer']);
    $postings->create(['title' => 'Designer']);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();

    $results = app(MatchOpenings::class)->handle($profile, null, null, 30, ['Laravel', 'PHP', 'Laravel']);

    expect($results->pluck('id')->all())->toBe([$two->id, $one->id]);
    expect($results->pluck('distance')->all())->toBe([null, null]);
});

test('hybrid fusion promotes postings found by both legs and retains low semantic ranks', function () {
    $zeros = array_fill(0, 1534, 0);
    $vector = '['.implode(',', [1, 0, ...$zeros]).']';
    $postings = JobPosting::factory()->remote()->withEmbedding()->state(['description' => 'Other', 'expires_at' => null]);
    $both = $postings->create(['title' => 'Laravel developer', 'embedding' => $vector]);
    $postings->count(50)->create(['title' => 'Designer', 'embedding' => '['.implode(',', [3, 4, ...$zeros]).']']);
    $keyword = $postings->create(['title' => 'Laravel developer', 'embedding' => '['.implode(',', [0, 1, ...$zeros]).']']);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();
    $action = app(MatchOpenings::class);

    $semantic = $action->handle($profile, $vector, null, 60);
    $hybrid = $action->handle($profile, $vector, null, 30, ['Laravel']);

    expect($semantic->pluck('id')->last())->toBe($keyword->id);
    expect($hybrid->first()->id)->toBe($both->id);
    expect($hybrid->pluck('id')->all())->toContain($keyword->id);
});

test('keyword matching applies expiry arrangement and country filters', function () {
    $postings = JobPosting::factory()->remote()->state(['title' => 'Laravel developer', 'expires_at' => null]);
    $included = $postings->create();
    $postings->expired()->create();
    $postings->create(['work_arrangement' => WorkArrangement::Onsite]);
    $postings->create(['country_code' => 'US', 'country_codes' => ['US']]);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();

    $results = app(MatchOpenings::class)->handle($profile, null, 'ID', 30, ['Laravel']);

    expect($results->pluck('id')->all())->toBe([$included->id]);
});

test('postings without vectors are eligible for keyword and hybrid but not semantic matching', function () {
    $vector = '['.implode(',', [1, ...array_fill(0, 1535, 0)]).']';
    $posting = JobPosting::factory()->remote()->create(['title' => 'Laravel developer', 'embedding' => null, 'expires_at' => null]);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();
    $action = app(MatchOpenings::class);

    expect($action->handle($profile, null, null, 30, ['Laravel'])->pluck('id')->all())->toBe([$posting->id]);
    expect($action->handle($profile, $vector, null, 30, ['Laravel'])->pluck('id')->all())->toBe([$posting->id]);
    expect($action->handle($profile, $vector, null)->pluck('id')->all())->toBe([]);
});

test('punctuation and empty terms are safe and an empty list preserves semantic matching', function () {
    $vector = '['.implode(',', [1, ...array_fill(0, 1535, 0)]).']';
    $postings = JobPosting::factory()->remote()->withEmbedding()->state(['description' => 'Work', 'embedding' => $vector, 'expires_at' => null]);
    $postings->create(['title' => 'C developer']);
    $cpp = $postings->create(['title' => 'C++ developer']);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();
    $action = app(MatchOpenings::class);

    expect($action->handle($profile, null, null, 30, ['C++', '   ', '!!!'])->pluck('id')->all())->toBe([$cpp->id]);
    expect($action->handle($profile, $vector, null, 30, [])->pluck('id')->all())->toBe($action->handle($profile, $vector, null)->pluck('id')->all());
});

test('technical names keep their own token in the column and in the terms', function (string $term, string $title) {
    $postings = JobPosting::factory()->remote()->state(['description' => 'Work', 'embedding' => null, 'expires_at' => null]);
    $titles = ['C developer', 'C# developer', 'C++ developer', '.NET developer', 'Node.js developer', 'Network engineer'];
    $ids = collect($titles)->mapWithKeys(fn (string $title): array => [$title => $postings->create(['title' => $title])->id]);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();

    $results = app(MatchOpenings::class)->handle($profile, null, null, 30, [$term]);

    expect($results->pluck('id')->all())->toBe([$ids[$title]]);
})->with([
    'C' => ['C', 'C developer'],
    'C#' => ['c#', 'C# developer'],
    'C++' => ['C++', 'C++ developer'],
    '.NET' => ['.net', '.NET developer'],
    'Node.js' => ['NodeJS', 'Node.js developer'],
]);

test('the search vector splits technical names joined by a slash and keeps host names', function () {
    $posting = JobPosting::factory()->create([
        'title' => 'C/C++ and C#/.NET', 'description' => 'ASP.NET at example.net', 'embedding' => null,
    ]);

    $vector = DB::table('job_postings')->where('id', $posting->id)->value(DB::raw('strip(search_vector)::text'));

    expect($vector)->toBe("'and' 'asp' 'at' 'c' 'cpp' 'csharp' 'dotnet' 'example.net'");
});

test('every filtered posting keeps its keyword rank without a cap on the leg', function () {
    $postings = JobPosting::factory()->remote()->state(['description' => 'Work', 'embedding' => null, 'expires_at' => null]);
    $postings->count(55)->create(['title' => 'Laravel PHP developer']);
    $target = $postings->create(['title' => 'Laravel developer']);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();

    $results = app(MatchOpenings::class)->handle($profile, null, null, 100, ['Laravel', 'PHP']);

    expect($results)->toHaveCount(56);
    expect($results->last()->id)->toBe($target->id);
    expect((int) $results->last()->getAttribute('keyword_rank'))->toBe(56);
});

test('generated search vectors include embedded skills and tolerate missing embedded text', function () {
    $skills = JobPosting::factory()->withEmbedding()->create(['title' => 'Engineer', 'description' => 'Work', 'embedded_text' => "Title\nSkills: Laravel, PHP"]);
    $title = JobPosting::factory()->create(['title' => 'Laravel engineer', 'description' => 'Work', 'embedding' => null]);
    $description = JobPosting::factory()->create(['title' => 'Engineer', 'description' => 'Laravel work', 'embedding' => null]);

    $ids = JobPosting::query()->whereKey([$skills->id, $title->id, $description->id])
        ->whereRaw("search_vector @@ phraseto_tsquery('simple', 'laravel')")->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe([$skills->id, $title->id, $description->id]);
});

test('IDF lets a rare term outrank two common ones, and count keeps the old order', function () {
    $postings = JobPosting::factory()->remote()->state(['description' => 'Work', 'embedding' => null, 'expires_at' => null]);
    $rare = $postings->create(['title' => 'Laravel developer']);
    $common = $postings->create(['title' => 'Docker Redis engineer']);
    $postings->count(6)->create(['title' => 'Docker Redis ops']);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();
    $action = app(MatchOpenings::class);
    $terms = ['Laravel', 'Docker', 'Redis'];

    $count = $action->handle($profile, null, null, 30, $terms);
    $idf = $action->handle($profile, null, null, 30, $terms, KeywordWeighting::Idf);

    expect($count->pluck('id')->search($common->id))->toBeLessThan($count->pluck('id')->search($rare->id))
        ->and($idf->first()->id)->toBe($rare->id)
        ->and(round((float) $idf->first()->getAttribute('keyword_weight'), 4))->toBe(round(log(8), 4));
});

test('IDF times proficiency lowers a rare beginner skill below a rare expert one', function () {
    $postings = JobPosting::factory()->remote()->state(['description' => 'Work', 'embedding' => null, 'expires_at' => null]);
    $rust = $postings->create(['title' => 'Rust developer']);
    $laravel = $postings->create(['title' => 'Laravel developer']);
    $postings->count(4)->create(['title' => 'Designer']);
    $profile = Profile::factory()->remoteOnly()->withoutSalary()->make();
    $action = app(MatchOpenings::class);
    $terms = ['Rust', 'Laravel'];

    $idf = $action->handle($profile, null, null, 30, $terms, KeywordWeighting::Idf);
    $weighted = $action->handle($profile, null, null, 30, $terms, KeywordWeighting::IdfProficiency, ['Rust' => 0.5, 'Laravel' => 1.0]);

    expect($idf->pluck('id')->all())->toBe([$laravel->id, $rust->id])
        ->and($weighted->pluck('id')->all())->toBe([$laravel->id, $rust->id])
        ->and(round((float) $weighted[1]->getAttribute('keyword_weight'), 4))->toBe(round(0.5 * log(6), 4));

    $flipped = $action->handle($profile, null, null, 30, $terms, KeywordWeighting::IdfProficiency, ['Rust' => 1.0, 'Laravel' => 0.5]);

    expect($flipped->pluck('id')->all())->toBe([$rust->id, $laravel->id]);
});
