<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\SiteFactory;
use Waypost\Support\Table;

/**
 * @property string $id
 * @property string $name
 * @property string|null $address
 * @property string $timezone
 * @property int $no_data_alert_after_min
 * @property string $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[UseFactory(SiteFactory::class)]
final class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;
    use SoftDeletes;

    /** The database defaults, so a new model has them before it is read back. */
    protected $attributes = [
        'timezone' => 'Europe/Budapest',
        'no_data_alert_after_min' => 120,
    ];

    protected function casts(): array
    {
        return ['no_data_alert_after_min' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, Table::name('site_user'));
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<Checkpoint, $this> */
    public function checkpoints(): HasMany
    {
        return $this->hasMany(Checkpoint::class);
    }

    /** @return HasMany<Route, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    /** @return HasMany<ReportSubscription, $this> */
    public function reportSubscriptions(): HasMany
    {
        return $this->hasMany(ReportSubscription::class);
    }

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
