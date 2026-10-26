<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Each group of linked postings becomes one opening, and the group head keeps its ID.
     */
    public function up(): void
    {
        Schema::create('openings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merged_into_id')->nullable()->index()->constrained('openings')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('alter table openings add constraint openings_merged_into_check check (merged_into_id is null or merged_into_id <> id)');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->foreignId('opening_id')->nullable()->index()->constrained();
        });

        DB::statement('insert into openings (id, created_at, updated_at) select id, now(), now() from job_postings where duplicate_of_id is null');
        DB::statement('update job_postings set opening_id = coalesce(duplicate_of_id, id)');
        DB::statement("select setval(pg_get_serial_sequence('openings', 'id'), coalesce((select max(id) from openings), 0) + 1, false)");

        DB::statement('alter table job_postings drop constraint job_postings_duplicate_link_check');
        DB::statement('alter table job_postings drop constraint job_postings_duplicate_of_check');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropIndex(['duplicate_of_id']);
            $table->dropConstrainedForeignId('duplicate_of_id');
        });
    }

    /**
     * Reverse the migrations. The lowest posting of each opening without a duplicate reason becomes its canonical row,
     * and a posting with a reason but no such row loses the reason.
     */
    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->foreignId('duplicate_of_id')->nullable()->constrained('job_postings')->nullOnDelete();
            $table->index('duplicate_of_id');
        });

        DB::statement('alter table job_postings add constraint job_postings_duplicate_of_check check (duplicate_of_id is null or duplicate_of_id <> id)');

        DB::statement(<<<'SQL'
            update job_postings as posting
            set duplicate_of_id = heads.head_id
            from (
                select opening_id, min(id) as head_id from job_postings where duplicate_reason is null group by opening_id
            ) as heads
            where posting.opening_id = heads.opening_id
                and posting.duplicate_reason is not null
                and heads.head_id <> posting.id
        SQL);

        DB::statement(<<<'SQL'
            update job_postings set duplicate_reason = null, duplicate_similarity = null
            where duplicate_reason is not null and duplicate_of_id is null
        SQL);

        DB::statement('alter table job_postings add constraint job_postings_duplicate_link_check check ((duplicate_of_id is null) = (duplicate_reason is null))');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_id');
        });

        Schema::drop('openings');
    }
};
