<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('chain_entries'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained(Table::name('devices'));
            $table->unsignedBigInteger('seq');
            // A string, not an enum: new entry types (e.g. sos) need no migration; the schema validates it.
            $table->string('type', 32);
            $table->json('payload');
            $table->dateTime('device_time', 3);
            $table->unsignedBigInteger('uptime_ms');
            $table->string('boot_id', 64);
            $table->dateTime('gnss_time', 3)->nullable();
            $table->dateTime('ref_server_time', 3)->nullable();
            $table->unsignedBigInteger('ref_uptime_ms')->nullable();
            $table->string('ref_boot_id', 64)->nullable();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);
            $table->dateTime('received_at', 3);
            $table->string('verify_status', 16);
            $table->json('flags');

            $table->unique(['device_id', 'seq']);
            $table->index(['device_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('chain_entries'));
    }
};
