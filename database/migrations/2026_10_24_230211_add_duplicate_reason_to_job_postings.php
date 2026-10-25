<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Existing links get the reason and the score that the link statement would give them.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->string('duplicate_reason')->nullable();
            $table->decimal('duplicate_similarity', 4, 3)->nullable();
        });

        DB::statement(<<<'SQL'
            update job_postings as posting
            set duplicate_reason = case when posting.duplicate_key = canonical.duplicate_key then 'exact' else 'trigram' end,
                duplicate_similarity = case when posting.duplicate_key = canonical.duplicate_key then null
                    else round(similarity(canonical.normalized_title, posting.normalized_title)::numeric, 3) end
            from job_postings as canonical
            where posting.duplicate_of_id = canonical.id
        SQL);

        DB::statement("alter table job_postings add constraint job_postings_duplicate_reason_check check (duplicate_reason in ('exact', 'trigram'))");
        DB::statement('alter table job_postings add constraint job_postings_duplicate_link_check check ((duplicate_of_id is null) = (duplicate_reason is null))');
        DB::statement("alter table job_postings add constraint job_postings_duplicate_similarity_check check ((duplicate_reason = 'trigram') = (duplicate_similarity is not null))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('alter table job_postings drop constraint job_postings_duplicate_similarity_check');
        DB::statement('alter table job_postings drop constraint job_postings_duplicate_link_check');
        DB::statement('alter table job_postings drop constraint job_postings_duplicate_reason_check');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn(['duplicate_reason', 'duplicate_similarity']);
        });
    }
};
