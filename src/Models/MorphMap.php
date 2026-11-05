<?php

declare(strict_types=1);

namespace Waypost\Models;

use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * The morph aliases of the package's models: what a polymorphic column (media.owner_type,
 * idempotency_keys.owner_type, personal_access_tokens.tokenable_type) stores instead of the class name. Added to the
 * host's morph map (Relation::morphMap(), never enforceMorphMap()), so a renamed class does not orphan stored rows,
 * and a host that enforces its morph map can still use the package. The "waypost." prefix keeps them apart from the
 * host's own aliases.
 */
final class MorphMap
{
    /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    public const array ALIASES = [
        'waypost.chain_entry' => ChainEntry::class,
        'waypost.checkpoint' => Checkpoint::class,
        'waypost.device' => Device::class,
        'waypost.device_diagnostic' => DeviceDiagnostic::class,
        'waypost.idempotency_key' => IdempotencyKey::class,
        'waypost.incident' => Incident::class,
        'waypost.media' => Media::class,
        'waypost.no_data_alert' => NoDataAlert::class,
        'waypost.personal_access_token' => PersonalAccessToken::class,
        'waypost.report' => Report::class,
        'waypost.report_subscription' => ReportSubscription::class,
        'waypost.round' => Round::class,
        'waypost.route' => Route::class,
        'waypost.route_version' => RouteVersion::class,
        'waypost.route_version_checkpoint' => RouteVersionCheckpoint::class,
        'waypost.scan' => Scan::class,
        'waypost.schedule' => Schedule::class,
        'waypost.shift' => Shift::class,
        'waypost.site' => Site::class,
        'waypost.user' => User::class,
    ];

    private function __construct()
    {
    }

    public static function register(): void
    {
        Relation::morphMap(self::ALIASES);
    }

    /**
     * The alias a polymorphic column stores for the model, e.g. in a query on owner_type.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    public static function alias(string $model): string
    {
        $alias = array_search($model, self::ALIASES, true);

        return is_string($alias) ? $alias : throw new LogicException("Not a model of the package: {$model}");
    }
}
