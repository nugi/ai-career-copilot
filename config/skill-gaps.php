<?php

return [

    /*
    | Number of best matches to read before ranking their missing skills.
    */
    'top_matches' => 30,

    /*
    | A posting's weight halves for each fourteen days since it was posted.
    */
    'half_life_days' => 14,

    /*
    | Nice-to-have skills receive this factor; required skills receive 1.
    */
    'nice_to_have_weight' => 0.25,

    /*
    | Number of the highest-priority gaps sent to the learning planner.
    */
    'plan_gaps' => 5,

    /*
    | Minimum hours between two learning plans for the same user.
    */
    'plan_every_hours' => 24,
];
