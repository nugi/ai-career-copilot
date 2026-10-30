<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add embeddings with m and ef_construction at the pgvector defaults, kept on purpose.
     * A large table needs CREATE INDEX CONCURRENTLY as docs/embedding-benchmark.md describes.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->vector('embedding', 1536)->nullable();
            $table->string('embedding_model')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->char('embedding_text_hash', 64)->nullable();
        });

        Schema::table('resume_chunks', function (Blueprint $table) {
            $table->vector('embedding', 1536)->nullable();
            $table->string('embedding_model')->nullable();
            $table->timestamp('embedded_at')->nullable();
        });

        DB::statement('alter table job_postings add constraint job_postings_embedding_check check ((embedding is null and embedding_model is null and embedded_at is null) or (embedding is not null and embedding_model is not null and embedded_at is not null))');
        DB::statement('alter table resume_chunks add constraint resume_chunks_embedding_check check ((embedding is null and embedding_model is null and embedded_at is null) or (embedding is not null and embedding_model is not null and embedded_at is not null))');

        DB::statement('create index job_postings_embedding_hnsw_index on job_postings using hnsw (embedding vector_cosine_ops) with (m = 16, ef_construction = 64)');
        DB::statement('create index resume_chunks_embedding_hnsw_index on resume_chunks using hnsw (embedding vector_cosine_ops) with (m = 16, ef_construction = 64)');
    }

    public function down(): void
    {
        DB::statement('drop index job_postings_embedding_hnsw_index');
        DB::statement('drop index resume_chunks_embedding_hnsw_index');

        DB::statement('alter table job_postings drop constraint job_postings_embedding_check');
        DB::statement('alter table resume_chunks drop constraint resume_chunks_embedding_check');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'embedding_model', 'embedded_at', 'embedding_text_hash']);
        });

        Schema::table('resume_chunks', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'embedding_model', 'embedded_at']);
        });
    }
};
