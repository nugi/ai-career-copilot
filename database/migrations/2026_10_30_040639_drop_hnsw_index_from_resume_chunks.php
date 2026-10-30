<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Chunks are only searched inside one resume, where the btree on (resume_id, position) and an exact sort are complete. A graph over the chunks of every user does not help that search.
     */
    public function up(): void
    {
        DB::statement('drop index if exists resume_chunks_embedding_hnsw_index');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('create index resume_chunks_embedding_hnsw_index on resume_chunks using hnsw (embedding vector_cosine_ops) with (m = 16, ef_construction = 64)');
    }
};
