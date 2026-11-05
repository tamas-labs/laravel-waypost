<?php

declare(strict_types=1);

namespace Waypost\Enums;

use ValueError;
use Waypost\Models\Checkpoint;
use Waypost\Models\Incident;
use Waypost\Models\MorphMap;
use Waypost\Models\Scan;

/**
 * What a photo belongs to, as the API names it (entities/media.json). The owner_type column holds the model's morph
 * alias (MorphMap), e.g. waypost.scan.
 */
enum MediaOwnerType: string
{
    case Scan = 'scan';
    case Incident = 'incident';
    case Checkpoint = 'checkpoint';

    /** The value of media.owner_type: the owner model's morph alias. */
    public function stored(): string
    {
        return MorphMap::alias(match ($this) {
            self::Scan => Scan::class,
            self::Incident => Incident::class,
            self::Checkpoint => Checkpoint::class,
        });
    }

    /** Of a value of media.owner_type. */
    public static function ofStored(string $ownerType): self
    {
        foreach (self::cases() as $case) {
            if ($case->stored() === $ownerType) {
                return $case;
            }
        }

        throw new ValueError("Not a media owner: {$ownerType}");
    }
}
