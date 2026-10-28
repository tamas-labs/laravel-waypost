<?php

declare(strict_types=1);

namespace Waypost\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Time handling: the server stores every instant in UTC with millisecond precision (datetime(3)),
 * and the API exchanges them as ISO 8601 UTC strings with exactly three fractional digits
 * (schema common/timestamp.json), e.g. 2026-10-27T20:00:00.000Z. Sub-millisecond digits are
 * truncated. The application time zone does not matter: use Time::now() rather than now().
 */
final class Time
{
    /** Database text form of a datetime(3) column, always in UTC. */
    public const string DATABASE_FORMAT = 'Y-m-d H:i:s.v';

    /** API text form, always in UTC. */
    public const string ISO_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    /** The pattern of waypost/schema common/timestamp.json; D: "$" must not match before a trailing newline. */
    public const string ISO_PATTERN = '/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])T([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\.[0-9]{3}Z$/D';

    private function __construct()
    {
    }

    /** Current time in UTC (honours Carbon's test clock). */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /** API text form of an instant, e.g. 2026-10-27T20:00:00.000Z. */
    public static function iso(DateTimeInterface $time): string
    {
        return CarbonImmutable::instance($time)->utc()->format(self::ISO_FORMAT);
    }

    /**
     * Parses the API text form; anything else (offset, other precision, impossible date) is rejected.
     *
     * @throws InvalidArgumentException
     */
    public static function fromIso(string $value): CarbonImmutable
    {
        if (preg_match(self::ISO_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Not an ISO 8601 UTC timestamp with milliseconds: {$value}");
        }

        try {
            $time = CarbonImmutable::createFromFormat('!' . self::ISO_FORMAT, $value, 'UTC');
        } catch (Throwable) {
            $time = null;
        }
        // createFromFormat() rolls impossible dates over (02-30 → 03-02); the round trip catches that.
        if (!$time instanceof CarbonImmutable || $time->format(self::ISO_FORMAT) !== $value) {
            throw new InvalidArgumentException("Impossible date: {$value}");
        }

        return $time;
    }
}
