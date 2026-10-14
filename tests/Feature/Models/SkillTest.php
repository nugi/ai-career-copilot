<?php

use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\SkillAlias;
use Illuminate\Database\UniqueConstraintViolationException;

test('a skill keeps its display name and stores a normalized name for lookups', function () {
    $skill = Skill::factory()->create(['name' => '  Tailwind   CSS ']);

    expect($skill->only('name', 'normalized_name'))->toBe([
        'name' => 'Tailwind CSS',
        'normalized_name' => 'tailwind css',
    ]);
});

test('a skill cannot be added twice under a different case or spacing', function () {
    Skill::factory()->create(['name' => 'PostgreSQL']);

    expect(fn () => Skill::factory()->create(['name' => ' postgresql ']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('aliases are stored normalized and resolve to their skill', function () {
    $skill = Skill::factory()->create(['name' => 'Kubernetes']);
    $skill->aliases()->create(['alias' => ' K8s ']);

    $resolved = SkillAlias::query()->where('alias', 'k8s')->sole()->skill;

    expect($resolved->is($skill))->toBeTrue();
});

test('an alias can point to only one skill', function () {
    SkillAlias::factory()->create(['alias' => 'js']);

    expect(fn () => SkillAlias::factory()->create(['alias' => 'JS']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a skill lists the job postings that ask for it', function () {
    $skill = Skill::factory()->create();
    $posting = JobPosting::factory()->create();
    $posting->skills()->attach($skill, ['is_required' => false]);

    $listed = $skill->jobPostings()->sole();

    expect($listed->is($posting))->toBeTrue()
        ->and($listed->pivot->is_required)->toBeFalse();
});
