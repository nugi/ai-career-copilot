<?php

use App\Actions\Skills\RankSkillGaps;
use App\Actions\Skills\SkillGap;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Carbon;

test('a required skill ranks above a nice-to-have skill listed by more jobs', function (): void {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00'));
    $user = User::factory()->create();
    $required = Skill::factory()->unverified()->create(['name' => 'PostgreSQL']);
    $nice = Skill::factory()->unverified()->create(['name' => 'Kubernetes']);
    $postings = JobPosting::factory()->count(4)->create(['posted_at' => now(), 'skills_extracted_at' => now()]);
    $postings[0]->skills()->attach($required, ['is_required' => true]);

    foreach ($postings->skip(1) as $posting) {
        $posting->skills()->attach($nice, ['is_required' => false]);
    }

    $gaps = app(RankSkillGaps::class)->handle($user, $postings->mapWithKeys(fn (JobPosting $posting): array => [$posting->id => 80])->all());

    expect(array_map(fn (SkillGap $gap): array => $gap->toArray(), $gaps))->toBe([
        ['skill' => 'PostgreSQL', 'weight' => 0.8, 'required_jobs' => 1, 'nice_jobs' => 0, 'jobs' => 1, 'of' => 4],
        ['skill' => 'Kubernetes', 'weight' => 0.6, 'required_jobs' => 0, 'nice_jobs' => 3, 'jobs' => 3, 'of' => 4],
    ]);
});

test('a posting fourteen days old has half the weight of a new posting', function (): void {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00'));
    $user = User::factory()->create();
    $newSkill = Skill::factory()->unverified()->create(['name' => 'PostgreSQL']);
    $oldSkill = Skill::factory()->unverified()->create(['name' => 'Redis']);
    $new = JobPosting::factory()->create(['posted_at' => now(), 'skills_extracted_at' => now()]);
    $old = JobPosting::factory()->create(['posted_at' => now()->subDays(14), 'skills_extracted_at' => now()]);
    $new->skills()->attach($newSkill, ['is_required' => true]);
    $old->skills()->attach($oldSkill, ['is_required' => true]);

    $gaps = app(RankSkillGaps::class)->handle($user, [$new->id => 80, $old->id => 80]);

    expect($gaps)->toHaveCount(2);
    expect($gaps[0]->skill)->toBe('PostgreSQL')->and($gaps[0]->weight)->toBe(0.8);
    expect($gaps[1]->skill)->toBe('Redis')->and($gaps[1]->weight)->toBe(0.4);
});

test('skills the user has are excluded even when names differ in case', function (): void {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00'));
    $user = User::factory()->create();
    $known = Skill::factory()->unverified()->create(['name' => 'laravel', 'normalized_name' => 'legacy-laravel']);
    $laravel = Skill::factory()->unverified()->create(['name' => 'Laravel']);
    $php = Skill::factory()->unverified()->create(['name' => 'PHP']);
    $missing = Skill::factory()->unverified()->create(['name' => 'PostgreSQL']);
    $user->skills()->attach([$known->id, $php->id], ['source' => 'resume']);
    $posting = JobPosting::factory()->create(['posted_at' => now(), 'skills_extracted_at' => now()]);
    $posting->skills()->attach([$laravel->id, $php->id, $missing->id], ['is_required' => true]);

    $gaps = app(RankSkillGaps::class)->handle($user, [$posting->id => 80]);

    expect(array_column($gaps, 'skill'))->toBe(['PostgreSQL']);
});

test('of counts only matches whose skills have been extracted', function (): void {
    $this->travelTo(Carbon::parse('2026-11-15 12:00:00'));
    $user = User::factory()->create();
    $skill = Skill::factory()->unverified()->create(['name' => 'PostgreSQL']);
    $extracted = JobPosting::factory()->create(['posted_at' => now(), 'skills_extracted_at' => now()]);
    $empty = JobPosting::factory()->create(['posted_at' => now(), 'skills_extracted_at' => now()]);
    $pending = JobPosting::factory()->create(['posted_at' => now(), 'skills_extracted_at' => null]);
    $extracted->skills()->attach($skill, ['is_required' => true]);
    $pending->skills()->attach($skill, ['is_required' => true]);

    $gaps = app(RankSkillGaps::class)->handle($user, [$extracted->id => 80, $empty->id => 80, $pending->id => 80]);

    expect($gaps)->toHaveCount(1);
    expect($gaps[0]->of)->toBe(2)->and($gaps[0]->jobs)->toBe(1)->and($gaps[0]->weight)->toBe(0.8);
});
