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
            $builder->where($column, '=', $price, $boolean);

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
                if ($exact && ! self::fitsColumn($query->getModel(), $column->relationColumn, $filterValue)) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $this->whereColumnMatches($query, $column->relationColumn, $filterValue, $exact);
            });

            return;
        }

        if ($exact && ! self::fitsColumn($builder->getModel(), $columnKey, $filterValue)) {
            // `id = 'abc'` is an error on PostgreSQL (22P02), not "no match". In
            // the global search the column is left out; a filter on it alone matches nothing.
            if (! $useOrWhere) {
                $builder->whereRaw('1 = 0');
            }

            return;
        }

        $this->whereColumnMatches($builder, "{$table}.{$columnKey}", $filterValue, $exact, $useOrWhere ? 'or' : 'and');
    }

    /**
     * Whether a value can be compared with `=` to a column that is numeric by
     * its cast (or an integer key): a whole number in the 64-bit range for an
     * integer, a number for a decimal. Other columns take any value.
     */
    private static function fitsColumn(Model $model, string $column, mixed $value): bool
    {
        $cast = $model->getCasts()[$column] ?? null;

        if ($cast === null && $column === $model->getKeyName() && in_array($model->getKeyType(), ['int', 'integer'], true)) {
            $cast = 'int';
        }

        $cast = is_string($cast) ? strtolower(explode(':', $cast)[0]) : null;

        return match ($cast) {
            'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'float', 'double', 'real', 'decimal' => is_numeric($value) && is_finite((float) $value),
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
            $query->where($column, '=', $filterValue, $boolean);

            return;
        }

        LikeExpression::apply($query, $column, (string) $filterValue, $boolean);
    }
}
