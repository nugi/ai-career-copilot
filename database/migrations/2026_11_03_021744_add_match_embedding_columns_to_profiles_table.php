<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->vector('match_embedding', 1536)->nullable();
            $table->string('match_embedding_model')->nullable();
            $table->char('match_embedding_hash', 64)->nullable();
        });

        DB::statement('alter table profiles add constraint profiles_match_embedding_check check ((match_embedding is null and match_embedding_model is null and match_embedding_hash is null) or (match_embedding is not null and match_embedding_model is not null and match_embedding_hash is not null))');
    }

    public function down(): void
    {
        DB::statement('alter table profiles drop constraint profiles_match_embedding_check');

        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn(['match_embedding', 'match_embedding_model', 'match_embedding_hash']);
        });
    }
};
