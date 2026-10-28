<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

// Projections of the chain (scans and incidents), filled while the entries are processed. Locations are
// integers as in the chain: degrees × 10^7 and decimetres.
return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('scans'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('round_id')->nullable()->constrained(Table::name('rounds'));
            $table->foreignUuid('checkpoint_id')->nullable()->constrained(Table::name('checkpoints'));
            $table->string('method', 16);
            $table->dateTime('scanned_at', 3);
            $table->integer('lat_e7')->nullable();
            $table->integer('lng_e7')->nullable();
            $table->unsignedInteger('accuracy_dm')->nullable();
            $table->boolean('is_mock');
            $table->json('flags');
            $table->text('note')->nullable();
            $table->foreignUuid('chain_entry_id')->constrained(Table::name('chain_entries'));

            $table->index('scanned_at');
        });

        Schema::create(Table::name('incidents'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shift_id')->constrained(Table::name('shifts'));
            $table->string('category', 16);
            $table->text('note')->nullable();
            $table->dateTime('occurred_at', 3);
            $table->integer('lat_e7')->nullable();
            $table->integer('lng_e7')->nullable();
            $table->unsignedInteger('accuracy_dm')->nullable();
            $table->foreignUuid('nearest_checkpoint_id')->nullable()->constrained(Table::name('checkpoints'));
            $table->foreignUuid('scan_id')->nullable()->constrained(Table::name('scans'));
            $table->foreignUuid('chain_entry_id')->constrained(Table::name('chain_entries'));

            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('incidents'));
        Schema::dropIfExists(Table::name('scans'));
    }
};
