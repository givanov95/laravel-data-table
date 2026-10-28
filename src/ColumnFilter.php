<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use DateTimeZone;
use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\Exceptions\InvalidColumnNameException;
use Givanov95\DataTable\Support\DateSqlExpression;
use Givanov95\DataTable\Support\LikeExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class ColumnFilter
{
    private Builder $builder;

    public function __construct(private DataTable $dataTable)
    {
    }

    public function apply(
        Builder $builder,
        string $columnKey,
        mixed $filterValue,
        bool $useOrWhere = false,
        ?string $timeZone = null,
    ): self {
        $column = $this->dataTable->getColumnByKey($columnKey);

        if (! $column) {
            throw new InvalidColumnNameException("Invalid column name: {$columnKey}");
        }

        $enumColumns = $this->dataTable->getEnumColumns();
        $dateColumns = $this->dataTable->getDateColumns();
        $priceColumns = $this->dataTable->getPriceColumns();
        $table = $builder->getModel()->getTable();

        $exact = $column->isExactMatch();

        if ($dateColumns->has($columnKey)) {
            $this->applyDateFilter($builder, $column, $columnKey, $filterValue, $useOrWhere, $timeZone, $table);
        } elseif ($enumColumns->has($columnKey)) {
            $this->applyEnumFilter($builder, $column, $columnKey, $filterValue, $useOrWhere, $table);
        } elseif ($priceColumns->has($columnKey)) {
            $this->applyPriceFilter($builder, $columnKey, $filterValue, $exact, $useOrWhere, $table);
        } else {
            $this->applyDefaultFilter($builder, $column, $columnKey, $filterValue, $exact, $useOrWhere, $table);
        }

        $this->builder = $builder;

        return $this;
    }

    public function getBuilder(): Builder
    {
        return $this->builder;
    }

    private function applyDateFilter(
        Builder $builder,
        $column,
        string $columnKey,
        mixed $filterValue,
        bool $useOrWhere,
        ?string $timeZone,
        string $table,
    ): void {
        $dateColumn = $this->dataTable->getDateColumns()->get($columnKey);
        $serverTZ = new DateTimeZone(date_default_timezone_get());
        $clientTZ = DateTimeHelper::zone($timeZone) ?? $serverTZ;

        $helper = new DateTimeHelper($dateColumn, $clientTZ, $serverTZ, (string) $filterValue);
        $range = $helper->range();

        if ($range !== null) {
            $this->whereInRange($builder, $column, $columnKey, $range, $useOrWhere, $table);

            return;
        }

        $convertedDate = $helper->convert()->convertedDate;

        if (! $convertedDate) {
            return;
        }

        $operator = $column->isExactMatch() ? '=' : 'LIKE';
        $value = $column->isExactMatch() ? $convertedDate : "%{$convertedDate}%";

        if ($column instanceof RelationColumn) {
            $method = $useOrWhere ? 'orWhereHas' : 'whereHas';
            $builder->{$method}($column->relationString, function ($query) use ($column, $helper, $operator, $value) {
                $this->whereDateFormatted(
                    $query,
                    $query->getModel()->getTable().'.'.$column->relationColumn,
                    $helper->format,
                    $operator,
                    $value,
                );
            });

            return;
        }

        $method = $useOrWhere ? 'orWhere' : 'where';
        $builder->{$method}(function ($query) use ($table, $columnKey, $helper, $operator, $value) {
            $this->whereDateFormatted($query, "{$table}.{$columnKey}", $helper->format, $operator, $value);
        });
    }

    /**
     * `from <= column < to` on the stored value: the same on every driver, and
     * it can use an index.
     *
     * @param array{0: string, 1: string} $range
     */
    private function whereInRange(
        Builder $builder,
        $column,
        string $columnKey,
        array $range,
        bool $useOrWhere,
        string $table,
    ): void {
        $between = static function (Builder $query, string $qualified) use ($range): void {
            $query->where($qualified, '>=', $range[0])->where($qualified, '<', $range[1]);
        };

        if ($column instanceof RelationColumn) {
            $method = $useOrWhere ? 'orWhereHas' : 'whereHas';
            $builder->{$method}($column->relationString, function ($query) use ($column, $between) {
                $between($query, $query->getModel()->getTable().'.'.$column->relationColumn);
            });

            return;
        }

        $method = $useOrWhere ? 'orWhere' : 'where';
        $builder->{$method}(fn ($query) => $between($query, "{$table}.{$columnKey}"));
    }

    private function whereDateFormatted(
        Builder $query,
        string $column,
        string $phpFormat,
        string $operator,
        string $value,
    ): void {
        $expression = DateSqlExpression::make($query->getQuery()->getConnection(), $column, $phpFormat);

        $query->whereRaw("{$expression->sql} {$operator} ?", [$expression->format, $value]);
    }

    private function applyEnumFilter(
        Builder $builder,
        $column,
        string $columnKey,
        mixed $filterValue,
        bool $useOrWhere,
        string $table,
    ): void {
        $enumColumn = $this->dataTable->getEnumColumns()->get($columnKey);
        $enumClass = $enumColumn->getEnumClass();

        $normalizedFilter = str_replace(' ', '_', (string) $filterValue);

        $matchedEnumIds = collect($enumClass::cases())
            ->filter(fn ($case) => str_contains(strtolower($case->name), strtolower($normalizedFilter)))
            ->pluck('value');

        if ($column instanceof RelationColumn) {
            $useOrWhere
                ? $builder->orWhereIn($column->relationString, $matchedEnumIds)
                : $builder->whereIn($column->relationString, $matchedEnumIds);

            return;
        }

        $useOrWhere
            ? $builder->orWhereIn("{$table}.{$columnKey}", $matchedEnumIds)
            : $builder->whereIn("{$table}.{$columnKey}", $matchedEnumIds);
    }

    private function applyPriceFilter(
        Builder $builder,
        string $columnKey,
        mixed $filterValue,
        bool $exact,
        bool $useOrWhere,
        string $table,
    ): void {
        // Digits only, taken from the raw input: a `%` or `_` typed by the user must not reach the pattern.
        $price = preg_replace('/\D/', '', (string) $filterValue);

        if ($price === '') {
            return;
        }

        // Qualified: a join of a relation ordering can bring a column of the same name.
        $column = "{$table}.{$columnKey}";
        $boolean = $useOrWhere ? 'or' : 'and';

        if ($exact) {
            $this->whereEquals($builder, $column, $price, $boolean);

            return;
        }

        LikeExpression::apply($builder, $column, $price, $boolean);
    }

    private function applyDefaultFilter(
        Builder $builder,
        $column,
        string $columnKey,
        mixed $filterValue,
        bool $exact,
        bool $useOrWhere,
        string $table,
    ): void {
        if ($column instanceof RelationColumn) {
            $method = $useOrWhere ? 'orWhereHas' : 'whereHas';
            $builder->{$method}($column->relationString, function ($query) use ($column, $filterValue, $exact) {
                $this->whereColumnMatches(
                    $query,
                    $query->getModel()->getTable().'.'.$column->relationColumn,
                    $filterValue,
                    $exact,
                );
            });

            return;
        }

        $this->whereColumnMatches($builder, "{$table}.{$columnKey}", $filterValue, $exact, $useOrWhere ? 'or' : 'and');
    }

    /**
     * `column = value`, without handing the database a value its column type
     * rejects: PostgreSQL fails the whole query (`id = 'abc'` is 22P02, a uuid,
     * boolean or date column the same, a number past an `integer` 22003) where
     * MySQL and SQLite just find nothing.
     *
     * - A column numeric by its cast (or an integer key) keeps the typed
     *   comparison (an index can be used); a value it cannot hold matches
     *   nothing, without asking the database.
     * - Any other column on PostgreSQL is compared as text: its type is not
     *   known here (a uuid key, a boolean or date cast, a number without a cast).
     *
     * The false clause takes the same boolean, so a global search whose every
     * column is left out is not an empty group (which Laravel drops: every row
     * would match).
     *
     * @param string $column table-qualified (`posts.id`)
     */
    private function whereEquals(Builder $query, string $column, mixed $value, string $boolean = 'and'): void
    {
        $model = $query->getModel();
        $name = (string) str($column)->afterLast('.');

        if (self::numericCast($model, $name) !== null) {
            self::fitsColumn($model, $name, $value)
                ? $query->where($column, '=', $value, $boolean)
                : $query->whereRaw('1 = 0', [], $boolean);

            return;
        }

        $connection = $query->getQuery()->getConnection();

        if ($connection->getDriverName() === 'pgsql') {
            $wrapped = $connection->getQueryGrammar()->wrap($column);
            $query->whereRaw("CAST({$wrapped} AS TEXT) = ?", [(string) $value], $boolean);

            return;
        }

        $query->where($column, '=', $value, $boolean);
    }

    /**
     * `int` or `decimal` for a column numeric by its cast (or an integer
     * primary key, which Laravel casts to int), null otherwise.
     */
    private static function numericCast(Model $model, string $column): ?string
    {
        $cast = $model->getCasts()[$column] ?? null;

        if ($cast === null && $column === $model->getKeyName() && in_array($model->getKeyType(), ['int', 'integer'], true)) {
            $cast = 'int';
        }

        return match (is_string($cast) ? strtolower(explode(':', $cast)[0]) : null) {
            'int', 'integer' => 'int',
            'float', 'double', 'real', 'decimal' => 'decimal',
            default => null,
        };
    }

    /**
     * Whether a value can be compared with `=` to a column that is numeric by
     * its cast (or an integer key): a whole number for an integer, a number
     * for a decimal. Other columns take any value.
     *
     * An integer has to fit 32 bits: the cast does not say whether the column
     * is `integer` or `bigint`, and a larger value is an error (22003) on an
     * `integer` column in PostgreSQL. A bigint value above that is not found
     * by an exact match.
     *
     * @internal
     */
    public static function fitsColumn(Model $model, string $column, mixed $value): bool
    {
        return match (self::numericCast($model, $column)) {
            'int' => filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]],
            ) !== false,
            'decimal' => is_numeric($value) && is_finite((float) $value),
            default => true,
        };
    }

    private function whereColumnMatches(
        Builder $query,
        string $column,
        mixed $filterValue,
        bool $exact,
        string $boolean = 'and',
    ): void {
        if ($exact) {
            $this->whereEquals($query, $column, $filterValue, $boolean);

            return;
        }

        LikeExpression::apply($query, $column, (string) $filterValue, $boolean);
    }
}
