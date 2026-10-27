<?php

namespace VitisStudio\Tendril\Core\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

/**
 * UTC, microsecond-precision time handling shared by every package.
 *
 * PostgreSQL columns are timestamptz, so values are written with an explicit
 * +00:00 offset; MySQL/SQLite columns are DATETIME(6) and receive naive UTC.
 */
final class Instant
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function parse(mixed $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (is_int($value) || is_float($value)) {
            return CarbonImmutable::createFromTimestamp($value, 'UTC');
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        }

        throw new InvalidArgumentException('Unable to parse instant.');
    }

    public static function tryParse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return self::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Transport/serialization format (ISO-8601 with microseconds, UTC).
     */
    public static function serialize(DateTimeInterface $value): string
    {
        return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function toDatabase(DateTimeInterface $value, string $driver): string
    {
        $utc = CarbonImmutable::instance($value)->utc();

        return $driver === 'pgsql'
            ? $utc->format('Y-m-d H:i:s.uP')
            : $utc->format('Y-m-d H:i:s.u');
    }

    public static function hour(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)->utc()->startOfHour();
    }

    public static function day(DateTimeInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)->utc()->startOfDay();
    }
}
