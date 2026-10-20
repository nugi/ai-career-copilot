<?php

use App\Models\Profile;
use App\Models\Resume;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $resume = Resume::factory()->create(['content' => 'Redacted text']);

    $this->get(route('resumes.content', $resume))->assertRedirect(route('login'));
});

test('users who have not finished onboarding are sent to it from the resume text', function (User $user) {
    $resume = Resume::factory()->for($user)->create(['content' => 'Redacted text']);

    $this->actingAs($user)->get(route('resumes.content', $resume))->assertRedirect(route('onboarding.edit'));
})->with([
    'no profile' => fn () => User::factory()->create(),
    'incomplete profile' => fn () => User::factory()->has(Profile::factory()->incomplete())->create(),
]);

test('the owner gets the redacted text of a resume as JSON', function () {
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->for($user)->create(['content' => 'Backend engineer. Contact: [EMAIL]']);

    $this->actingAs($user)
        ->getJson(route('resumes.content', $resume))
        ->assertOk()
        ->assertExactJson(['content' => 'Backend engineer. Contact: [EMAIL]']);
});

test('another user gets 404 for a resume they do not own', function () {
    $owner = User::factory()->onboarded()->create();
    $resume = Resume::factory()->for($owner)->create(['content' => 'Private text']);
    $otherUser = User::factory()->onboarded()->create();

    $this->actingAs($otherUser)
        ->getJson(route('resumes.content', $resume))
        ->assertNotFound();
});

test('a resume ID that is not a number gives 404', function () {
    $this->actingAs(User::factory()->onboarded()->create())
        ->getJson('/resumes/abc/content')
        ->assertNotFound();
});
