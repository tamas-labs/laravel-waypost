<?php

declare(strict_types=1);

namespace Waypost\Sync;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Waypost\Support\Time;
use Waypost\Support\Uuid;

/**
 * The position of a sync pull (spec: API-szerződés / Pull): the change time and id of the last record sent, and a
 * fingerprint of the sites the caller saw then. Opaque to the client: base64url of "<time>_<id>_<scope>".
 */
final readonly class PullCursor
{
    /** The id of the start position, before every record. */
    public const string NIL_ID = '00000000-0000-0000-0000-000000000000';

    private const string PATTERN = '/^(?<time>[^_]+)_(?<id>[0-9a-f-]{36})_(?<scope>[0-9a-f]{16})$/D';

    public function __construct(
        public CarbonImmutable $time,
        public string $id,
        public string $scope,
    ) {
    }

    /** Before every record, for the caller's sites. */
    public static function start(string $scope): self
    {
        return new self(CarbonImmutable::createFromTimestampUTC(0), self::NIL_ID, $scope);
    }

    /**
     * The fingerprint of a set of sites: another set gets another one, in any order.
     *
     * @param  list<string>  $siteIds
     */
    public static function scope(array $siteIds): string
    {
        sort($siteIds);

        return substr(hash('sha256', implode(',', $siteIds)), 0, 16);
    }

    /** The cursor of a pull response, or null if the text is not one. */
    public static function decode(string $text): ?self
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);
        if ($decoded === false || preg_match(self::PATTERN, $decoded, $parts) !== 1) {
            return null;
        }
        if ($parts['id'] !== self::NIL_ID && !Uuid::isV7($parts['id'])) {
            return null;
        }

        try {
            return new self(Time::fromIso($parts['time']), $parts['id'], $parts['scope']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(Time::iso($this->time) . "_{$this->id}_{$this->scope}"), '+/', '-_'), '=');
    }
}
