<?php

use App\Models\JobPosting;

test('unknown skills are listed by the number of postings', function () {
    JobPosting::factory()->create([
        'extracted_skills' => [
            'required' => [],
            'nice_to_have' => [],
            'unknown' => ['FooQL', 'Zeta'],
            'ungrounded' => [],
        ],
    ]);

    JobPosting::factory()->create([
        'extracted_skills' => [
            'required' => [],
            'nice_to_have' => [],
            'unknown' => ['FooQL'],
            'ungrounded' => [],
        ],
    ]);

    $this->artisan('skills:unknown')
        ->expectsTable(
            ['Name', 'Postings'],
            [
                ['fooql', 2],
                ['zeta', 1],
            ],
        )
        ->assertExitCode(0);
});
