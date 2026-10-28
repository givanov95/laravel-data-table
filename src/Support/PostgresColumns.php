<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Support;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
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
    /** @var array<string, array<string, string>> "connection|table" => [column => type name] */
    private static array $types = [];

    /**
     * The type name (`int4`, `uuid`, `timestamptz`…) of each column, or an
     * empty list when the schema cannot be read.
     *
     * @return array<string, string>
     */
    public static function types(Connection $connection, string $table): array
    {
        $key = $connection->getName().'|'.$table;

        if (isset(self::$types[$key])) {
            return self::$types[$key];
        }

        try {
            $types = [];

            foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
                $types[$column['name']] = (string) $column['type_name'];
            }
        } catch (Throwable) {
            return [];
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
     */
    public static function accepts(string $typeName, string $value): ?bool
    {
        $trimmed = trim($value);

        return match ($typeName) {
            'int2' => self::integer($trimmed, -32768, 32767),
            'int4' => self::integer($trimmed, -2147483648, 2147483647),
            'int8' => self::integer($trimmed, PHP_INT_MIN, PHP_INT_MAX),
            'numeric', 'float4', 'float8' => is_numeric($trimmed) && is_finite((float) $trimmed),
            'bool' => in_array(strtolower($trimmed), ['t', 'f', 'true', 'false', 'y', 'n', 'yes', 'no', 'on', 'off', '1', '0'], true),
            'uuid' => Str::isUuid($trimmed),
            'date' => self::dateTime($trimmed, ['Y-m-d']),
            'timestamp', 'timestamptz' => self::dateTime($trimmed, ['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s']),
            'time', 'timetz' => self::dateTime($trimmed, ['H:i', 'H:i:s']),
            'varchar', 'bpchar', 'text', 'citext', 'name', 'char' => true,
            default => null,
        };
    }

    private static function integer(string $value, int $min, int $max): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) !== false;
    }

    /**
     * @param list<string> $formats
     */
    private static function dateTime(string $value, array $formats): bool
    {
        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($parsed !== false && $parsed->format($format) === $value) {
                return true;
            }
        }

        return false;
    }
}
