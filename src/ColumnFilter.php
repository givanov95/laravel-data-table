<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use DateTimeZone;
use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\Exceptions\InvalidColumnNameException;
use Givanov95\DataTable\Support\DateSqlExpression;
use Givanov95\DataTable\Support\LikeExpression;
use Illuminate\Database\Eloquent\Builder;

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
            $this->applyPriceFilter($builder, $columnKey, $filterValue, $exact, $useOrWhere);
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
        $clientTZ = new DateTimeZone($timeZone ?: date_default_timezone_get());
        $serverTZ = new DateTimeZone(date_default_timezone_get());

        $helper = new DateTimeHelper($dateColumn, $clientTZ, $serverTZ, (string) $filterValue);
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
    ): void {
        // Digits only, taken from the raw input: a `%` or `_` typed by the user must not reach the pattern.
        $price = preg_replace('/\D/', '', (string) $filterValue);

        if ($price === '') {
            return;
        }

        $method = $useOrWhere ? 'orWhere' : 'where';
        $builder->{$method}($columnKey, $exact ? '=' : 'LIKE', $exact ? $price : "%{$price}%");
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
                $this->whereColumnMatches($query, $column->relationColumn, $filterValue, $exact);
            });

            return;
        }

        $this->whereColumnMatches($builder, "{$table}.{$columnKey}", $filterValue, $exact, $useOrWhere ? 'or' : 'and');
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
