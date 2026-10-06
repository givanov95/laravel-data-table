<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;

/**
 * A "contains" match (`LIKE '%value%'`) on user input, with `%`, `_` and `\` in
 * the value matched literally instead of acting as wildcards.
 *
 * `$sql` carries a single `?`; bind `$pattern` to it.
 */
final class LikeExpression
{
    private function __construct(
        public readonly string $sql,
        public readonly string $pattern,
    ) {
    }

    /**
     * @param string $column unquoted, optionally table-qualified column (`posts.title`)
     */
    public static function contains(Connection $connection, string $column, string $value): self
    {
        $wrapped = $connection->getQueryGrammar()->wrap($column);

        $sql = match ($connection->getDriverName()) {
            // Backslash is the default escape character, and a literal ESCAPE '\' would
            // be an unterminated string in MySQL.
            'mysql', 'mariadb' => "{$wrapped} LIKE ?",
            // The same ::text cast the grammar puts on a LIKE, so numeric columns stay searchable.
            'pgsql'            => "{$wrapped}::text LIKE ?",
            // No default escape character (sqlite, sqlsrv, ...).
            default            => "{$wrapped} LIKE ? ESCAPE '\\'",
        };

        return new self($sql, '%'.self::escape($value).'%');
    }

    /**
     * Adds the match to a query as a `where` (or an `orWhere`, with `$boolean = 'or'`).
     *
     * @param Builder<*> $query
     */
    public static function apply(Builder $query, string $column, string $value, string $boolean = 'and'): void
    {
        $expression = self::contains($query->getQuery()->getConnection(), $column, $value);

        $query->whereRaw($expression->sql, [$expression->pattern], $boolean);
    }

    public static function escape(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
