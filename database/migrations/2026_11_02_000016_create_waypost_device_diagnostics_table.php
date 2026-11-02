<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

// What a device reported after the server rejected its chain entries (POST /devices/diagnostics). Outside the chain,
// and it stays when a later entry replaces the rejected one in its seq's place.
return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('device_diagnostics'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained(Table::name('devices'));
            $table->string('code', 20);
            // No foreign key: the rejected entry may not be stored, or a later one may have replaced it.
            $table->uuid('entry_id');
            $table->unsignedBigInteger('seq');
            $table->unsignedBigInteger('expected_seq')->nullable();
            $table->unsignedBigInteger('server_last_seq');
            $table->unsignedSmallInteger('rejected_count');
            $table->dateTime('occurred_at', 3);
            $table->dateTime('received_at', 3);

            $table->index(['device_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('device_diagnostics'));
    }
};
