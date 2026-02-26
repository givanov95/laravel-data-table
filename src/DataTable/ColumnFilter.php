<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use BackedEnum;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ColumnFilter
{
    private DataTable $dataTable;

    private Builder $builder;

    public function __construct(DataTable $dataTable)
    {
        $this->dataTable = $dataTable;
    }

    public function apply(
        Builder $builder,
        string $columnKey,
        mixed $filterValue,
        bool $useOrWhere = false,
        ?string $timeZone = null
    ): self {
        $column = $this->dataTable->getColumnByKey($columnKey);
        if (! $column) {
            throw new InvalidArgumentException('Invalid column name');
        }

        $enumColumns = $this->dataTable->getEnumColumns();
        $dateColumns = $this->dataTable->getDateColumns();
        $priceColumns = $this->dataTable->getPriceColumns();
        $table = $builder->getModel()->getTable();

        $operator = $column->exactMatch ? '=' : 'LIKE';
        $value = $column->exactMatch ? $filterValue : "%{$filterValue}%";

        if (! empty($dateColumns[$columnKey])) {
            $clientTZ = new DateTimeZone($timeZone ?? date_default_timezone_get());
            $serverTZ = new DateTimeZone(date_default_timezone_get());

            $dateTimeHelper = new DateTimeHelper($dateColumns[$columnKey], $clientTZ, $serverTZ, $filterValue);
            $convertedDate = $dateTimeHelper->convert()->convertedDate;
            if (! $convertedDate) {
                return $this;
            }
            $value = $column->exactMatch ? $convertedDate : "%{$convertedDate}%";

            // relation-aware filter
            if ($column->relation) {
                $builder->{$useOrWhere ? 'orWhereHasRelation' : 'whereHasRelation'}(
                    $column->relation->relationString,
                    fn ($query) => $query->whereRaw(
                        "DATE_FORMAT(`{$table}`.{$column->relation->relationColumn}, '{$dateTimeHelper->sqlFormat}' ) {$operator} ?",
                        [$value]
                    )
                );
            } else {
                $builder->{$useOrWhere ? 'orWhere' : 'where'}(
                    fn ($query) => $query->whereRaw(
                        "DATE_FORMAT(`{$table}`.{$columnKey}, '{$dateTimeHelper->sqlFormat}' ) {$operator} ?",
                        [$value]
                    )
                );
            }
        }

        // Enum (PHP 8.1+ backed enum)
        elseif ($enumColumns->get($columnKey)) {

            $enumColumn = $enumColumns->get($columnKey);
            $enumClass = $enumColumn->getEnum();

            if (! enum_exists($enumClass)) {
                throw new InvalidArgumentException("{$enumClass} is not a valid enum.");
            }

            if (! is_subclass_of($enumClass, BackedEnum::class)) {
                throw new InvalidArgumentException("{$enumClass} must be a backed enum.");
            }

            $normalizedFilter = str_replace(' ', '_', $filterValue);

            $matchedEnumIds = collect($enumClass::cases())
                ->filter(fn ($case) => str_contains(
                    strtolower($case->name),
                    strtolower($normalizedFilter)
                )
                )
                ->pluck('value');

            $builder = $column->relation
                ? ($useOrWhere
                    ? $builder->orWhereIn($column->relation->relationString, $matchedEnumIds)
                    : $builder->whereIn($column->relation->relationString, $matchedEnumIds)
                )
                : ($useOrWhere
                    ? $builder->orWhereIn("{$table}.{$columnKey}", $matchedEnumIds)
                    : $builder->whereIn("{$table}.{$columnKey}", $matchedEnumIds)
                );
        }

        // Price
        elseif ($priceColumns->keys()->contains($columnKey)) {
            $price = preg_replace('/[^0-9%]/', '', $value);
            if ($price !== '%%') {
                $builder->{$useOrWhere ? 'orWhere' : 'where'}($columnKey, $operator, $price);
            }
        }

        // Default / relation
        else {
            if ($column->relation) {
                $builder = $useOrWhere
                    ? $builder->orWhereRelation($column->relation->relationString, $column->relation->relationColumn, $operator, $value)
                    : $builder->whereRelation($column->relation->relationString, $column->relation->relationColumn, $operator, $value);
            } else {
                $builder = $useOrWhere
                    ? $builder->orWhere("{$table}.{$columnKey}", $operator, $value)
                    : $builder->where("{$table}.{$columnKey}", $operator, $value);
            }
        }

        $this->builder = $builder;

        return $this;
    }

    public function getBuilder(): Builder
    {
        return $this->builder;
    }
}
