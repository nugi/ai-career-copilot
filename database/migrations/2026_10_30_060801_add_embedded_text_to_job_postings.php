<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the text of each vector so a row shows what was embedded, and index the text hash for copying a vector between postings with the same text.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->text('embedded_text')->nullable();
            $table->index('embedding_text_hash');
        });

        DB::statement('alter table job_postings drop constraint job_postings_embedding_check');
        DB::statement('alter table job_postings add constraint job_postings_embedding_check check ((embedding is null and embedding_model is null and embedded_at is null and embedding_text_hash is null and embedded_text is null) or (embedding is not null and embedding_model is not null and embedded_at is not null and embedding_text_hash is not null and embedded_text is not null))');
    }

    /**
     * Restore the earlier embedding constraint before removing the vector text and its lookup index.
     */
    public function down(): void
    {
        DB::statement('alter table job_postings drop constraint job_postings_embedding_check');
        DB::statement('alter table job_postings add constraint job_postings_embedding_check check ((embedding is null and embedding_model is null and embedded_at is null) or (embedding is not null and embedding_model is not null and embedded_at is not null))');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropIndex(['embedding_text_hash']);
            $table->dropColumn('embedded_text');
        });
    }
};
