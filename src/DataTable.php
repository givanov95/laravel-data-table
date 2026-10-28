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
use Givanov95\DataTable\Exceptions\UnsupportedRelationException;
use Givanov95\DataTable\Support\Archive;
use Givanov95\DataTable\Support\LikeExpression;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Gate;

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

    /**
     * The request keys of config/data-table.php, for the bundled widget (it
     * gets the object's default serialization, not toArray()).
     *
     * @var array<string, string>
     */
    public array $requestKeys = [];

    /** Whether the rows are the archive (soft-deleted ones), as applied. */
    public bool $trashed = false;

    /** The model's key (`getKeyName()`): which field of a row identifies it, for the bundled widget. */
    public string $keyName = 'id';

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

    private DataTableParams $params;

    private bool $trashedAllowed = false;

    private ?string $trashedAbility = null;

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
        // The model's key, not `id`: a model keyed on `uid` has no `id` column.
        $this->ordering = Ordering::fromRequest($this->request, $builder->getModel()->getKeyName());
        $this->keyName = $builder->getModel()->getKeyName();

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
        $this->params = $params;
        $this->requestKeys = DataTableConfig::requestKeys();

        $this->initRelations();
        $this->applyModelFiltering($params);
        $this->applyOrderByColumns();
        $this->applyTieBreak();

        if ($params->globalFilter !== null && $params->globalFilter !== '') {
            $this->applyGlobalFilter($params->globalFilter);
        }

        $this->applyCallbackBeforePaginate($callbackBeforePaginate);

        /** @var LengthAwarePaginatorContract $lengthAwarePaginator */
        $lengthAwarePaginator = $this->getBuilder()->paginate($params->perPage);
        $this->paginator = new Paginator($lengthAwarePaginator->withQueryString());

        $relation = DataTableConfig::getTranslatableRelation();
        $keyColumn = DataTableConfig::getTranslatableColumnName();
        $localeColumn = DataTableConfig::getTranslatableLocaleColumn();
        $textColumn = DataTableConfig::getTranslatableTextColumn();

        $this->data = (new Collection($this->paginator->items()))->map(function ($item) use (
            $relation,
            $keyColumn,
            $localeColumn,
            $textColumn,
        ) {
            foreach ($this->translatableColumns as $translatableColumn) {
                $key = $translatableColumn->getTranslationKey();
                $locale = $translatableColumn->getLocale();

                // The column's locale: with several loaded, the first one found was shown.
                $translation = optional($item->{$relation})->first(
                    fn ($translation) => $translation->{$keyColumn} == $key && $translation->{$localeColumn} == $locale,
                );

                if ($translation) {
                    $item->{$key} = $translation->{$textColumn};
                }
            }

            return $item;
        });

        return $this;
    }

    /**
     * Let the request show the archive (`filter[trashed]=true`): the
     * soft-deleted rows instead of the live ones. Off by default. With an
     * ability, only users the gate allows it for the model class
     * (`Gate::allows($ability, Post::class)`) get the archive; for the rest,
     * and for a model without SoftDeletes, the parameter is ignored.
     */
    public function allowTrashed(bool $allow = true, ?string $ability = null): self
    {
        $this->trashedAllowed = $allow;
        $this->trashedAbility = $ability;

        return $this;
    }

    /**
     * Restore a soft-deleted record of this table, from the POST route of the
     * app: the record is looked up through the table's query (and `$scope`,
     * the same narrowing as the callback given to process()), so a record the
     * table does not list is a 404. Then the `restore` policy is checked.
     *
     * The archive has to be allowed (`allowTrashed()`) and the model has to use
     * SoftDeletes, or it is a 404; the ability of `allowTrashed()`, if any, has
     * to pass, or it is a 403.
     *
     * @param ?callable(Builder):void $scope
     */
    public function restore(int|string $id, ?callable $scope = null): RedirectResponse
    {
        $builder = clone $this->getBuilder();
        $model = $builder->getModel();

        if (! $this->trashedAllowed || ! Archive::supports($model)) {
            throw (new ModelNotFoundException())->setModel($model::class, [$id]);
        }

        if ($this->trashedAbility !== null) {
            Gate::authorize($this->trashedAbility, $model::class);
        }

        if ($scope !== null) {
            $builder->where(function ($query) use ($scope) {
                $scope($query);
            });
        }

        // `/posts/abc/restore` on an integer or uuid key is a 404, not a type error (22P02) from PostgreSQL.
        if (ColumnFilter::acceptance($builder->getQuery()->getConnection(), $model, $model->getKeyName(), $id) === false) {
            throw (new ModelNotFoundException())->setModel($model::class, [$id]);
        }

        /** @phpstan-ignore-next-line — onlyTrashed() is provided by the SoftDeletes scope. */
        $record = $builder->onlyTrashed()->findOrFail($id);

        return (new SoftRestorer($record))->restore();
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

        $this->eagerLoadTranslations($builder);

        $this->setBuilder($builder);
    }

    /**
     * The translations the columns show, loaded with the page instead of one
     * query per row (and no LazyLoadingViolationException under
     * preventLazyLoading()). A load the app set up itself (`with()`,
     * setRelation()) is left as it is.
     */
    private function eagerLoadTranslations(Builder $builder): void
    {
        $relation = DataTableConfig::getTranslatableRelation();

        if ($this->translatableColumns->isEmpty() || array_key_exists($relation, $builder->getEagerLoads())) {
            return;
        }

        $keys = $this->translatableColumns->map(fn (TranslatableColumn $c) => $c->getTranslationKey())->unique()->values()->all();
        $locales = $this->translatableColumns->map(fn (TranslatableColumn $c) => $c->getLocale())->unique()->values()->all();

        $builder->with([$relation => function ($query) use ($keys, $locales) {
            $query->whereIn(DataTableConfig::getTranslatableColumnName(), $keys)
                ->whereIn(DataTableConfig::getTranslatableLocaleColumn(), $locales);
        }]);
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

        // A RelationColumn passed here is checked as in setRelationColumn().
        if ($column instanceof RelationColumn && $column->isOrderable()) {
            $this->assertOrderableRelationPath($column);
        }

        $this->columns->put($column->getDatabaseColumnName(), $column);

        return $this;
    }

    /**
     * @throws UnsupportedRelationException when the column is orderable and a
     *     relation on its path cannot be ordered by with a join (see
     *     applyRelationOrdering()); a searchable-only column takes any relation.
     */
    public function setRelationColumn(RelationColumn $relationColumn): self
    {
        if ($relationColumn->isOrderable()) {
            $this->assertOrderableRelationPath($relationColumn);
        }

        $this->columns->put($relationColumn->getDatabaseColumnName(), $relationColumn);

        return $this;
    }

    /**
     * Checked when the column is declared, so a table that could not be sorted
     * by it fails on every load, in development, not with a 500 on the first
     * click on its header.
     */
    private function assertOrderableRelationPath(RelationColumn $column): void
    {
        $model = $this->getBuilder()->getModel();

        foreach (explode('.', $column->relationString) as $name) {
            if (! $model->isRelation($name)) {
                throw new UnsupportedRelationException(
                    sprintf('%s::%s() is not a relation, so the column "%s" cannot be ordered by.', $model::class, $name, $column->relationPath),
                    ['column' => $column->relationPath, 'relation' => $name],
                );
            }

            $relation = $model->{$name}();
            self::assertJoinableRelation($relation, $model, $name);
            $model = $relation->getRelated();
        }
    }

    /**
     * Ordering joins each relation of the path, so only relations with at most
     * one related row per row can take part: BelongsTo, HasOne and MorphOne.
     * A to-many relation (or a `…OfMany`) would repeat rows and break the pages.
     */
    private static function assertJoinableRelation(mixed $relation, Model $model, string $name): void
    {
        $joinable = $relation instanceof Relation
            && ! $relation instanceof MorphTo
            && ($relation instanceof BelongsTo
                || (($relation instanceof HasOne || $relation instanceof MorphOne) && ! $relation->isOneOfMany()));

        if (! $joinable) {
            throw new UnsupportedRelationException(
                sprintf(
                    'Cannot order by %s::%s() (%s): only BelongsTo, HasOne and MorphOne relations can be ordered by.',
                    $model::class,
                    $name,
                    is_object($relation) ? $relation::class : get_debug_type($relation),
                ),
                ['relation' => $name],
            );
        }
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
        $this->trashed = $params->trashed === 'true'
            && Archive::available($this->trashedAllowed, $this->getBuilder()->getModel(), $this->trashedAbility);

        if (! $this->trashed) {
            return;
        }

        $modelFiltering = (new ModelFiltering())->onlyTrashed();
        $this->setBuilder($modelFiltering->apply($this->getBuilder()));
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
                    $query->orWhereHas(DataTableConfig::getTranslatableRelation(), function ($q) use ($column, $searchText) {
                        $q->where(DataTableConfig::getTranslatableLocaleColumn(), $column->getLocale())
                            ->where(DataTableConfig::getTranslatableColumnName(), $column->getTranslationKey());

                        LikeExpression::apply($q, DataTableConfig::getTranslatableTextColumn(), $searchText);
                    });

                    continue;
                }

                if ($column instanceof RelationColumn) {
                    $query->orWhereHas($column->relationString, function ($q) use ($column, $searchText) {
                        LikeExpression::apply($q, $column->relationColumn, $searchText);
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
        $columnFilter = $this->columnFilter->apply(
            $queryBuilder,
            $columnKey,
            $filterValue,
            $useOrWhere,
            $this->params->timeZone,
        );
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
        $ordering = $this->resolveOrdering($this->getOrdering());
        $this->setOrdering($ordering);

        $builder = $this->getBuilder();

        $mainModel = $builder->getModel();
        $mainTable = $mainModel->getTable();

        $this->qualifyMainSelect($builder, $mainTable);

        if ($this->rawOrdering) {
            $builder->orderByRaw($this->rawOrdering->getString());
            $this->setBuilder($builder);

            return $this;
        }

        if ($ordering->fallback !== null) {
            $declaredColumn = $this->findOrderableColumn($ordering->key)
                ?? throw new InvalidColumnNameException("Invalid ordering column: {$ordering->key}");

            $this->applyDeclaredColumnOrdering($builder, $declaredColumn, $mainTable, $ordering);
            $this->setBuilder($builder);

            return $this;
        }

        $orderingColumn = $this->getColumnByKey($ordering->columnName);

        if (! $orderingColumn) {
            $this->orderByMainColumn($builder, $mainTable, $ordering->columnName, $ordering->direction);
            $this->setBuilder($builder);

            return $this;
        }

        if ($orderingColumn instanceof TranslatableColumn) {
            $this->orderByTranslatable($builder, $orderingColumn, $mainTable, $ordering);
            $this->setBuilder($builder);

            return $this;
        }

        if (! $ordering->hasRelations) {
            $this->orderByMainColumn($builder, $mainTable, $ordering->columnName, $ordering->direction);
            $this->setBuilder($builder);

            return $this;
        }

        $this->applyRelationOrdering($builder, explode('.', (string) $ordering->relationsString), $mainTable, $ordering);
        $this->setBuilder($builder);

        return $this;
    }

    /**
     * An ordering taken from the request is only honoured when it points at a
     * declared, orderable column; anything else falls back to the default
     * ordering. Orderings set by the developer (`setOrdering(new Ordering(...))`)
     * are trusted as-is.
     */
    private function resolveOrdering(Ordering $ordering): Ordering
    {
        if ($ordering->fallback === null || $this->findOrderableColumn($ordering->key) !== null) {
            return $ordering;
        }

        return $ordering->fallback;
    }

    /**
     * Find the orderable column a request key refers to: the key a column is
     * registered under, or the full `relation.column` path of a RelationColumn.
     */
    private function findOrderableColumn(string $key): ?Column
    {
        foreach ($this->columns as $columnKey => $column) {
            if (! $column->isOrderable()) {
                continue;
            }

            if ((string) $columnKey === $key || ($column instanceof RelationColumn && $column->relationPath === $key)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Order by a declared column. The SQL is built from the column definition
     * (and, for relations, the relation path declared on the RelationColumn),
     * never from the raw request key.
     */
    private function applyDeclaredColumnOrdering(
        Builder $builder,
        Column $column,
        string $mainTable,
        Ordering $ordering,
    ): void {
        if ($column instanceof TranslatableColumn) {
            $this->orderByTranslatable($builder, $column, $mainTable, $ordering);

            return;
        }

        if ($column instanceof RelationColumn) {
            $this->applyRelationOrdering(
                $builder,
                explode('.', $column->relationString),
                $mainTable,
                new Ordering($column->relationColumn, $ordering->direction),
            );

            return;
        }

        $this->orderByMainColumn($builder, $mainTable, $column->getDatabaseColumnName(), $ordering->direction);
    }

    /**
     * Order by a column of the main table, or by an alias of the select
     * (`withCount('entrances')` → `entrances_count`), which has no table.
     */
    private function orderByMainColumn(Builder $builder, string $mainTable, string $column, string $direction): void
    {
        $builder->orderBy(
            in_array($column, $this->selectAliases($builder), true) ? $column : "{$mainTable}.{$column}",
            $direction,
        );
    }

    /**
     * The aliases the select gives its expressions and columns (`… as name`).
     *
     * @return list<string>
     */
    private function selectAliases(Builder $builder): array
    {
        $query = $builder->getQuery();
        $aliases = [];

        foreach ($query->columns ?? [] as $column) {
            $sql = $column instanceof \Illuminate\Contracts\Database\Query\Expression
                ? (string) $column->getValue($query->getGrammar())
                : (is_string($column) ? $column : '');

            if (preg_match('/\s+as\s+[`"\[]?([\w]+)[`"\]]?\s*$/i', $sql, $match) === 1) {
                $aliases[] = $match[1];
            }
        }

        return $aliases;
    }

    /**
     * Rows that tie on the chosen order (many 0 counts, equal names) must not
     * move between pages, so the primary key decides last, in the same
     * direction. A raw ordering is the developer's whole order and is left alone.
     */
    private function applyTieBreak(): void
    {
        if ($this->rawOrdering !== null) {
            return;
        }

        $builder = $this->getBuilder();
        $model = $builder->getModel();
        $key = $model->getKeyName();
        $qualified = "{$model->getTable()}.{$key}";

        foreach ($builder->getQuery()->orders ?? [] as $order) {
            if (in_array($order['column'] ?? null, [$key, $qualified], true)) {
                return;
            }
        }

        $builder->orderBy($qualified, $this->getOrdering()->direction);
    }

    private function qualifyMainSelect(Builder $builder, string $mainTable): void
    {
        // The raw list, not getColumns(): that one turns every Expression
        // (withCount, selectSub) into a string, which addSelect() would then
        // quote as one column name, with its `?` bindings inside it.
        $existing = $builder->getQuery()->columns ?? [];

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
        // Keys, table and morph class from the relation itself: the model's
        // key need not be `id`, and under a morph map the type is the alias.
        $relation = $this->translationsRelation($builder->getModel());
        $alias = 'dt_translations';
        $keyColumn = DataTableConfig::getTranslatableColumnName();
        $localeColumn = DataTableConfig::getTranslatableLocaleColumn();

        $builder->leftJoin("{$relation->getRelated()->getTable()} as {$alias}", function ($join) use (
            $relation,
            $alias,
            $mainTable,
            $column,
            $keyColumn,
            $localeColumn,
        ) {
            $join->on("{$alias}.{$relation->getForeignKeyName()}", '=', "{$mainTable}.{$relation->getLocalKeyName()}")
                ->where("{$alias}.{$relation->getMorphType()}", '=', $relation->getMorphClass())
                ->where("{$alias}.{$localeColumn}", '=', $column->getLocale())
                ->where("{$alias}.{$keyColumn}", '=', $column->getTranslationKey());
        });

        $builder->orderBy("{$alias}.".DataTableConfig::getTranslatableTextColumn(), $ordering->direction);
    }

    private function translationsRelation(Model $model): MorphOneOrMany
    {
        $name = DataTableConfig::getTranslatableRelation();
        $relation = $model->isRelation($name) ? $model->{$name}() : null;

        if (! $relation instanceof MorphOneOrMany) {
            throw new UnsupportedRelationException(
                sprintf('%s::%s() must be a morphMany (or morphOne) relation to order by a TranslatableColumn.', $model::class, $name),
                ['relation' => $name],
            );
        }

        return $relation;
    }

    /**
     * @param string[] $relations
     */
    protected function applyRelationOrdering(Builder $builder, array $relations, string $prevTable, Ordering $ordering): void
    {
        $parentModel = $builder->getModel();

        foreach ($relations as $relation) {
            $relationInstance = $parentModel->{$relation}();
            self::assertJoinableRelation($relationInstance, $parentModel, $relation);

            $relatedModel = $relationInstance->getRelated();
            $relatedTable = $relatedModel->getTable();

            if ($relationInstance instanceof MorphOne) {
                $morphType = $relationInstance->getMorphType();
                $morphId = $relationInstance->getForeignKeyName();
                $localKey = $relationInstance->getLocalKeyName();
                $morphClass = $relationInstance->getMorphClass();
                $builder->leftJoin("{$relatedTable} AS {$relation}", function ($join) use (
                    $prevTable,
                    $relation,
                    $morphType,
                    $morphId,
                    $localKey,
                    $morphClass,
                ) {
                    $join->on("{$relation}.{$morphId}", '=', "{$prevTable}.{$localKey}")
                        ->where("{$relation}.{$morphType}", '=', $morphClass);
                });
            } elseif ($relationInstance instanceof HasOne) {
                $builder->leftJoin(
                    "{$relatedTable} AS {$relation}",
                    "{$relation}.{$relationInstance->getForeignKeyName()}",
                    '=',
                    "{$prevTable}.{$relationInstance->getLocalKeyName()}",
                );
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

    /*
    |--------------------------------------------------------------------------
    | Clean array contract
    |--------------------------------------------------------------------------
    */

    /**
     * Produce a clean, framework-standard payload for headless / TanStack-style
     * frontends. Unlike the object's default JSON serialization (which exposes
     * the internal `paginator` / `columns` shape consumed by the bundled Vue
     * widget), this returns a normalised structure:
     *
     *     [
     *       'data'    => [ ...row... ],
     *       'meta'    => [ current_page, per_page, total, last_page, from, to ],
     *       'columns' => [ [ key, label, sortable, searchable ], ... ],   // ordered
     *       'state'   => [ search, sort => [column, direction] | null, trashed ],
     *       'params'  => [ per_page, global_filter, trashed, ordering, time_zone ],   // request keys
     *     ]
     *
     * Call it explicitly (`->process()->toArray()`); the default serialization
     * is left untouched so existing `@givanov95/vue-data-table` screens keep
     * working. Must be called after {@see process()}.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $total = $this->paginator->itemsLength;
        $perPage = $this->paginator->perPage;
        $currentPage = $this->paginator->currentPage;
        // From the rows on this page: a page past the last one is empty (0–0),
        // not "61–10 of 10".
        $count = $this->data->count();
        $from = $count > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;

        return [
            'data' => $this->data->values()->all(),
            'meta' => [
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $this->paginator->lastPage,
                'from' => $from,
                'to' => $count > 0 ? $from + $count - 1 : 0,
            ],
            'columns' => $this->columns
                ->map(fn (Column $column, string $key): array => [
                    'key' => $key,
                    'label' => $column->getLabel(),
                    'sortable' => $column->isOrderable(),
                    'searchable' => $column->isSearchable(),
                ])
                ->values()
                ->all(),
            'state' => [
                'search' => $this->params->globalFilter ?? '',
                'sort' => $this->appliedSort(),
                'trashed' => $this->trashed,
            ],
            'params' => $this->requestKeys,
        ];
    }

    /**
     * The order the rows are in: the ordering, unless a raw ordering replaced it;
     * then what that raw ordering says it amounts to, if anything.
     *
     * @return array{column: string, direction: string}|null
     */
    private function appliedSort(): ?array
    {
        $ordering = $this->rawOrdering !== null ? $this->rawOrdering->reportedAs : $this->ordering;

        return $ordering === null ? null : [
            'column' => $ordering->key,
            'direction' => strtolower($ordering->direction),
        ];
    }
}
