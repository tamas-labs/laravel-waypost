<?php

declare(strict_types=1);

namespace Waypost\Enums;

use ValueError;
use Waypost\Models\Checkpoint;
use Waypost\Models\Incident;
use Waypost\Models\Scan;

/**
 * What a photo belongs to, as the API names it (entities/media.json). The owner_type column holds the model class, as
 * an Eloquent morph does: a global morph map could clash with the host application's own.
 */
enum MediaOwnerType: string
{
    case Scan = 'scan';
    case Incident = 'incident';
    case Checkpoint = 'checkpoint';

    /** @return class-string<Scan|Incident|Checkpoint> */
    public function model(): string
    {
        return match ($this) {
            self::Scan => Scan::class,
            self::Incident => Incident::class,
            self::Checkpoint => Checkpoint::class,
        };
    }

    public static function ofModel(string $model): self
    {
        return match ($model) {
            Scan::class => self::Scan,
            Incident::class => self::Incident,
            Checkpoint::class => self::Checkpoint,
            default => throw new ValueError("Not a media owner: {$model}"),
        };
    }
}
