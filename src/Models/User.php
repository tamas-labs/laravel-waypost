<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\UserFactory;
use Waypost\Enums\Role;
use Waypost\Models\Concerns\HasWaypostTokens;
use Waypost\Support\Table;

/**
 * A supervisor or a guard (waypost_users; the host application's users table is not used). Supervisors
 * log in with e-mail and password, everybody on the site phone with a PIN (PBKDF2, checked offline).
 *
 * @property string $id
 * @property string $name
 * @property Role $role
 * @property string|null $email
 * @property string|null $password
 * @property string|null $pin_hash
 * @property string|null $pin_salt
 * @property int|null $pin_iterations
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[UseFactory(UserFactory::class)]
final class User extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable;
    use Authorizable;
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasWaypostTokens;
    use SoftDeletes;

    protected $guarded = ['not_deleted'];

    protected $hidden = ['password', 'not_deleted'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'password' => 'hashed',
            'pin_iterations' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Site, $this> */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, Table::name('site_user'));
    }

    /** @return HasMany<ReportSubscription, $this> */
    public function reportSubscriptions(): HasMany
    {
        return $this->hasMany(ReportSubscription::class);
    }
}
