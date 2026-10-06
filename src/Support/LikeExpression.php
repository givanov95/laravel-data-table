<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;

/**
 * A "contains" match (`LIKE '%value%'`) on user input, with `%` and `_` in the
 * value matched literally instead of acting as wildcards. Case is ignored on
 * every driver: PostgreSQL gets `ILIKE`, the others follow the column's collation
 * (case-insensitive by default).
 *
 * `$sql` carries a single `?`; bind `$pattern` to it.
 */
final class LikeExpression
{
    /**
     * The LIKE escape character, the same on every driver. A backslash would not
     * do: it is MySQL's default only while NO_BACKSLASH_ESCAPES is off, and
     * ESCAPE '\' is an unterminated string there otherwise.
     */
    private const ESCAPE = '!';

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

        $escape = self::ESCAPE;

        $sql = match ($connection->getDriverName()) {
            // PostgreSQL's LIKE is case-sensitive, unlike the other drivers'. The ::text cast is
            // the one the grammar puts on a LIKE, so numeric columns stay searchable.
            'pgsql' => "{$wrapped}::text ILIKE ? ESCAPE '{$escape}'",
            default => "{$wrapped} LIKE ? ESCAPE '{$escape}'",
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
        $escape = self::ESCAPE;

        return strtr($value, [$escape => $escape.$escape, '%' => $escape.'%', '_' => $escape.'_']);
    }
}
