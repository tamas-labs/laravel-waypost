<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

// The recipients a report has gone out to so far, written after each e-mail: an attempt that failed or died halfway
// leaves them to the next one, which sends only to the rest (ReportSender).
return new class extends Migration {
    public function up(): void
    {
        Schema::table(Table::name('reports'), function (Blueprint $table) {
            $table->json('delivered_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(Table::name('reports'), function (Blueprint $table) {
            $table->dropColumn('delivered_to');
        });
    }
};
