<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Support;

use Givanov95\DataTable\Exceptions\UnsupportedDriverException;
use Illuminate\Database\Connection;

/**
 * Formats a date column to text in SQL, in the dialect of the connection's driver
 * (DATE_FORMAT / TO_CHAR / strftime), so it can be compared with a filter value.
 *
 * `$sql` carries a single `?` for the driver-specific format; bind `$format` to it
 * before any other binding.
 */
final class DateSqlExpression
{
    /** PHP date() token => the same token in each SQL dialect. */
    private const TOKENS = [
        'mysql' => ['d' => '%d', 'm' => '%m', 'Y' => '%Y',   'H' => '%H',  'i' => '%i', 's' => '%s'],
        'pgsql' => ['d' => 'DD', 'm' => 'MM', 'Y' => 'YYYY', 'H' => 'HH24', 'i' => 'MI', 's' => 'SS'],
        'sqlite' => ['d' => '%d', 'm' => '%m', 'Y' => '%Y',  'H' => '%H',  'i' => '%M', 's' => '%S'],
    ];

    /** Characters that TO_CHAR copies through unchanged; anything else is quoted. */
    private const PGSQL_PLAIN_LITERALS = ' .,:/-';

    private function __construct(
        public readonly string $sql,
        public readonly string $format,
    ) {
    }

    /**
     * @param string $column    unquoted, optionally table-qualified column (`posts.created_at`)
     * @param string $phpFormat the PHP date() format the filter value was parsed with
     */
    public static function make(Connection $connection, string $column, string $phpFormat): self
    {
        $driver  = $connection->getDriverName();
        $wrapped = $connection->getQueryGrammar()->wrap($column);

        return match ($driver) {
            'mysql', 'mariadb' => new self("DATE_FORMAT({$wrapped}, ?)", self::translate($phpFormat, 'mysql')),
            'pgsql'            => new self("TO_CHAR({$wrapped}, ?)", self::translate($phpFormat, 'pgsql')),
            'sqlite'           => new self("strftime(?, {$wrapped})", self::translate($phpFormat, 'sqlite')),
            default            => throw new UnsupportedDriverException(
                "Date filtering is not supported on the '{$driver}' database driver. Supported: mysql, mariadb, pgsql, sqlite.",
                ['driver' => $driver],
            ),
        };
    }

    private static function translate(string $phpFormat, string $dialect): string
    {
        $result = '';

        foreach (mb_str_split($phpFormat) as $char) {
            $result .= self::TOKENS[$dialect][$char] ?? self::literal($char, $dialect);
        }

        return $result;
    }

    private static function literal(string $char, string $dialect): string
    {
        if ($dialect === 'pgsql') {
            return str_contains(self::PGSQL_PLAIN_LITERALS, $char)
                ? $char
                : '"'.addcslashes($char, '"\\').'"';
        }

        // `%` starts a specifier in DATE_FORMAT and strftime.
        return $char === '%' ? '%%' : $char;
    }
}
