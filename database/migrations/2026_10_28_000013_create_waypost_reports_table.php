<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('reports'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained(Table::name('sites'));
            $table->string('type', 16);
            $table->dateTime('period_start', 3);
            $table->dateTime('period_end', 3);
            $table->dateTime('generated_at', 3);
            $table->dateTime('sent_at', 3)->nullable();
            $table->string('path')->nullable();
            $table->json('recipients');

            // One report per site, type and period, so a repeated job does not send it twice.
            $table->unique(['site_id', 'type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('reports'));
    }
};
