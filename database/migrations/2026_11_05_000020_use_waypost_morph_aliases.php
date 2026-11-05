<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Waypost\Support\Table;

// The polymorphic columns store the package's morph aliases (Waypost\Models\MorphMap) instead of the class names: a
// renamed class no longer orphans rows, and a host that enforces its morph map can use the package. The names are
// written out here, as they were, so the migration does not change with the code.
return new class extends Migration {
    private const array ALIASES = [
        'Waypost\Models\Scan' => 'waypost.scan',
        'Waypost\Models\Incident' => 'waypost.incident',
        'Waypost\Models\Checkpoint' => 'waypost.checkpoint',
        'Waypost\Models\User' => 'waypost.user',
        'Waypost\Models\Device' => 'waypost.device',
    ];

    private const array COLUMNS = [
        'media' => 'owner_type',
        'idempotency_keys' => 'owner_type',
        'personal_access_tokens' => 'tokenable_type',
    ];

    public function up(): void
    {
        $this->rename(self::ALIASES);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::ALIASES));
    }

    /** @param  array<string, string>  $names  old → new */
    private function rename(array $names): void
    {
        foreach (self::COLUMNS as $table => $column) {
            foreach ($names as $from => $to) {
                DB::table(Table::name($table))->where($column, $from)->update([$column => $to]);
            }
        }
    }
};
