<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deterministic Fit Scoring
    |--------------------------------------------------------------------------
    |
    | Change the version when a weight or a rule changes, so stored scores show which rules made them.
    |
    */

    'version' => 'v1',
    'weights' => [
        'required_skills' => 40,
        'nice_skills' => 10,
        'seniority' => 20,
        'salary' => 15,
        'location' => 15,
    ],
    'seniority_years' => ['junior' => 0, 'mid' => 2, 'senior' => 5, 'lead' => 8],
    'salary_zero_below' => 0.8,
    'eligibility' => ['open' => 1.0, 'unclear' => 0.5, 'restricted' => 0.0, 'excluded' => 0.0],
];
