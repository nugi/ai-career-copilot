<?php

use App\Enums\AiFeature;
use App\Enums\Currency;
use App\Enums\EmploymentType;
use App\Enums\JobRegion;
use App\Enums\ResumeSection;
use App\Enums\SalaryPeriod;
use App\Enums\SeniorityLevel;
use App\Enums\SkillProficiency;
use App\Enums\SkillSource;
use App\Enums\WorkArrangement;
use App\Models\AiUsage;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\ResumeChunk;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create a row in the given table and return the where clause that selects it.
 *
 * @return array<string, int>
 */
function rowIn(string $table): array
{
    return match ($table) {
        'job_postings' => ['id' => JobPosting::factory()->create()->id],
        'profiles' => ['id' => Profile::factory()->create()->id],
        'resumes' => ['id' => Resume::factory()->create()->id],
        'resume_chunks' => ['id' => ResumeChunk::factory()->create()->id],
        'ai_usages' => ['id' => AiUsage::factory()->create()->id],
        'user_skills' => (function (): array {
            $user = User::factory()->create();
            $skill = Skill::factory()->create();
            $user->skills()->attach($skill, ['proficiency' => SkillProficiency::Beginner, 'source' => SkillSource::Profile]);

            return ['user_id' => $user->id, 'skill_id' => $skill->id];
        })(),
    };
}

test('the database accepts every case of an enum column and nothing else', function (string $table, string $column, string $enum, ?array $cases = null) {
    $row = rowIn($table);

    foreach ($cases ?? $enum::cases() as $case) {
        DB::table($table)->where($row)->update([$column => $case->value]);
    }

    expect(fn () => DB::table($table)->where($row)->update([$column => 'zzz']))
        ->toThrow(QueryException::class, "{$table}_{$column}_check");
})->with([
    'job_postings.work_arrangement' => ['job_postings', 'work_arrangement', WorkArrangement::class],
    'job_postings.employment_type' => ['job_postings', 'employment_type', EmploymentType::class],
    'job_postings.seniority' => ['job_postings', 'seniority', SeniorityLevel::class],
    'job_postings.region' => ['job_postings', 'region', JobRegion::class],
    'job_postings.salary_period' => ['job_postings', 'salary_period', SalaryPeriod::class],
    'profiles.salary_currency' => ['profiles', 'salary_currency', Currency::class],
    'profiles.salary_period' => ['profiles', 'salary_period', SalaryPeriod::class, SalaryPeriod::profileCases()],
    'resume_chunks.section' => ['resume_chunks', 'section', ResumeSection::class],
    'user_skills.proficiency' => ['user_skills', 'proficiency', SkillProficiency::class],
    'user_skills.source' => ['user_skills', 'source', SkillSource::class],
    'ai_usages.feature' => ['ai_usages', 'feature', AiFeature::class],
]);

test('the database accepts a list of known work arrangements on a profile', function (string $json) {
    $row = rowIn('profiles');

    DB::table('profiles')->where($row)->update(['work_arrangements' => $json]);

    expect(json_decode(DB::table('profiles')->where($row)->value('work_arrangements')))->toBe(json_decode($json));
})->with([
    'empty' => '[]',
    'every case' => fn () => json_encode(array_column(WorkArrangement::cases(), 'value')),
]);

test('the database rejects invalid work arrangements on a profile', function (string $json) {
    $row = rowIn('profiles');

    expect(fn () => DB::table('profiles')->where($row)->update(['work_arrangements' => $json]))
        ->toThrow(QueryException::class, 'profiles_work_arrangements_check');
})->with([
    'unknown value' => '["remote", "office"]',
    'a single value instead of a list' => '"remote"',
    'an object' => '{"remote": true}',
]);

test('the database rejects a negative count, size or cost', function (string $table, string $column) {
    $row = rowIn($table);

    expect(fn () => DB::table($table)->where($row)->update([$column => -1]))
        ->toThrow(QueryException::class, "{$table}_{$column}_check");
})->with([
    'resumes.file_size' => ['resumes', 'file_size'],
    'resume_chunks.position' => ['resume_chunks', 'position'],
    'resume_chunks.token_count' => ['resume_chunks', 'token_count'],
    'ai_usages.input_tokens' => ['ai_usages', 'input_tokens'],
    'ai_usages.output_tokens' => ['ai_usages', 'output_tokens'],
    'ai_usages.latency_ms' => ['ai_usages', 'latency_ms'],
    'ai_usages.cost_usd' => ['ai_usages', 'cost_usd'],
]);

test('the database rejects a profile version below 1', function () {
    $row = rowIn('profiles');

    expect(fn () => DB::table('profiles')->where($row)->update(['version' => 0]))
        ->toThrow(QueryException::class, 'profiles_version_check');
});

test('the database accepts a resume skill without a proficiency and rejects a profile skill without one', function () {
    $user = User::factory()->create();

    $user->skills()->attach(Skill::factory()->create(), ['proficiency' => null, 'source' => SkillSource::Resume]);

    expect(fn () => $user->skills()->attach(Skill::factory()->create(), ['proficiency' => null, 'source' => SkillSource::Profile]))
        ->toThrow(QueryException::class, 'user_skills_proficiency_required_check');
});
