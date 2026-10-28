<?php

declare(strict_types=1);

namespace Waypost\Http;

use DateTimeInterface;
use LogicException;
use Waypost\Auth\Caller;
use Waypost\Models\Checkpoint;
use Waypost\Models\ReportSubscription;
use Waypost\Models\Route;
use Waypost\Models\RouteVersion;
use Waypost\Models\RouteVersionCheckpoint;
use Waypost\Models\Schedule;
use Waypost\Models\Site;
use Waypost\Models\User;
use Waypost\Support\Time;

/**
 * The records as the API sends them: exactly the properties of the waypost/schema entity schemas
 * (entities/*.json), absent values as null. The configuration endpoints and sync pull use the same shapes.
 */
final class Entities
{
    private function __construct()
    {
    }

    /** @return array<string, mixed> entities/site.json */
    public static function site(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'address' => $site->address,
            'timezone' => $site->timezone,
            'no_data_alert_after_min' => $site->no_data_alert_after_min,
            'created_by' => $site->created_by,
            'created_at' => self::time($site->created_at),
            'updated_at' => self::time($site->updated_at),
        ];
    }

    /**
     * entities/user.json as the caller may see it: site_ids only of the caller's sites, the PIN verifier only for
     * the site phone (which checks PINs offline; Q9).
     *
     * @return array<string, mixed>
     */
    public static function user(User $user, Caller $caller): array
    {
        $siteIds = array_values(array_intersect($user->sites->modelKeys(), $caller->siteIds()));
        sort($siteIds);
        $pin = $caller->device !== null && $user->pin_hash !== null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role->value,
            'pin_hash' => $pin ? $user->pin_hash : null,
            'pin_salt' => $pin ? $user->pin_salt : null,
            'pin_iterations' => $pin ? $user->pin_iterations : null,
            'active' => $user->active,
            'site_ids' => $siteIds,
            'created_at' => self::time($user->created_at),
            'updated_at' => self::time($user->updated_at),
        ];
    }

    /** @return array<string, mixed> entities/checkpoint.json */
    public static function checkpoint(Checkpoint $checkpoint): array
    {
        return [
            'id' => $checkpoint->id,
            'site_id' => $checkpoint->site_id,
            'name' => $checkpoint->name,
            'instruction' => $checkpoint->instruction,
            'tag_type' => $checkpoint->tag_type->value,
            'tag_uid' => $checkpoint->tag_uid,
            'qr_payload' => $checkpoint->qr_payload,
            'lat_e7' => $checkpoint->lat_e7,
            'lng_e7' => $checkpoint->lng_e7,
            'accuracy_dm' => $checkpoint->accuracy_dm,
            'photo_media_id' => $checkpoint->photo_media_id,
            'active' => $checkpoint->active,
            'created_at' => self::time($checkpoint->created_at),
            'updated_at' => self::time($checkpoint->updated_at),
        ];
    }

    /** @return array<string, mixed> entities/route.json */
    public static function route(Route $route): array
    {
        return [
            'id' => $route->id,
            'site_id' => $route->site_id,
            'name' => $route->name,
            'order_mode' => $route->order_mode->value,
            'current_version_id' => $route->current_version_id,
            'active' => $route->active,
            'created_at' => self::time($route->created_at),
            'updated_at' => self::time($route->updated_at),
        ];
    }

    /** @return array<string, mixed> entities/route-version.json */
    public static function routeVersion(RouteVersion $version): array
    {
        return [
            'id' => $version->id,
            'route_id' => $version->route_id,
            'version' => $version->version,
            'walk_duration_s' => $version->walk_duration_s,
            'created_by' => $version->created_by,
            'created_at' => self::time($version->created_at),
        ];
    }

    /** @return array<string, mixed> entities/route-version-checkpoint.json */
    public static function routeVersionCheckpoint(RouteVersionCheckpoint $point): array
    {
        return [
            'route_version_id' => $point->route_version_id,
            'checkpoint_id' => $point->checkpoint_id,
            'position' => $point->position,
            'walk_time_from_prev_s' => $point->walk_time_from_prev_s,
        ];
    }

    /** @return array<string, mixed> entities/schedule.json */
    public static function schedule(Schedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'route_id' => $schedule->route_id,
            'name' => $schedule->name,
            'weekdays' => $schedule->weekdays,
            'mode' => $schedule->mode->value,
            'start_times' => $schedule->start_times,
            'interval_from' => $schedule->interval_from,
            'interval_to' => $schedule->interval_to,
            'interval_every_min' => $schedule->interval_every_min,
            'time_limit_min' => $schedule->time_limit_min,
            'active' => $schedule->active,
            'created_at' => self::time($schedule->created_at),
            'updated_at' => self::time($schedule->updated_at),
        ];
    }

    /** @return array<string, mixed> entities/report-subscription.json */
    public static function reportSubscription(ReportSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'site_id' => $subscription->site_id,
            'frequency' => $subscription->frequency->value,
        ];
    }

    private static function time(?DateTimeInterface $time): string
    {
        return Time::iso($time ?? throw new LogicException('A saved record has its timestamps.'));
    }
}
