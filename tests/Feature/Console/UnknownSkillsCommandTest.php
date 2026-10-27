<?php

use App\Models\JobPosting;

test('unknown skills are listed by the number of postings', function () {
    JobPosting::factory()->create([
        'extracted_skills' => [
            'required' => [],
            'nice_to_have' => [],
            'unknown' => [
                ['name' => 'FooQL', 'normalized' => 'fooql', 'required' => true],
                ['name' => 'Zeta', 'normalized' => 'zeta', 'required' => false],
            ],
            'ungrounded' => [],
        ],
    ]);

    JobPosting::factory()->create([
        'extracted_skills' => [
            'required' => [],
            'nice_to_have' => [],
            'unknown' => [['name' => 'fooql', 'normalized' => 'fooql', 'required' => false]],
            'ungrounded' => [],
        ],
    ]);

    $this->artisan('skills:unknown')
        ->expectsTable(
            ['Name', 'Required', 'Nice to have', 'Postings'],
            [
                ['fooql', 1, 1, 2],
                ['Zeta', 0, 1, 1],
            ],
        )
        ->assertExitCode(0);
});
