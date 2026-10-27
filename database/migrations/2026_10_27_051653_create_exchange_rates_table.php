<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store one positive reference rate per currency and ECB date.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date');
            $table->char('currency', 3);
            $table->decimal('rate', 18, 8);
            $table->timestamps();

            $table->unique(['rate_date', 'currency']);
            $table->index(['currency', 'rate_date']);
        });

        DB::statement("alter table exchange_rates add constraint exchange_rates_currency_check check (currency ~ '^[A-Z]{3}$')");
        DB::statement('alter table exchange_rates add constraint exchange_rates_rate_check check (rate > 0)');
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
