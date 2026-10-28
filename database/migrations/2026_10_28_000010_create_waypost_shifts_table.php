<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

// Projections of the chain (shifts and rounds), filled while the entries are processed.
return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('shifts'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained(Table::name('sites'));
            $table->foreignUuid('device_id')->constrained(Table::name('devices'));
            $table->foreignUuid('guard_id')->constrained(Table::name('users'));
            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3)->nullable();
            $table->string('closed_by', 16)->nullable();
            $table->foreignUuid('chain_entry_id')->constrained(Table::name('chain_entries'));

            $table->index(['site_id', 'started_at']);
            $table->index(['device_id', 'started_at']);
        });

        Schema::create(Table::name('rounds'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shift_id')->constrained(Table::name('shifts'));
            $table->foreignUuid('route_version_id')->constrained(Table::name('route_versions'));
            $table->foreignUuid('schedule_id')->constrained(Table::name('schedules'));
            $table->dateTime('scheduled_at', 3);
            $table->unsignedSmallInteger('time_limit_min')->nullable();
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('finished_at', 3)->nullable();
            $table->string('status', 16);

            $table->index(['schedule_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('rounds'));
        Schema::dropIfExists(Table::name('shifts'));
    }
};
