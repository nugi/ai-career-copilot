<?php

use App\Actions\Jobs\ImportJobPostings;
use App\Enums\EmploymentType;
use App\Enums\SalaryPeriod;
use App\Enums\SeniorityLevel;
use App\JobSources\FakeJobSource;
use App\JobSources\JobPostingData;
use App\JobSources\JobSourceRegistry;
use App\JobSources\Normalization\DuplicateKey;
use App\JobSources\Normalization\PostingNormalizer;
use App\Models\JobPosting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Load a source response without changing the fixture on disk.
 *
 * @return array<string, mixed>
 */
function importJobFixture(string $source, string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/job-sources/{$source}/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Serve the same shared job on both boards and the remaining fixture postings.
 */
function fakeImportJobSources(): void
{
    $jobicy = importJobFixture('jobicy', 'page');
    $jobicy['jobs'][0]['companyName'] = 'Northwind Systems Inc.';
    $jobicy['jobs'][0]['jobTitle'] = 'Sr. Backend Engineer';
    $jobicy['jobs'][0]['jobGeo'] = 'USA';

    Http::fake([
        'himalayas.app/jobs/api/search*' => Http::response(importJobFixture('himalayas', 'page')),
        'jobicy.com/api/jobs*' => fn (Request $request) => Http::response(
            str_contains($request->url(), 'cursor=') ? importJobFixture('jobicy', 'last-page') : $jobicy,
        ),
    ]);
}

beforeEach(function () {
    config([
        'job-sources.default_queries' => ['backend engineer'],
        'job-sources.himalayas.pages_per_query' => 1,
        'job-sources.jobicy.pages_per_query' => 2,
    ]);

    Http::preventStrayRequests();
});

test('running both sources twice creates no duplicates', function () {
    $this->freezeTime();
    fakeImportJobSources();
    $sources = app(JobSourceRegistry::class);
    $import = app(ImportJobPostings::class);
    $import->handle($sources->get('himalayas'));
    $firstJobicy = $import->handle($sources->get('jobicy'));
    $firstCount = JobPosting::query()->count();
    $beforeSecondRun = JobPosting::query()->orderBy('id')->get()->keyBy('id');
    $firstHimalayas = JobPosting::query()->where('source', 'himalayas')->count();
    $firstJobicyRows = JobPosting::query()->where('source', 'jobicy')->count();

    $this->travel(1)->hours();

    $secondHimalayas = $import->handle($sources->get('himalayas'));
    $secondJobicy = $import->handle($sources->get('jobicy'));

    $this->assertDatabaseCount('job_postings', $firstCount);
    expect($firstCount)->toBe(5);
    expect($secondHimalayas->created)->toBe(0)
        ->and($secondHimalayas->updated)->toBe(0)
        ->and($secondHimalayas->unchanged)->toBe($firstHimalayas)
        ->and($secondJobicy->created)->toBe(0)
        ->and($secondJobicy->updated)->toBe(0)
        ->and($secondJobicy->unchanged)->toBe($firstJobicyRows);

    foreach ($beforeSecondRun as $posting) {
        $current = $posting->fresh();
        expect($current->first_seen_at->equalTo($posting->first_seen_at))->toBeTrue()
            ->and($current->updated_at->equalTo($posting->updated_at))->toBeTrue()
            ->and($current->last_seen_at->greaterThan($posting->last_seen_at))->toBeTrue();
    }
    expect(JobPosting::query()->select('source', 'dedup_key')->groupBy('source', 'dedup_key')->havingRaw('count(*) > 1')->get())->toBeEmpty();
    $shared = JobPosting::query()->where('normalized_company', 'northwind systems')->where('normalized_title', 'senior backend engineer');
    expect((clone $shared)->count())->toBe(2);
    $canonical = (clone $shared)->canonical()->sole();
    $duplicate = (clone $shared)->whereNotNull('duplicate_of_id')->sole();
    expect($duplicate->duplicate_of_id)->toBe($canonical->id);
    expect($duplicate->duplicateOf->is($canonical))->toBeTrue();
    expect($duplicate->duplicate_reason)->toBe('exact');
    expect($duplicate->duplicate_similarity)->toBeNull();
    expect($firstJobicy->linksChanged)->toBe(1);
    expect($secondHimalayas->linksChanged)->toBe(0);
    expect($secondJobicy->linksChanged)->toBe(0);
    Http::assertSentCount(6);
});

test('a stored posting keeps its places and pay', function () {
    $this->freezeTime();
    Http::fake([
        'jobicy.com/api/jobs*' => Http::sequence()
            ->push(importJobFixture('jobicy', 'page'))
            ->push(importJobFixture('jobicy', 'last-page')),
    ]);

    $report = app(ImportJobPostings::class)->handle(app(JobSourceRegistry::class)->get('jobicy'));

    $posting = JobPosting::query()->where('source', 'jobicy')->where('external_id', '900103')->sole()->fresh();
    expect($posting->country_codes)->toBe(['CA', 'US']);
    expect(array_is_list($posting->country_codes))->toBeTrue();
    expect($posting->salary_currency)->toBe('USD');
    expect($posting->annual_salary_min)->toBe(212000);
    expect($report->source)->toBe('jobicy');
    expect($report->fetch->yielded)->toBe(3);
    expect($report->normalized)->toBe(3);
    expect($report->skipped)->toBe(0);
    expect($report->created)->toBe(3);
    Http::assertSentCount(2);
});

test('a posting too long for its columns does not stop the run', function () {
    $this->freezeTime();
    $import = app(ImportJobPostings::class);
    $posting = fn (string $externalId, string $title): JobPostingData => new JobPostingData(
        source: 'fake',
        externalId: $externalId,
        title: $title,
        companyName: 'Northwind Systems',
        applyUrl: "https://example.com/jobs/{$externalId}",
        descriptionHtml: '<p>Own the billing API and its queue workers.</p>',
        postedAt: CarbonImmutable::parse('2026-10-20 09:00:00'),
        expiresAt: CarbonImmutable::parse('2026-11-19 09:00:00'),
        remoteType: 'remote',
    );

    $report = $import->handle(new FakeJobSource([
        $posting('fake-normal', 'Senior Laravel Engineer'),
        $posting('fake-overlong', str_repeat('a', 256)),
    ]));

    expect($report->created)->toBe(1)
        ->and($report->skipped)->toBe(1)
        ->and(JobPosting::query()->where('source', 'fake')->count())->toBe(1);
    Http::assertNothingSent();
});

test('the next posting takes over when the canonical one expires', function () {
    $this->freezeTime();
    fakeImportJobSources();
    $sources = app(JobSourceRegistry::class);
    $import = app(ImportJobPostings::class);
    $import->handle($sources->get('himalayas'));
    $import->handle($sources->get('jobicy'));
    $shared = JobPosting::query()->where('normalized_company', 'northwind systems')->where('normalized_title', 'senior backend engineer');
    $canonical = (clone $shared)->whereNull('duplicate_of_id')->sole();
    $duplicate = (clone $shared)->whereNotNull('duplicate_of_id')->sole();
    $canonical->update(['expires_at' => now()->subDay()]);

    $report = $import->handle(new FakeJobSource([]));

    expect($duplicate->fresh()->duplicate_of_id)->toBeNull();
    expect($canonical->fresh()->duplicate_of_id)->toBeNull();
    expect($report->linksChanged)->toBe(1);
    Http::assertSentCount(3);
});

test('near-duplicate titles at the same company are linked', function () {
    $this->freezeTime();
    $postings = [];

    foreach (['Senior Backend Engineer, Laravel', 'Senior Backend Engineer (Laravel/PHP)'] as $title) {
        $company = DuplicateKey::company('Northwind Systems Inc.');
        $normalizedTitle = DuplicateKey::title($title);
        $postings[] = JobPosting::factory()->create([
            'title' => $title,
            'company_name' => 'Northwind Systems Inc.',
            'normalized_company' => $company,
            'normalized_title' => $normalizedTitle,
            'location_key' => 'US',
            'duplicate_key' => DuplicateKey::hash($company, $normalizedTitle, 'US'),
            'seniority' => SeniorityLevel::Senior,
            'expires_at' => null,
        ]);
    }

    $import = app(ImportJobPostings::class);
    $first = $import->handle(new FakeJobSource([]));
    $second = $import->handle(new FakeJobSource([]));

    expect($postings[0]->fresh()->duplicate_of_id)->toBeNull();
    expect($postings[0]->fresh()->duplicate_reason)->toBeNull();
    expect($postings[0]->fresh()->duplicate_similarity)->toBeNull();
    expect($postings[1]->fresh()->duplicate_of_id)->toBe($postings[0]->id);
    expect($postings[1]->fresh()->duplicate_reason)->toBe('trigram');
    expect($postings[1]->fresh()->duplicate_similarity)->toBe(0.889);
    expect($first->linksChanged)->toBe(1);
    expect($second->linksChanged)->toBe(0);
    Http::assertNothingSent();
});

test('a changed description counts as one update', function () {
    $this->freezeTime();
    $posting = fn (string $descriptionHtml): JobPostingData => new JobPostingData(
        source: 'fake',
        externalId: 'fake-description',
        title: 'Senior Laravel Engineer',
        companyName: 'Northwind Systems',
        applyUrl: 'https://example.com/jobs/fake-description',
        descriptionHtml: $descriptionHtml,
        postedAt: CarbonImmutable::parse('2026-10-20 09:00:00'),
        expiresAt: CarbonImmutable::parse('2026-11-19 09:00:00'),
        remoteType: 'remote',
    );
    $stored = fn (): JobPosting => JobPosting::query()->where('source', 'fake')->where('external_id', 'fake-description')->sole();
    $import = app(ImportJobPostings::class);

    $import->handle(new FakeJobSource([$posting('<p>Own the billing API.</p>')]));
    $firstSeenAt = $stored()->first_seen_at;
    $updatedAt = $stored()->updated_at;

    $this->travel(1)->hours();

    $report = $import->handle(new FakeJobSource([$posting('<p>Own the billing API and its queue workers.</p>')]));

    expect($report->created)->toBe(0)
        ->and($report->updated)->toBe(1)
        ->and($report->unchanged)->toBe(0)
        ->and($stored()->updated_at->greaterThan($updatedAt))->toBeTrue()
        ->and($stored()->first_seen_at->equalTo($firstSeenAt))->toBeTrue();
    Http::assertNothingSent();
});

test('a later run without pay keeps the stored pay', function () {
    $this->freezeTime();
    $import = app(ImportJobPostings::class);
    $posting = fn (array $overrides): JobPostingData => new JobPostingData(...[
        'source' => 'fake',
        'externalId' => 'fake-pay',
        'title' => 'Senior Laravel Engineer',
        'companyName' => 'Northwind Systems',
        'applyUrl' => 'https://example.com/jobs/fake-pay',
        'descriptionHtml' => '<p>Own the billing API and its queue workers.</p>',
        'postedAt' => CarbonImmutable::parse('2026-10-20 09:00:00'),
        'expiresAt' => CarbonImmutable::parse('2026-11-19 09:00:00'),
        'remoteType' => 'remote',
        ...$overrides,
    ]);
    $stored = fn (): JobPosting => JobPosting::query()->where('source', 'fake')->where('external_id', 'fake-pay')->sole();

    $import->handle(new FakeJobSource([$posting([
        'salaryMin' => 80000.0,
        'salaryMax' => 100000.0,
        'salaryCurrency' => 'USD',
        'salaryPeriod' => 'yearly',
        'employmentType' => 'Full Time',
    ])]));
    $import->handle(new FakeJobSource([$posting([])]));

    expect($stored()->salary_min)->toBe(80000)
        ->and($stored()->salary_max)->toBe(100000)
        ->and($stored()->salary_currency)->toBe('USD')
        ->and($stored()->salary_period)->toBe(SalaryPeriod::Yearly)
        ->and($stored()->annual_salary_min)->toBe(80000)
        ->and($stored()->annual_salary_max)->toBe(100000)
        ->and($stored()->salary_normalization_version)->toBe(PostingNormalizer::NORMALIZATION_VERSION)
        ->and($stored()->employment_type)->toBe(EmploymentType::FullTime);

    $import->handle(new FakeJobSource([$posting([
        'salaryMax' => 120000.0,
        'salaryCurrency' => 'EUR',
        'salaryPeriod' => 'yearly',
    ])]));

    expect($stored()->salary_min)->toBeNull()
        ->and($stored()->salary_max)->toBe(120000)
        ->and($stored()->salary_currency)->toBe('EUR');
    Http::assertNothingSent();
});

test('different roles or companies are not linked', function (string $firstTitle, string $secondTitle, string $firstCompany, string $secondCompany, ?SeniorityLevel $firstSeniority, ?SeniorityLevel $secondSeniority) {
    $this->freezeTime();
    $first = JobPosting::factory()->create([
        'title' => $firstTitle,
        'company_name' => $firstCompany,
        'seniority' => $firstSeniority,
        'location_key' => 'US',
        'expires_at' => null,
    ]);
    $second = JobPosting::factory()->create([
        'title' => $secondTitle,
        'company_name' => $secondCompany,
        'seniority' => $secondSeniority,
        'location_key' => 'US',
        'expires_at' => null,
    ]);

    $report = app(ImportJobPostings::class)->handle(new FakeJobSource([]));

    expect($first->fresh()->duplicate_of_id)->toBeNull();
    expect($second->fresh()->duplicate_of_id)->toBeNull();
    expect($report->linksChanged)->toBe(0);
    Http::assertNothingSent();
})->with([
    'different roles' => ['Senior Backend Engineer', 'Senior Frontend Engineer', 'Northwind Systems', 'Northwind Systems', SeniorityLevel::Senior, SeniorityLevel::Senior],
    'different companies' => ['Senior Backend Engineer', 'Senior Backend Engineer', 'Northwind Systems', 'Baltic Data', SeniorityLevel::Senior, SeniorityLevel::Senior],
    'a level word in one title only' => ['Software Engineer, Growth', 'Senior Software Engineer, Growth', 'Northwind Systems', 'Northwind Systems', null, null],
    'conflicting seniority' => ['Junior Data Engineer', 'Senior Data Engineer', 'Northwind Systems', 'Northwind Systems', SeniorityLevel::Junior, SeniorityLevel::Senior],
]);
