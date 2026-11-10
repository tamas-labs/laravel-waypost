<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

// The seq up to which a push last tried the device's unapplied entries of earlier pushes: the next push goes on from
// there and comes round to the start again, so entries that can never be applied do not keep the others from their turn
// (ChainProcessor::PENDING_LIMIT).
return new class extends Migration {
    public function up(): void
    {
        Schema::table(Table::name('devices'), function (Blueprint $table) {
            $table->unsignedBigInteger('pending_cursor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table(Table::name('devices'), function (Blueprint $table) {
            $table->dropColumn('pending_cursor');
        });
    }
};
