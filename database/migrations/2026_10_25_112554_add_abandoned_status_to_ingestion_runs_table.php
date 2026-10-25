<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow the "abandoned" status that closes a run whose worker was killed.
     */
    public function up(): void
    {
        DB::statement('alter table ingestion_runs drop constraint ingestion_runs_status_check');
        DB::statement("alter table ingestion_runs add constraint ingestion_runs_status_check check (status in ('running', 'succeeded', 'failed', 'rate_limited', 'abandoned'))");
    }

    /**
     * Reverse the migrations, changing abandoned rows to failed because the old list does not hold them.
     */
    public function down(): void
    {
        DB::table('ingestion_runs')->where('status', 'abandoned')->update(['status' => 'failed']);

        DB::statement('alter table ingestion_runs drop constraint ingestion_runs_status_check');
        DB::statement("alter table ingestion_runs add constraint ingestion_runs_status_check check (status in ('running', 'succeeded', 'failed', 'rate_limited'))");
    }
};
