<?php

namespace App\Enums;

/**
 * How the keyword leg scores the profile terms that a posting contains (ADR 0035).
 */
enum KeywordWeighting: string
{
    /** Every distinct term counts 1. */
    case Count = 'count';

    /** A term counts ln(N / df) over the filtered set, so a rare term counts more than a common one. */
    case Idf = 'idf';

    /** IDF times the user's proficiency in the skill: 1.0 for expert down to 0.5 for beginner. Roles count 1.0. */
    case IdfProficiency = 'idf_proficiency';
}
