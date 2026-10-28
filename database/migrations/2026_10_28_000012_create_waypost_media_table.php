<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

return new class extends Migration {
    public function up(): void
    {
        // A row exists as soon as an entry or a checkpoint refers to the photo; mime, size_bytes, path and
        // uploaded_at are filled by the upload.
        Schema::create(Table::name('media'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->nullable()->constrained(Table::name('devices'));
            $table->uuidMorphs('owner');
            $table->char('sha256', 64);
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('path')->nullable();
            $table->dateTime('taken_at', 3);
            $table->dateTime('uploaded_at', 3)->nullable();
        });

        Schema::table(Table::name('checkpoints'), function (Blueprint $table) {
            $table->foreign('photo_media_id')->references('id')->on(Table::name('media'))->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table(Table::name('checkpoints'), function (Blueprint $table) {
            $table->dropForeign(['photo_media_id']);
        });

        Schema::dropIfExists(Table::name('media'));
    }
};
