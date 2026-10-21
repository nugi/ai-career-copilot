<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable()->after('content');
            $table->index(['user_id', 'content_hash']);
        });

        DB::table('resumes')
            ->whereNotNull('content')
            ->orderBy('id')
            ->chunkById(100, function ($resumes): void {
                foreach ($resumes as $resume) {
                    DB::table('resumes')
                        ->where('id', $resume->id)
                        ->update(['content_hash' => hash('sha256', $resume->content)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
