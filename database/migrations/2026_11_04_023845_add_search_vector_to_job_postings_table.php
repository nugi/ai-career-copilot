<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            alter table job_postings add column search_vector tsvector generated always as (
                setweight(to_tsvector('simple'::regconfig, coalesce(title, '')), 'A')
                || setweight(to_tsvector('simple'::regconfig, split_part(coalesce(embedded_text, ''), E'\nSkills: ', 2)), 'B')
                || setweight(to_tsvector('simple'::regconfig, coalesce(description, '')), 'C')
            ) stored
            SQL);
    }

    public function down(): void
    {
        DB::statement('alter table job_postings drop column search_vector');
    }
};
