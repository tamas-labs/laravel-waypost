<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('users'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('role', 16);
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('pin_hash', 44)->nullable();
            $table->string('pin_salt', 24)->nullable();
            $table->unsignedInteger('pin_iterations')->nullable();
            $table->boolean('active')->default(true);
            $table->datetimes(3);
            $table->softDeletesDatetime(precision: 3);
            // 1 while the row is not deleted, otherwise null: a unique index over it only binds live rows.
            $table->unsignedTinyInteger('not_deleted')->nullable()->storedAs('case when deleted_at is null then 1 end');

            $table->unique(['email', 'not_deleted']);
            $table->index(['updated_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('users'));
    }
};
