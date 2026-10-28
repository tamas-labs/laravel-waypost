<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

// The Idempotency-Key of write requests: the stored response is replayed for 24 hours.
return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('idempotency_keys'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The token's owner (user or device): keys are only unique per caller.
            $table->uuidMorphs('owner');
            $table->uuid('key');
            $table->string('method', 10);
            $table->string('path');
            // SHA-256 of method, path and body: the same key with another request is rejected.
            $table->char('request_hash', 64);
            // Null while the first request is still being processed.
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('expires_at', 3)->index();

            $table->unique(['owner_type', 'owner_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('idempotency_keys'));
    }
};
