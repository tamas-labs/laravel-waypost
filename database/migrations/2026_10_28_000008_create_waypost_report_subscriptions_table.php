<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('report_subscriptions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained(Table::name('users'))->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained(Table::name('sites'))->cascadeOnDelete();
            $table->string('frequency', 16);
            $table->datetimes(3);
            $table->softDeletesDatetime(precision: 3);
            // 1 while the row is not deleted, otherwise null: a deleted subscription can be created again.
            $table->unsignedTinyInteger('not_deleted')->nullable()->storedAs('case when deleted_at is null then 1 end');

            $table->unique(['user_id', 'site_id', 'frequency', 'not_deleted'], Table::name('report_subscriptions_unique'));
            $table->index(['updated_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('report_subscriptions'));
    }
};
