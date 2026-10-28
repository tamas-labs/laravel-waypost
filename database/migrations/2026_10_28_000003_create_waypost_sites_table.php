<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('sites'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('timezone', 64)->default('Europe/Budapest');
            $table->unsignedSmallInteger('no_data_alert_after_min')->default(120);
            $table->foreignUuid('created_by')->constrained(Table::name('users'));
            $table->datetimes(3);
            $table->softDeletesDatetime(precision: 3);

            $table->index(['updated_at', 'id']);
        });

        Schema::create(Table::name('site_user'), function (Blueprint $table) {
            $table->foreignUuid('site_id')->constrained(Table::name('sites'))->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained(Table::name('users'))->cascadeOnDelete();

            $table->primary(['site_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('site_user'));
        Schema::dropIfExists(Table::name('sites'));
    }
};
