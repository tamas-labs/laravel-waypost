<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waypost\Support\Table;

// An index on every foreign key column that has none yet. InnoDB creates one with each foreign key, PostgreSQL and
// SQLite do not: there the report's and the retention's joins (rounds.shift_id, scans.round_id, the chain_entry_id
// columns …) and the check of every deleted parent row would read the whole table. On MySQL nothing changes.
return new class extends Migration {
    /** @var array<string, list<string>> table => columns */
    private const array COLUMNS = [
        'sites' => ['created_by'],
        'site_user_detachments' => ['user_id'],
        'devices' => ['site_id', 'enrolled_by'],
        'checkpoints' => ['photo_media_id'],
        'routes' => ['site_id', 'current_version_id'],
        'route_versions' => ['created_by'],
        'route_version_checkpoints' => ['checkpoint_id'],
        'schedules' => ['route_id'],
        'report_subscriptions' => ['site_id'],
        'shifts' => ['guard_id', 'chain_entry_id'],
        'rounds' => ['shift_id', 'route_version_id'],
        'scans' => ['round_id', 'checkpoint_id', 'chain_entry_id'],
        'incidents' => ['shift_id', 'nearest_checkpoint_id', 'scan_id', 'chain_entry_id'],
        'media' => ['device_id'],
    ];

    public function up(): void
    {
        $this->each(fn (Blueprint $table, string $column) => $table->index($column));
    }

    public function down(): void
    {
        $this->each(fn (Blueprint $table, string $column) => $table->dropIndex([$column]));
    }

    /** @param  Closure(Blueprint, string): mixed  $change */
    private function each(Closure $change): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (self::COLUMNS as $name => $columns) {
            Schema::table(Table::name($name), function (Blueprint $table) use ($columns, $change): void {
                foreach ($columns as $column) {
                    $change($table, $column);
                }
            });
        }
    }
};
