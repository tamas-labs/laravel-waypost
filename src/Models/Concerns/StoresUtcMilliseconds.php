<?php

declare(strict_types=1);

namespace Waypost\Models\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Waypost\Support\Time;

/**
 * Date attributes (timestamps, soft deletes, "datetime" casts) are stored in UTC with milliseconds
 * (datetime(3) columns), read back as UTC, and serialised in the API form (Time::iso()), whatever the
 * host application's time zone is. Eloquent alone would store the wall-clock time of the application
 * time zone without any conversion.
 */
trait StoresUtcMilliseconds
{
    public function getDateFormat(): string
    {
        return Time::DATABASE_FORMAT;
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    public function fromDateTime($value)
    {
        return empty($value) ? $value : $this->asDateTime($value)->utc()->format($this->getDateFormat());
    }

    /**
     * Database values carry no zone and are always UTC; any other value keeps its own zone and is
     * converted to UTC.
     *
     * @param  mixed  $value
     * @return Carbon
     */
    protected function asDateTime($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2}(\.\d+)?)?$/', $value) === 1) {
            return Date::parse($value, 'UTC');
        }

        return parent::asDateTime($value)->utc();
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return Time::iso($date);
    }
}
