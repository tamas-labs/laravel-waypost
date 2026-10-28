<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('devices'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->nullable()->constrained(Table::name('sites'));
            $table->string('name');
            $table->string('platform', 16);
            $table->string('app_version', 50);
            $table->text('secret');
            $table->foreignUuid('enrolled_by')->constrained(Table::name('users'));
            $table->dateTime('enrolled_at', 3);
            $table->dateTime('revoked_at', 3)->nullable();
            $table->dateTime('last_seen_at', 3)->nullable();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->char('last_hash', 64)->nullable();
            $table->datetimes(3);
            $table->softDeletesDatetime(precision: 3);

            $table->index(['updated_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('devices'));
    }
};
