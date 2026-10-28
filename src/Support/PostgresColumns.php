<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Support;

use Illuminate\Database\Connection;
use Throwable;

/**
 * PostgreSQL fails the whole query on a literal its column type rejects
 * (`id = 'abc'` is 22P02, a number past `integer` 22003, a bad date 22007),
 * where MySQL and SQLite find nothing. So an exact match on PostgreSQL checks
 * the value against the column's real type, read from the schema once per
 * table and process.
 *
 * @internal
 */
final class PostgresColumns
{
    /** @var array<string, array<string, string>> "connection|database|table" => [column => type name] */
    private static array $types = [];

    /**
     * The type name (`int4`, `uuid`, `timestamptz`…) of each column, or an
     * empty list when the schema cannot be read.
     *
     * @return array<string, string>
     */
    public static function types(Connection $connection, string $table): array
    {
        // The database too: tenants can share a connection name.
        $key = $connection->getName().'|'.$connection->getDatabaseName().'|'.$table;

        if (isset(self::$types[$key])) {
            return self::$types[$key];
        }

        $types = [];

        try {
            foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
                $types[$column['name']] = (string) $column['type_name'];
            }
        } catch (Throwable) {
            // Not read again for every column; the cast decides instead.
        }

        return self::$types[$key] = $types;
    }

    /** Forget the types read so far (tests recreate tables). */
    public static function flush(): void
    {
        self::$types = [];
    }

    /**
     * Whether PostgreSQL takes the value as a literal of the type: true or
     * false, or null for a type not known here (compare it as text then).
     * The forms people type are accepted (`007`, a uuid without hyphens,
     * `2026-10-28T10:00`, fractions of a second); what PostgreSQL would reject
     * is not.
     */
    public static function accepts(string $typeName, string $value): ?bool
    {
        // PostgreSQL skips the spaces around a number, a boolean or a date; not around a uuid.
        $trimmed = trim($value);

        return match ($typeName) {
            'int2' => self::integer($trimmed, '32767', '32768'),
            'int4' => self::integer($trimmed, '2147483647', '2147483648'),
            'int8' => self::integer($trimmed, '9223372036854775807', '9223372036854775808'),
            'numeric' => is_numeric($trimmed) && is_finite((float) $trimmed),
            'float4' => self::float($trimmed, 3.4028234663852886e38, 1.401298464324817e-45),
            'float8' => self::float($trimmed, PHP_FLOAT_MAX, 4.9e-324),
            'bool' => in_array(strtolower($trimmed), ['t', 'f', 'true', 'false', 'y', 'n', 'yes', 'no', 'on', 'off', '1', '0'], true),
            'uuid' => preg_match('/^\{?[0-9a-f]{4}(-?[0-9a-f]{4}){7}\}?$/i', $value) === 1,
            'date' => self::date($trimmed),
            'timestamp', 'timestamptz' => self::timestamp($trimmed),
            'time', 'timetz' => self::time($trimmed),
            'varchar', 'bpchar', 'text', 'citext', 'name', 'char' => true,
            default => null,
        };
    }

    /** A whole number (leading zeros and a sign allowed) within the bounds, compared as digits. */
    private static function integer(string $value, string $max, string $minAbs): bool
    {
        if (preg_match('/^([+-]?)0*(\d+)$/', $value, $m) !== 1) {
            return false;
        }

        $bound = $m[1] === '-' ? $minAbs : $max;

        return strlen($m[2]) < strlen($bound) || (strlen($m[2]) === strlen($bound) && strcmp($m[2], $bound) <= 0);
    }

    /** A number the float type can hold: not past its largest, not a non-zero that rounds to 0. */
    private static function float(string $value, float $max, float $min): bool
    {
        if (! is_numeric($value)) {
            return false;
        }

        $number = abs((float) $value);
        $zero = preg_match('/^[+-]?0*\.?0*(e[+-]?\d+)?$/i', $value) === 1;

        return $number <= $max && ($zero || $number >= $min);
    }

    /** `Y-m-d`: a day that exists, from year 1 (PostgreSQL has no year 0). */
    private static function date(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) === 1
            && (int) $m[1] >= 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** A date, optionally with a time (` ` or `T`) and a zone (`Z`, `+02`, `+02:00`). */
    private static function timestamp(string $value): bool
    {
        if (preg_match('/^(\S+?)(?:[ T](.+?))?(?:Z|[+-]\d{2}(?::?\d{2})?)?$/i', $value, $m) !== 1) {
            return false;
        }

        return self::date($m[1]) && (! isset($m[2]) || $m[2] === '' || self::time($m[2]));
    }

    /** `H:i`, `H:i:s` or `H:i:s.u`; `24:00` is the end of the day. */
    private static function time(string $value): bool
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?$/', $value, $m) !== 1) {
            return false;
        }

        [$hour, $minute, $second] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];

        if ($hour === 24) {
            return $minute === 0 && $second === 0;
        }

        return $hour < 24 && $minute < 60 && $second <= 60;
    }
}
