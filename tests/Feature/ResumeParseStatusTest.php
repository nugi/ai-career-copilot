<?php

use App\Models\Resume;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the resumes page returns the parse status of a pending, a parsed and a failed resume', function () {
    $user = User::factory()->onboarded()->create();
    Resume::factory()->extracted()->for($user)->create(['created_at' => now()->subMinutes(2)]);
    Resume::factory()->parsed()->for($user)->create(['created_at' => now()->subMinute()]);
    Resume::factory()->parseFailed()->for($user)->create();

    $this->actingAs($user)
        ->get(route('resumes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('resumes')
            ->has('resumes', 3)
            ->where('resumes.0.parse_status', 'failed')
            ->where('resumes.1.parse_status', 'parsed')
            ->where('resumes.2.parse_status', 'pending')
        );
});
