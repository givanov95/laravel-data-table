<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use DateTimeZone;
use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\Exceptions\InvalidColumnNameException;
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

        $operator = $column->isExactMatch() ? '=' : 'LIKE';
        $value = $column->isExactMatch() ? $filterValue : "%{$filterValue}%";

        if ($dateColumns->has($columnKey)) {
            $this->applyDateFilter($builder, $column, $columnKey, $filterValue, $useOrWhere, $timeZone, $table);
        } elseif ($enumColumns->has($columnKey)) {
            $this->applyEnumFilter($builder, $column, $columnKey, $filterValue, $useOrWhere, $table);
        } elseif ($priceColumns->has($columnKey)) {
            $this->applyPriceFilter($builder, $columnKey, $value, $operator, $useOrWhere);
        } else {
            $this->applyDefaultFilter($builder, $column, $columnKey, $value, $operator, $useOrWhere, $table);
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
            $builder->{$method}($column->relationString, fn ($query) => $query->whereRaw(
                "DATE_FORMAT(`{$column->targetTable}`.`{$column->relationColumn}`, '{$helper->sqlFormat}') {$operator} ?",
                [$value],
            ));

            return;
        }

        $method = $useOrWhere ? 'orWhere' : 'where';
        $builder->{$method}(fn ($query) => $query->whereRaw(
            "DATE_FORMAT(`{$table}`.`{$columnKey}`, '{$helper->sqlFormat}') {$operator} ?",
            [$value],
        ));
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
        mixed $value,
        string $operator,
        bool $useOrWhere,
    ): void {
        $price = preg_replace('/[^0-9%]/', '', (string) $value);

        if ($price === '%%' || $price === '') {
            return;
        }

        $method = $useOrWhere ? 'orWhere' : 'where';
        $builder->{$method}($columnKey, $operator, $price);
    }

    private function applyDefaultFilter(
        Builder $builder,
        $column,
        string $columnKey,
        mixed $value,
        string $operator,
        bool $useOrWhere,
        string $table,
    ): void {
        if ($column instanceof RelationColumn) {
            $useOrWhere
                ? $builder->orWhereRelation($column->relationString, $column->relationColumn, $operator, $value)
                : $builder->whereRelation($column->relationString, $column->relationColumn, $operator, $value);

            return;
        }

        $useOrWhere
            ? $builder->orWhere("{$table}.{$columnKey}", $operator, $value)
            : $builder->where("{$table}.{$columnKey}", $operator, $value);
    }
}
