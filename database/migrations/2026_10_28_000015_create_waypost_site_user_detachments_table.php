<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

// Who was ever detached from a site (server only). A detached user leaves no row in site_user, yet sync pull has to
// list them as deleted for the callers on that site, and only for those.
return new class extends Migration {
    public function up(): void
    {
        Schema::create(Table::name('site_user_detachments'), function (Blueprint $table) {
            $table->foreignUuid('site_id')->constrained(Table::name('sites'))->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained(Table::name('users'))->cascadeOnDelete();
            // The last detachment.
            $table->dateTime('detached_at', 3);

            $table->primary(['site_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Table::name('site_user_detachments'));
    }
};
