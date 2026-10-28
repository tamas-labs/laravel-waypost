<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

// Sanctum's personal_access_tokens table, owned by the package, for the tokens of users and devices.
return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('personal_access_tokens'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('tokenable', Table::name('pat_tokenable_index'));
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->dateTime('last_used_at', 3)->nullable();
            $table->dateTime('expires_at', 3)->nullable()->index();
            $table->datetimes(3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('personal_access_tokens'));
    }
};
