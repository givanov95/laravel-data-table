<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Closure;
use Givanov95\DataTable\Support\Archive;
use Givanov95\DataTable\Support\FilterClauses;
use Givanov95\DataTable\Support\LikeExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Reusable server-side table adapter.
 *
 * Reads the query parameters emitted by the `useLaravelDataTable` Vue composable
 * (`filter[global]`, `ordering[key]` / `ordering[direction]`, `filter[trashed]`,
 * `perPage`, `page`; the names come from `config/data-table.php`) and returns the
 * exact `{ data, meta, columns, state }` payload it expects. Global search OR-s
 * the searchable columns; sorting is restricted to the sortable columns;
 * soft-deleted rows are opt-in through the `trashed` toggle.
 *
 * @template TModel of Model
 */
class QueryBuilderTable
{
    /** @var list<array{key: string, label: string|null, sortable: bool, searchable: bool, filterable: bool, filter: array{type: string, options?: list<array{value: string|int, label: string}>}|null}> */
    private array $columns = [];

    /** @var array<string, 'text'|'exact'|'select'|'date'> the filter of each filterable column */
    private array $filters = [];

    /** @var (Closure(TModel): array<string, mixed>)|null */
    private ?Closure $transform = null;

    private bool $allowTrashed = false;

    private ?string $trashedAbility = null;

    /** Null: the model's key (`getKeyName()`). */
    private ?string $defaultSortKey = null;

    private string $defaultSortDirection = 'desc';

    /** Null: `data-table.default_per_page`. */
    private ?int $defaultPerPage = null;

    private ?int $maxPerPage = null;

    /**
     * @param  Builder<TModel>  $query
     */
    private function __construct(
        private readonly Builder $query,
        private readonly Request $request,
    ) {}

    /**
     * @template TFor of Model
     *
     * @param  Builder<TFor>  $query
     * @return self<TFor>
     */
    public static function for(Builder $query, Request $request): self
    {
        return new self($query, $request);
    }

    /**
     * `filter` makes a column filterable on its own (`filter[status]=paid`):
     * `'text'` (contains), `'exact'` (one or several values), `'select'` (one
     * or several of `options`: `[['value' => …, 'label' => …]]`, a
     * `value => label` map or a closure), or `'date'` (a `from` / `to` range of
     * calendar days, in the server's zone).
     *
     * @param  array<int, array{key: string, label?: string|null, sortable?: bool, searchable?: bool, filter?: 'text'|'exact'|'select'|'date', options?: array<array-key, mixed>|Closure(): array<array-key, mixed>}>  $columns
     * @return $this
     *
     * @throws InvalidArgumentException for an unknown filter, or a filterable column named like another request key
     */
    public function columns(array $columns): self
    {
        $this->filters = [];

        $this->columns = array_values(array_map(function (array $column): array {
            $filter = $column['filter'] ?? null;

            if ($filter !== null) {
                if (! in_array($filter, ['text', 'exact', 'select', 'date'], true)) {
                    throw new InvalidArgumentException("Unknown filter '{$filter}' for the column '{$column['key']}': use text, exact, select or date.");
                }

                if (in_array($column['key'], $this->reservedFilterNames(), true)) {
                    throw new InvalidArgumentException("The column '{$column['key']}' cannot be filterable: it is another request key of the table.");
                }

                $this->filters[$column['key']] = $filter;
            }

            return [
                'key' => $column['key'],
                'label' => $column['label'] ?? null,
                'sortable' => $column['sortable'] ?? false,
                'searchable' => $column['searchable'] ?? false,
                'filterable' => $filter !== null,
                'filter' => match ($filter) {
                    null => null,
                    'select' => ['type' => 'select', 'options' => FilterClauses::options($column['options'] ?? [])],
                    'date' => ['type' => 'date'],
                    default => ['type' => 'text'],
                },
            ];
        }, $columns));

        return $this;
    }

    /**
     * @param  Closure(TModel): array<string, mixed>  $transform
     * @return $this
     */
    public function transform(Closure $transform): self
    {
        $this->transform = $transform;

        return $this;
    }

    /**
     * Let the `trashed` toggle add the soft-deleted rows. With an ability, only
     * users the gate allows it for the model class get them; for the rest, and
     * for a model without SoftDeletes, the toggle is accepted and ignored.
     *
     * @return $this
     */
    public function allowTrashed(bool $allow = true, ?string $ability = null): self
    {
        $this->allowTrashed = $allow;
        $this->trashedAbility = $ability;

        return $this;
    }

    /** @return $this */
    public function defaultSort(string $key, string $direction = 'desc'): self
    {
        $this->defaultSortKey = $key;
        $this->defaultSortDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $this;
    }

    /** @return $this */
    public function defaultPerPage(int $perPage): self
    {
        $this->defaultPerPage = $perPage;

        return $this;
    }

    /**
     * Override `data-table.max_per_page` for this table.
     *
     * @return $this
     *
     * @throws InvalidArgumentException when $max is below 1
     */
    public function maxPerPage(int $max): self
    {
        if ($max < 1) {
            throw new InvalidArgumentException("maxPerPage() must be at least 1, {$max} given.");
        }

        $this->maxPerPage = $max;

        return $this;
    }

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null},
     *     columns: list<array{key: string, label: string|null, sortable: bool, searchable: bool}>,
     *     state: array{search: string|null, sort: array{column: string, direction: string}|null, trashed: bool, trashed_allowed: bool, filters: array<string, mixed>},
     * }
     */
    public function toArray(): array
    {
        $trashedAllowed = Archive::available($this->allowTrashed, $this->query->getModel(), $this->trashedAbility);
        $trashed = $trashedAllowed
            && filter_var($this->request->input(DataTableConfig::getTrashedKey()), FILTER_VALIDATE_BOOLEAN);

        // Spatie sorts and paginates the very builder it is given, so work on a copy: the
        // caller's builder stays as it was, and toArray() can be called again.
        $eloquent = clone $this->query;

        if ($trashed) {
            // @phpstan-ignore-next-line — withTrashed() is provided by the SoftDeletes scope.
            $eloquent->withTrashed();
        }

        // Applied here rather than in a Spatie filter callback, so the key it is read
        // from can be any request key (`data-table.global_filter`), not only `filter[...]`.
        $search = $this->searchTerm();

        if ($search !== null) {
            $this->applyGlobalSearch($eloquent, $search);
        }

        // Here too, not by Spatie: it would split `Smith, John` into two values.
        $filters = $this->applyColumnFilters($eloquent);

        $builder = QueryBuilder::for($eloquent, $this->request)
            ->allowedFilters(...$this->tolerableFilters());

        [$sortKey, $sortDirection] = $this->resolveSort();

        if ($sortKey !== null) {
            $builder->orderBy($sortKey, $sortDirection === 'asc' ? 'asc' : 'desc');
        }

        $paginator = $builder->paginate($this->resolvePerPage())->withQueryString();

        $data = [];

        foreach ($paginator->items() as $model) {
            $data[] = $this->transform !== null
                ? ($this->transform)($model)
                : $model->toArray();
        }

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                // 0 on an empty page, like DataTable (the paginator gives null).
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ],
            'columns' => $this->columns,
            'state' => [
                'search' => $this->searchTerm(),
                'sort' => $sortKey !== null
                    ? ['column' => $sortKey, 'direction' => $sortDirection]
                    : null,
                'trashed' => $trashed,
                'trashed_allowed' => $trashedAllowed,
                'filters' => $filters,
            ],
            'params' => DataTableConfig::requestKeys(),
        ];
    }

    /**
     * The names a filterable column cannot have: those of DataTable, plus
     * `global`, `timeZone` and `trashed` while the column filters are Spatie's
     * parameter, where tolerableFilters() registers those names already.
     *
     * @return list<string>
     */
    private function reservedFilterNames(): array
    {
        $spatie = DataTableConfig::getColumnFiltersKey() === (string) Config::get('query-builder.parameters.filter', 'filter');

        return array_values(array_unique([
            ...DataTableConfig::reservedFilterNames(),
            ...($spatie ? ['global', 'timeZone', 'trashed'] : []),
        ]));
    }

    /**
     * Each filter of a filterable column, as its own `where`; the applied
     * values, as `state.filters`.
     *
     * @param  Builder<TModel>  $query
     * @return array<string, string|list<string>|array{from?: string, to?: string}>
     */
    private function applyColumnFilters(Builder $query): array
    {
        $requested = FilterClauses::normalize($this->request->input(DataTableConfig::getColumnFiltersKey()));
        $connection = $query->getQuery()->getConnection();
        $model = $query->getModel();
        $applied = [];

        foreach ($this->columns as $column) {
            $key = $column['key'];
            $kind = $this->filters[$key] ?? null;

            if ($kind === null || ! array_key_exists($key, $requested)) {
                continue;
            }

            $value = $requested[$key];

            if ($kind === 'text') {
                $text = is_string($value) ? trim($value) : '';

                if ($text !== '') {
                    $applied[$key] = $text;
                    LikeExpression::apply($query, $key, $text);
                }

                continue;
            }

            if ($kind === 'date') {
                $range = FilterClauses::dateRange($value);

                if ($range !== []) {
                    $applied[$key] = $range;
                    $query->where(fn (Builder $q) => FilterClauses::whereDateRange($q, $key, $range, false));
                }

                continue;
            }

            $values = FilterClauses::values($value);

            if ($values === []) {
                continue;
            }

            $values = $kind === 'select'
                ? FilterClauses::offered($values, $column['filter']['options'] ?? [])
                // Only values the column can hold reach the database (PostgreSQL rejects `id = 'abc'`);
                // a type not known here (a native enum) takes them as they are.
                : array_values(array_filter($values, fn (string $v): bool => ColumnFilter::acceptance($connection, $model, $key, $v) !== false));

            if ($values === []) {
                // Nothing the column can hold: no row has it. None offered (a stale
                // link) is no filter, rather than an empty table that says nothing.
                if ($kind === 'exact') {
                    $query->whereRaw('1 = 0');
                }

                continue;
            }

            $applied[$key] = array_map('strval', $values);
            FilterClauses::whereIn($query, $key, $values);
        }

        return $applied;
    }

    /**
     * OR-s the search term across every searchable column.
     *
     * @param  Builder<TModel>  $query
     */
    private function applyGlobalSearch(Builder $query, mixed $value): void
    {
        $term = is_string($value) ? trim($value) : '';

        if ($term === '') {
            return;
        }

        $searchable = array_values(array_filter(
            $this->columns,
            static fn (array $column): bool => $column['searchable'],
        ));

        if ($searchable === []) {
            return;
        }

        $query->where(function (Builder $sub) use ($searchable, $term): void {
            foreach ($searchable as $column) {
                LikeExpression::apply($sub, $column['key'], $term, 'or');
            }
        });
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function resolveSort(): array
    {
        $ordering = DataTableConfig::getOrderingKey();

        $key = $this->request->input("{$ordering}.key");
        $direction = strtolower((string) $this->request->input("{$ordering}.direction", 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $sortable = array_column(
            array_values(array_filter($this->columns, static fn (array $c): bool => $c['sortable'])),
            'key',
        );

        if (is_string($key) && in_array($key, $sortable, true)) {
            return [$key, $direction];
        }

        return [$this->defaultSortKey ?? $this->query->getModel()->getKeyName(), $this->defaultSortDirection];
    }

    private function resolvePerPage(): int
    {
        return DataTableParams::clampPerPage(
            $this->request->input(DataTableConfig::getPerPageKey()),
            $this->defaultPerPage ?? DataTableConfig::getDefaultPerPage(),
            $this->maxPerPage ?? DataTableConfig::getMaxPerPage(),
        );
    }

    private function searchTerm(): ?string
    {
        $value = $this->request->input(DataTableConfig::getGlobalFilterKey());

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Spatie rejects a `filter[...]` it was not told about with InvalidFilterQuery,
     * but the search and the trashed toggle are applied here, not by Spatie, so
     * every filter the frontend sends is registered as a no-op:
     *
     * - `timeZone`, which the composable sends with every search;
     * - `global` and (when allowed) `trashed`, the default names, so a frontend that
     *   still sends them after the keys were changed is ignored instead of getting a 400;
     * - the names of the configured keys, when they live under Spatie's filter parameter.
     *
     * @return list<AllowedFilter>
     */
    private function tolerableFilters(): array
    {
        $names = [
            'global',
            'timeZone',
            $this->spatieFilterName(DataTableConfig::getGlobalFilterKey()),
            $this->spatieFilterName(DataTableConfig::getTimeZoneKey()),
        ];

        if ($this->allowTrashed) {
            $names[] = 'trashed';
            $names[] = $this->spatieFilterName(DataTableConfig::getTrashedKey());
        }

        // The column filters, applied by applyColumnFilters(): each by its own
        // name when they are Spatie's parameter, or their parameter under it.
        $columnFilters = DataTableConfig::getColumnFiltersKey();

        if ($columnFilters === (string) Config::get('query-builder.parameters.filter', 'filter')) {
            array_push($names, ...array_map('strval', array_keys($this->filters)));
        } else {
            $names[] = $this->spatieFilterName($columnFilters);
        }

        return array_map(
            static fn (string $name): AllowedFilter => AllowedFilter::callback($name, static fn () => null),
            array_values(array_unique(array_filter($names))),
        );
    }

    /**
     * The Spatie filter a request key refers to (`filter.search` is the filter
     * `search`), or null when the key is not under Spatie's filter parameter.
     */
    private function spatieFilterName(string $requestKey): ?string
    {
        $prefix = (string) Config::get('query-builder.parameters.filter', 'filter').'.';

        if (! str_starts_with($requestKey, $prefix)) {
            return null;
        }

        return explode('.', substr($requestKey, strlen($prefix)))[0];
    }
}
