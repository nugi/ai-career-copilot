<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The `simple` parser turns "C#", "C++" and "C" into the token `c` and ".NET" into `net`. Rewrite those names before
 * the text is parsed, in the column and in the query (ADR 0031). A SQL function and not a custom text search
 * dictionary, because RDS does not install dictionary files. Each replacement is padded with spaces, so "C/C++" and
 * "C#/.NET" split into two tokens and are not read as a file path.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create function normalize_search_text(input text) returns text
            language sql immutable strict parallel safe
            return regexp_replace(regexp_replace(regexp_replace(regexp_replace(regexp_replace(input,
                '\mc#', ' csharp ', 'gi'),
                '\mc\+\+', ' cpp ', 'gi'),
                '\m(asp|vb|ado)\.net\M', '\1 dotnet ', 'gi'),
                '(^|[^[:alnum:]_.])\.net\M', '\1 dotnet ', 'gi'),
                '\mnode\.?js\M', ' nodejs ', 'gi')
            SQL);

        DB::statement('alter table job_postings drop column search_vector');
        DB::statement(<<<'SQL'
            alter table job_postings add column search_vector tsvector generated always as (
                setweight(to_tsvector('simple'::regconfig, normalize_search_text(coalesce(title, ''))), 'A')
                || setweight(to_tsvector('simple'::regconfig, normalize_search_text(split_part(coalesce(embedded_text, ''), E'\nSkills: ', 2))), 'B')
                || setweight(to_tsvector('simple'::regconfig, normalize_search_text(coalesce(description, ''))), 'C')
            ) stored
            SQL);
    }

    public function down(): void
    {
        DB::statement('alter table job_postings drop column search_vector');
        DB::statement(<<<'SQL'
            alter table job_postings add column search_vector tsvector generated always as (
                setweight(to_tsvector('simple'::regconfig, coalesce(title, '')), 'A')
                || setweight(to_tsvector('simple'::regconfig, split_part(coalesce(embedded_text, ''), E'\nSkills: ', 2)), 'B')
                || setweight(to_tsvector('simple'::regconfig, coalesce(description, '')), 'C')
            ) stored
            SQL);
        DB::statement('drop function normalize_search_text(text)');
    }
};
