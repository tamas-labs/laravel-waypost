<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('checkpoints'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained(Table::name('sites'));
            $table->string('name');
            $table->text('instruction')->nullable();
            $table->string('tag_type', 16);
            $table->string('tag_uid', 20)->nullable();
            $table->string('qr_payload', 64)->nullable();
            $table->integer('lat_e7')->nullable();
            $table->integer('lng_e7')->nullable();
            $table->unsignedInteger('accuracy_dm')->nullable();
            // The foreign key to media is added with the media table.
            $table->uuid('photo_media_id')->nullable();
            $table->boolean('active')->default(true);
            $table->datetimes(3);
            $table->softDeletesDatetime(precision: 3);
            // 1 while the row is not deleted, otherwise null: a deleted checkpoint releases its tag and QR code.
            $table->unsignedTinyInteger('not_deleted')->nullable()->storedAs('case when deleted_at is null then 1 end');

            $table->unique(['site_id', 'tag_uid', 'not_deleted']);
            $table->unique(['qr_payload', 'not_deleted']);
            $table->index(['updated_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('checkpoints'));
    }
};
