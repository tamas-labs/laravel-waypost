<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('schedules'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('route_id')->constrained(Table::name('routes'));
            $table->string('name');
            // Bit mask of start days: 1 = Monday … 64 = Sunday.
            $table->unsignedTinyInteger('weekdays');
            $table->string('mode', 16);
            $table->json('start_times')->nullable();
            $table->time('interval_from')->nullable();
            $table->time('interval_to')->nullable();
            $table->unsignedSmallInteger('interval_every_min')->nullable();
            $table->unsignedSmallInteger('time_limit_min')->nullable();
            $table->boolean('active')->default(true);
            $table->datetimes(3);
            $table->softDeletesDatetime(precision: 3);

            $table->index(['updated_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('schedules'));
    }
};
