<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Apply the restricting-word check (ADR 0029, point 7) to readings saved before the check existed. The pattern is a
 * copy of App\Actions\Jobs\RestrictionWording::PATTERN at the time of this migration, in PostgreSQL syntax, so the
 * migration keeps its meaning when the class changes. A dropped reading leaves the posting unclear, as the extractor
 * would now save it. There is no down: the dropped readings are not kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update(<<<'SQL'
            update job_postings
            set eligible_countries = '[]'::jsonb, eligible_regions = '[]'::jsonb, eligibility_evidence = null
            where eligibility_evidence is not null
                and eligibility_evidence !~* ?
            SQL, ['\m(only|must|require[sd]?|authori[sz]ed|eligible|eligibility|limited to|restricted to'
            .'|open to (candidates|applicants|residents|people|those)|residents?|residing|citizens?|citizenship)\M']);
    }
};
