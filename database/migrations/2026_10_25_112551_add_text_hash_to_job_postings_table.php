<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the hash of the posting text and fill it for the stored rows.
     *
     * The formula must match NormalizedPosting::textHash(), so a later import computes the same hash for the same text.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->char('text_hash', 64)->nullable();
        });

        DB::table('job_postings')
            ->select(['id', 'title', 'description'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows): void {
                foreach ($rows as $row) {
                    DB::table('job_postings')->where('id', $row->id)->update([
                        'text_hash' => hash('sha256', json_encode([$row->title, $row->description], JSON_THROW_ON_ERROR)),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn('text_hash');
        });
    }
};
