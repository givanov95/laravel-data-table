<?php

declare(strict_types=1);

namespace Givanov95\DataTable;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Reusable server-side table adapter.
 *
 * Reads the query parameters emitted by the `useLaravelDataTable` Vue composable
 * (`filter[global]`, `ordering[key]` / `ordering[direction]`, `filter[trashed]`,
 * `perPage`, `page`) and returns the exact `{ data, meta, columns, state }`
 * payload it expects. Global search OR-s the searchable columns; sorting is
 * restricted to the sortable columns; soft-deleted rows are opt-in through the
 * `trashed` toggle.
 *
 * @template TModel of Model
 */
class QueryBuilderTable
{
    /** @var list<array{key: string, label: string|null, sortable: bool, searchable: bool}> */
    private array $columns = [];

    /** @var (Closure(TModel): array<string, mixed>)|null */
    private ?Closure $transform = null;

    private bool $allowTrashed = false;

    private string $defaultSortKey = 'id';

    private string $defaultSortDirection = 'desc';

    private int $defaultPerPage = 15;

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
     * @param  array<int, array{key: string, label?: string|null, sortable?: bool, searchable?: bool}>  $columns
     * @return $this
     */
    public function columns(array $columns): self
    {
        $this->columns = array_values(array_map(fn (array $column): array => [
            'key' => $column['key'],
            'label' => $column['label'] ?? null,
            'sortable' => $column['sortable'] ?? false,
            'searchable' => $column['searchable'] ?? false,
        ], $columns));

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

    /** @return $this */
    public function allowTrashed(bool $allow = true): self
    {
        $this->allowTrashed = $allow;

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
     */
    public function maxPerPage(int $max): self
    {
        $this->maxPerPage = $max;

        return $this;
    }

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{current_page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null},
     *     columns: list<array{key: string, label: string|null, sortable: bool, searchable: bool}>,
     *     state: array{search: string|null, sort: array{column: string, direction: string}|null, trashed: bool},
     * }
     */
    public function toArray(): array
    {
        $trashed = $this->allowTrashed
            && filter_var($this->request->input('filter.trashed'), FILTER_VALIDATE_BOOLEAN);

        $eloquent = $this->query;

        if ($trashed) {
            // @phpstan-ignore-next-line — withTrashed() is provided by the SoftDeletes scope.
            $eloquent->withTrashed();
        }

        $filters = [
            AllowedFilter::callback('global', function (Builder $query, mixed $value): void {
                $this->applyGlobalSearch($query, $value);
            }),
            // The composable sends filter[timeZone] with every search (and
            // filter[trashed] on the archived toggle). Register them so Spatie
            // does not reject them with InvalidFilterQuery; `trashed` is applied
            // manually via withTrashed() above, so these callbacks are no-ops.
            AllowedFilter::callback('timeZone', fn () => null),
        ];

        if ($this->allowTrashed) {
            $filters[] = AllowedFilter::callback('trashed', fn () => null);
        }

        $builder = QueryBuilder::for($eloquent, $this->request)
            ->allowedFilters(...$filters);

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
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'columns' => $this->columns,
            'state' => [
                'search' => $this->searchTerm(),
                'sort' => $sortKey !== null
                    ? ['column' => $sortKey, 'direction' => $sortDirection]
                    : null,
                'trashed' => $trashed,
            ],
        ];
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
                $sub->orWhere($column['key'], 'like', "%{$term}%");
            }
        });
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function resolveSort(): array
    {
        $key = $this->request->input('ordering.key');
        $direction = strtolower((string) $this->request->input('ordering.direction', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $sortable = array_column(
            array_values(array_filter($this->columns, static fn (array $c): bool => $c['sortable'])),
            'key',
        );

        if (is_string($key) && in_array($key, $sortable, true)) {
            return [$key, $direction];
        }

        return [$this->defaultSortKey, $this->defaultSortDirection];
    }

    private function resolvePerPage(): int
    {
        return DataTableParams::clampPerPage(
            (int) $this->request->input('perPage'),
            $this->defaultPerPage,
            $this->maxPerPage ?? DataTableConfig::getMaxPerPage(),
        );
    }

    private function searchTerm(): ?string
    {
        $value = $this->request->input('filter.global');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
