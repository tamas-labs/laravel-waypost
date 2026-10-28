<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('routes'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained(Table::name('sites'));
            $table->string('name');
            $table->string('order_mode', 16);
            // The foreign key is added below, once route_versions exists.
            $table->uuid('current_version_id')->nullable();
            $table->boolean('active')->default(true);
            $table->datetimes(3);
            $table->softDeletesDatetime(precision: 3);

            $table->index(['updated_at', 'id']);
        });

        // Versions never change after creation, so they only have created_at (which drives the pull cursor).
        Schema::create(Table::name('route_versions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('route_id')->constrained(Table::name('routes'));
            $table->unsignedInteger('version');
            $table->unsignedInteger('walk_duration_s')->nullable();
            $table->foreignUuid('created_by')->constrained(Table::name('users'));
            $table->dateTime('created_at', 3);

            $table->unique(['route_id', 'version']);
            $table->index(['created_at', 'id']);
        });

        Schema::table(Table::name('routes'), function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on(Table::name('route_versions'))->nullOnDelete();
        });

        Schema::create(Table::name('route_version_checkpoints'), function (Blueprint $table) {
            $table->foreignUuid('route_version_id')->constrained(Table::name('route_versions'))->cascadeOnDelete();
            $table->foreignUuid('checkpoint_id')->constrained(Table::name('checkpoints'));
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('walk_time_from_prev_s')->nullable();

            $table->primary(['route_version_id', 'position']);
            $table->unique(['route_version_id', 'checkpoint_id'], Table::name('rvc_checkpoint_unique'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('route_version_checkpoints'));

        Schema::table(Table::name('routes'), function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists(Table::name('route_versions'));
        Schema::dropIfExists(Table::name('routes'));
    }
};
