<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Waypost\Database\Factories\ReportFactory;
use Waypost\Enums\ReportFrequency;

/**
 * A generated report of a site for a period (a shift, a day, a week or a month).
 *
 * @property string $id
 * @property string $site_id
 * @property ReportFrequency $type
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $generated_at
 * @property Carbon|null $sent_at
 * @property string|null $path
 * @property list<string> $recipients
 */
#[UseFactory(ReportFactory::class)]
final class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'type' => ReportFrequency::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'generated_at' => 'datetime',
            'sent_at' => 'datetime',
            'recipients' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
