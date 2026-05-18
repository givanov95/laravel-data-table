<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Givanov95\DataTable\Columns\Column;
use Givanov95\DataTable\Columns\DateColumn;
use Givanov95\DataTable\Columns\EnumColumn;
use Givanov95\DataTable\Columns\PriceColumn;
use Givanov95\DataTable\Columns\RelationColumn;
use Givanov95\DataTable\Columns\TranslatableColumn;
use Givanov95\DataTable\Exceptions\InvalidColumnNameException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;

/**
 * Server-side DataTable builder for Laravel. Wraps an Eloquent query, applies
 * filtering / ordering / pagination based on the incoming HTTP request, and
 * produces a JSON-friendly payload that the bundled Vue components consume.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
class DataTable
{
    public Paginator $paginator;

    public ?RawOrdering $rawOrdering = null;

    /** @var Collection<int, \Illuminate\Database\Eloquent\Model> */
    public Collection $data;

    /** @var Collection<string, Column> */
    public Collection $columns;

    /** @var Collection<string, TranslatableColumn> */
    public Collection $translatableColumns;

    /** @var Collection<string, EnumColumn> */
    private Collection $enumColumns;

    /** @var Collection<string, PriceColumn> */
    private Collection $priceColumns;

    /** @var Collection<string, DateColumn> */
    private Collection $dateColumns;

    /** @var Collection<int, TableRelation> */
    private Collection $relations;

    private Builder $builder;

    private ColumnFilter $columnFilter;

    private Ordering $ordering;

    private readonly Request $request;

    public function __construct(Builder $builder, ?Request $request = null)
    {
        $this->relations = new Collection();
        $this->columns = new Collection();
        $this->translatableColumns = new Collection();
        $this->enumColumns = new Collection();
        $this->priceColumns = new Collection();
        $this->dateColumns = new Collection();
        $this->columnFilter = new ColumnFilter($this);

        $this->request = $request ?? App::make(Request::class);
        $this->ordering = Ordering::fromRequest($this->request);

        $this->setBuilder($builder);
    }

    /**
     * Apply filtering / ordering / pagination and materialise the result.
     *
     * @param ?callable(Builder):void $callbackBeforePaginate Custom hook applied right before paginate(); receives the builder.
     */
    public function process(
        ?DataTableParams $params = null,
        ?callable $callbackBeforePaginate = null,
    ): self {
        $params ??= DataTableParams::fromRequest($this->request);

        $this->initRelations();
        $this->applyModelFiltering($params);
        $this->softRestoreRecord($params);
        $this->applyOrderByColumns();

        if ($params->globalFilter !== null && $params->globalFilter !== '') {
            $this->applyGlobalFilter($params->globalFilter);
        }

        $this->applyCallbackBeforePaginate($callbackBeforePaginate);

        /** @var LengthAwarePaginatorContract $lengthAwarePaginator */
        $lengthAwarePaginator = $this->getBuilder()->paginate($params->perPage);
        $this->paginator = new Paginator($lengthAwarePaginator->withQueryString());

        $this->data = (new Collection($this->paginator->items()))->map(function ($item) {
            foreach ($this->translatableColumns as $translatableColumn) {
                $key = $translatableColumn->getTranslationKey();
                $databaseColumnName = DataTableConfig::getTranslatableColumnName();
                $translation = optional($item->translations)->firstWhere($databaseColumnName, $key);

                if ($translation) {
                    $item->{$key} = $translation->text;
                }
            }

            return $item;
        });

        return $this;
    }

    /**
     * Mutate the query directly. Useful for ad-hoc filters that don't map
     * cleanly to a Column definition.
     *
     * @param callable(Builder):void $callback
     */
    public function advancedSearch(callable $callback): self
    {
        $callback($this->getBuilder());

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Relation eager-loading
    |--------------------------------------------------------------------------
    */

    public function setRelation(string $relationString, ?array $columnsToSelect = null): self
    {
        $relation = new TableRelation($relationString);

        if (! empty($columnsToSelect)) {
            $relation->setColumnsToSelect($columnsToSelect);
        }

        $this->relations->push($relation);

        return $this;
    }

    /** @return Collection<int, TableRelation> */
    public function getRelations(): Collection
    {
        return $this->relations;
    }

    private function initRelations(): void
    {
        $builder = $this->getBuilder();

        foreach ($this->getRelations() as $relation) {
            $builder->with([
                $relation->relationsString => function ($query) use ($relation) {
                    if (! empty($relation->columnsToSelect)) {
                        $query->select($relation->columnsToSelect);
                    }
                },
            ]);
        }

        $this->setBuilder($builder);
    }

    /*
    |--------------------------------------------------------------------------
    | Columns
    |--------------------------------------------------------------------------
    */

    /**
     * Register a column. Accepts either a Column object or positional arguments:
     *
     *     ->setColumn('id', '#', searchable: true, orderable: true)
     *     ->setColumn(new Column('id', '#', true, true))
     */
    public function setColumn(
        string|Column $keyOrColumn,
        ?string $label = null,
        bool $searchable = false,
        bool $orderable = false,
        bool $exactMatch = false,
    ): self {
        $column = $keyOrColumn instanceof Column
            ? $keyOrColumn
            : new Column(
                databaseColumnName: $keyOrColumn,
                label: $label,
                searchable: $searchable,
                orderable: $orderable,
                exactMatch: $exactMatch,
            );

        $this->columns->put($column->getDatabaseColumnName(), $column);

        return $this;
    }

    public function setRelationColumn(RelationColumn $relationColumn): self
    {
        $this->columns->put($relationColumn->getDatabaseColumnName(), $relationColumn);

        return $this;
    }

    public function setTranslatableColumn(TranslatableColumn $translatableColumn): self
    {
        $this->translatableColumns->put($translatableColumn->getTranslationKey(), $translatableColumn);
        $this->columns->put($translatableColumn->getTranslationKey(), $translatableColumn);

        return $this;
    }

    /**
     * Register an enum-aware column.
     *
     * @param class-string<\BackedEnum> $enumClass
     */
    public function setEnumColumn(string $columnKey, string $enumClass): self
    {
        $this->enumColumns->put($columnKey, new EnumColumn($enumClass));

        return $this;
    }

    public function setPriceColumn(string $columnKey): self
    {
        $this->priceColumns->put($columnKey, new PriceColumn());

        return $this;
    }

    public function setDateColumn(
        string $columnKey,
        string $format,
        string $dateDelimiter = '.',
        string $timeDelimiter = ':',
    ): self {
        $this->dateColumns->put($columnKey, new DateColumn($format, $dateDelimiter, $timeDelimiter));

        return $this;
    }

    /** @return Collection<string, Column> */
    public function getColumns(): Collection
    {
        return $this->columns;
    }

    public function getColumnByKey(string $key): ?Column
    {
        if ($this->columns->has($key)) {
            return $this->columns->get($key);
        }

        foreach ($this->columns as $column) {
            if ($column->getDatabaseColumnName() === $key) {
                return $column;
            }
        }

        foreach ($this->translatableColumns as $column) {
            if ($column->getTranslationKey() === $key) {
                return $column;
            }
        }

        return null;
    }

    /** @return Collection<string, TranslatableColumn> */
    public function getTranslatableColumns(): Collection
    {
        return $this->translatableColumns;
    }

    /** @return Collection<string, RelationColumn> */
    public function getRelationColumns(): Collection
    {
        return $this->columns->filter(fn (Column $column) => $column instanceof RelationColumn);
    }

    /** @return Collection<string, EnumColumn> */
    public function getEnumColumns(): Collection
    {
        return $this->enumColumns;
    }

    /** @return Collection<string, PriceColumn> */
    public function getPriceColumns(): Collection
    {
        return $this->priceColumns;
    }

    /** @return Collection<string, DateColumn> */
    public function getDateColumns(): Collection
    {
        return $this->dateColumns;
    }

    /** @return Collection<string, Column> */
    private function getAllSearchableColumns(): Collection
    {
        return $this->columns->filter(fn (Column $column) => $column->isSearchable());
    }

    /*
    |--------------------------------------------------------------------------
    | Filtering
    |--------------------------------------------------------------------------
    */

    private function applyModelFiltering(DataTableParams $params): void
    {
        if ($params->trashed !== 'true') {
            return;
        }

        $modelFiltering = (new ModelFiltering())->onlyTrashed();
        $this->setBuilder($modelFiltering->apply($this->getBuilder()));
    }

    private function softRestoreRecord(DataTableParams $params): void
    {
        if (! $params->restoreId) {
            return;
        }

        $model = $this->getBuilder()
            ->getModel()
            ->newQueryWithoutScopes()
            ->withTrashed()
            ->findOrFail($params->restoreId);

        (new SoftRestorer($model))->restore();
    }

    private function applyGlobalFilter(string $searchText): self
    {
        $newBuilder = $this->getBuilder();
        $searchableColumns = $this->getAllSearchableColumns()->keys()->all();

        $newBuilder->where(function ($query) use ($searchText, $searchableColumns) {
            foreach ($searchableColumns as $columnKey) {
                $column = $this->getColumnByKey($columnKey);

                if (! $column) {
                    continue;
                }

                if ($column instanceof TranslatableColumn) {
                    $query->orWhereHas('translations', function ($q) use ($column, $searchText) {
                        $q->where('locale', $column->getLocale())
                            ->where(DataTableConfig::getTranslatableColumnName(), $column->getTranslationKey())
                            ->where('text', 'LIKE', '%'.$searchText.'%');
                    });

                    continue;
                }

                if ($column instanceof RelationColumn) {
                    $query->orWhereHas($column->relationString, function ($q) use ($column, $searchText) {
                        $q->where($column->relationColumn, 'LIKE', '%'.$searchText.'%');
                    });

                    continue;
                }

                try {
                    $this->applyColumnFilter($query, $columnKey, $searchText, useOrWhere: true);
                } catch (InvalidColumnNameException) {
                    // Skip unknown columns silently — they can't contribute to a global search.
                }
            }
        });

        $this->setBuilder($newBuilder);

        return $this;
    }

    private function applyColumnFilter(
        Builder $queryBuilder,
        string $columnKey,
        mixed $filterValue,
        bool $useOrWhere = false,
    ): self {
        $columnFilter = $this->columnFilter->apply($queryBuilder, $columnKey, $filterValue, $useOrWhere);
        $this->setBuilder($columnFilter->getBuilder());

        return $this;
    }

    private function applyCallbackBeforePaginate(?callable $callbackBeforePaginate): void
    {
        if (! $callbackBeforePaginate) {
            return;
        }

        $newBuilder = $this->getBuilder()->where(function ($query) use ($callbackBeforePaginate) {
            $callbackBeforePaginate($query);
        });

        $this->setBuilder($newBuilder);
    }

    /*
    |--------------------------------------------------------------------------
    | Ordering
    |--------------------------------------------------------------------------
    */

    public function applyOrderByColumns(): self
    {
        $ordering = $this->getOrdering();
        $builder = $this->getBuilder();

        $mainModel = $builder->getModel();
        $mainTable = $mainModel->getTable();

        $this->qualifyMainSelect($builder, $mainTable);

        if ($this->rawOrdering) {
            $builder->orderByRaw($this->rawOrdering->getString());
            $this->setBuilder($builder);

            return $this;
        }

        $orderingColumn = $this->getColumnByKey($ordering->columnName);

        if (! $orderingColumn) {
            $builder->orderBy("{$mainTable}.{$ordering->columnName}", $ordering->direction);
            $this->setBuilder($builder);

            return $this;
        }

        if ($orderingColumn instanceof TranslatableColumn) {
            $this->orderByTranslatable($builder, $orderingColumn, $mainTable, $ordering);
            $this->setBuilder($builder);

            return $this;
        }

        if (! $ordering->hasRelations) {
            $builder->orderBy("{$mainTable}.{$ordering->columnName}", $ordering->direction);
            $this->setBuilder($builder);

            return $this;
        }

        $this->applyRelationOrdering($builder, explode('.', (string) $ordering->relationsString), $mainTable, $ordering);
        $this->setBuilder($builder);

        return $this;
    }

    private function qualifyMainSelect(Builder $builder, string $mainTable): void
    {
        $existing = $builder->getQuery()->getColumns();

        if (empty($existing)) {
            $builder->select("{$mainTable}.*");

            return;
        }

        $builder->getQuery()->columns = [];

        foreach ($existing as $column) {
            $builder->addSelect($this->qualifyColumn($column, $mainTable));
        }
    }

    /**
     * Prefix a bare column name with the main table; leave already-qualified
     * names (`table.column`, `table.*`), raw expressions and `*` untouched.
     */
    private function qualifyColumn(mixed $column, string $mainTable): mixed
    {
        if (! is_string($column)) {
            return $column;
        }

        if ($column === '*' || str_contains($column, '.') || str_contains($column, '(')) {
            return $column;
        }

        return "{$mainTable}.{$column}";
    }

    private function orderByTranslatable(
        Builder $builder,
        TranslatableColumn $column,
        string $mainTable,
        Ordering $ordering,
    ): void {
        $modelClass = $builder->getModel()::class;
        $translationTable = DataTableConfig::getTranslatableTable();
        $translatableKeyColumn = DataTableConfig::getTranslatableColumnName();

        $builder->leftJoin("{$translationTable} as t", function ($join) use (
            $mainTable,
            $modelClass,
            $column,
            $translatableKeyColumn,
        ) {
            $join->on('t.translatable_id', '=', "{$mainTable}.id")
                ->where('t.translatable_type', '=', $modelClass)
                ->where('t.locale', '=', $column->getLocale())
                ->where("t.{$translatableKeyColumn}", '=', $column->getTranslationKey());
        });

        $builder->orderBy('t.text', $ordering->direction);
    }

    /**
     * @param string[] $relations
     */
    protected function applyRelationOrdering(Builder $builder, array $relations, string $prevTable, Ordering $ordering): void
    {
        $parentModel = $builder->getModel();

        foreach ($relations as $relation) {
            $relationInstance = $parentModel->{$relation}();
            $relatedModel = $relationInstance->getRelated();
            $relatedTable = $relatedModel->getTable();

            if ($relationInstance instanceof MorphOne) {
                $morphType = $relationInstance->getMorphType();
                $morphId = $relationInstance->getForeignKeyName();
                $builder->leftJoin("{$relatedTable} AS {$relation}", function ($join) use (
                    $prevTable,
                    $relation,
                    $morphType,
                    $morphId,
                    $parentModel,
                ) {
                    $join->on("{$relation}.{$morphId}", '=', "{$prevTable}.id")
                        ->where("{$relation}.{$morphType}", '=', $parentModel->getMorphClass());
                });
            } else {
                $foreignKey = $relationInstance->getForeignKeyName();
                $ownerKeyName = $relationInstance->getOwnerKeyName();
                $builder->leftJoin(
                    "{$relatedTable} AS {$relation}",
                    "{$relation}.{$ownerKeyName}",
                    '=',
                    "{$prevTable}.{$foreignKey}",
                );
            }

            $prevTable = $relation;
            $parentModel = $relatedModel;
        }

        $builder->orderBy("{$prevTable}.{$ordering->columnName}", $ordering->direction);
    }

    public function getOrdering(): Ordering
    {
        return $this->ordering;
    }

    public function setOrdering(Ordering $ordering): self
    {
        $this->ordering = $ordering;

        return $this;
    }

    public function getRawOrdering(): ?RawOrdering
    {
        return $this->rawOrdering;
    }

    public function setRawOrdering(?RawOrdering $rawOrdering): self
    {
        $this->rawOrdering = $rawOrdering;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Builder / data accessors
    |--------------------------------------------------------------------------
    */

    public function getBuilder(): Builder
    {
        return $this->builder;
    }

    private function setBuilder(Builder $builder): self
    {
        $this->builder = $builder;

        return $this;
    }

    public function getPaginator(): Paginator
    {
        return $this->paginator;
    }

    /** @return Collection<int, \Illuminate\Database\Eloquent\Model> */
    public function getData(): Collection
    {
        return $this->data;
    }
}
