<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('no_data_alerts'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained(Table::name('sites'));
            // The local start day (Y-m-d) of the schedule window the alert is about.
            $table->string('window_day', 10);
            $table->dateTime('scheduled_at', 3);
            $table->dateTime('deadline', 3);
            $table->dateTime('last_seen_at', 3)->nullable();
            $table->json('recipients');
            $table->dateTime('sent_at', 3)->nullable();

            // At most one alert a schedule window, also when two jobs run at once.
            $table->unique(['site_id', 'window_day']);
            $table->index('deadline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('no_data_alerts'));
    }
};
