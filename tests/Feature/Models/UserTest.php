<?php

use App\Enums\SkillProficiency;
use App\Enums\SkillSource;
use App\Models\AiUsage;
use App\Models\Profile;
use App\Models\Resume;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('a user has one profile', function () {
    $user = User::factory()->create();
    Profile::factory()->for($user)->create();

    expect(fn () => Profile::factory()->for($user)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the primary resume is the one marked primary', function () {
    $user = User::factory()->create();
    Resume::factory()->for($user)->create();
    $primary = Resume::factory()->for($user)->primary()->create();

    expect($user->primaryResume->is($primary))->toBeTrue();
});

test('a user cannot have two primary resumes', function () {
    $user = User::factory()->create();
    Resume::factory()->for($user)->primary()->create();

    expect(fn () => Resume::factory()->for($user)->primary()->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('different users can each have a primary resume', function () {
    Resume::factory()->primary()->create();

    $second = Resume::factory()->primary()->create();

    $this->assertModelExists($second);
});

test('a user lists their skills with a proficiency', function () {
    $user = User::factory()->create();
    $user->skills()->attach(Skill::factory()->create(), ['proficiency' => SkillProficiency::Advanced, 'source' => SkillSource::Profile]);

    expect($user->skills()->sole()->pivot->proficiency)->toBe(SkillProficiency::Advanced);
});

test('deleting a user removes their data but keeps their AI usage for cost reporting', function () {
    $user = User::factory()->create();
    $profile = Profile::factory()->for($user)->create();
    $resume = Resume::factory()->for($user)->create();
    $user->skills()->attach(Skill::factory()->create(), ['proficiency' => SkillProficiency::Beginner, 'source' => SkillSource::Profile]);
    $usage = AiUsage::factory()->for($user)->create();

    $user->delete();

    $this->assertModelMissing($profile);
    $this->assertModelMissing($resume);
    $this->assertDatabaseMissing('user_skills', ['user_id' => $user->id]);
    expect($usage->fresh()->user_id)->toBeNull();
});
