<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\ReportSubscriptionFactory;
use Waypost\Enums\ReportFrequency;

/**
 * @property string $id
 * @property string $user_id
 * @property string $site_id
 * @property ReportFrequency $frequency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[UseFactory(ReportSubscriptionFactory::class)]
final class ReportSubscription extends Model
{
    /** @use HasFactory<ReportSubscriptionFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['not_deleted'];

    protected $hidden = ['not_deleted'];

    protected function casts(): array
    {
        return ['frequency' => ReportFrequency::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
