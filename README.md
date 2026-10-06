# Laravel DataTable

A server-side **DataTable** builder for Laravel that ships with a matching
**Vue 3 + Inertia** frontend. Backend and frontend live in the same repository
and are published as two separate packages:

| Package                          | Installer                                      |
| -------------------------------- | ---------------------------------------------- |
| `givanov95/laravel-data-table`   | `composer require givanov95/laravel-data-table` |
| `@givanov95/vue-data-table`      | `npm install @givanov95/vue-data-table`         |

The two are designed to talk to each other through a single JSON payload, so
you write your table definition once in PHP and the Vue component renders it.

---

## Installation

### Backend (Laravel / PHP 8.4+)

```bash
composer require givanov95/laravel-data-table
```

The service provider is auto-discovered. Publish the config if you want to
customise the request parameter keys:

```bash
php artisan vendor:publish --tag=data-table-config
```

This creates `config/data-table.php`.

### Frontend (Vue 3 + Inertia)

```bash
npm install @givanov95/vue-data-table
```

Register the plugin once (e.g. in `app.ts` / `app.js`). Both options are
optional — without them the components fall back to identity translations and
a global `route()` helper if one exists.

```ts
import { createApp } from "vue";
import { DataTablePlugin } from "@givanov95/vue-data-table";

createApp(App)
    .use(DataTablePlugin, {
        // Hook up your i18n helper:
        translator: (key) => window.__(key),
        // Hook up ziggy or whatever produces URLs:
        route: window.route,
        // Optional: debounce delay for filter/search reloads (ms)
        reloadDebounceMs: 1200,
    })
    .mount("#app");
```

---

## Configuration

`config/data-table.php`:

```php
return [
    'translatable_table'  => 'translations',
    'translatable_column' => 'key',

    'global_filter' => 'filter.global',
    'per_page'      => 'perPage',
    'trashed'       => 'filter.trashed',
    'restore_id'    => 'restore_id',
    'ordering'      => 'ordering',

    'default_per_page' => 15,
    'max_per_page'     => 100,
];
```

These keys map directly to the HTTP query parameters the frontend sends.

The page size read from the request is bounded: anything above `max_per_page`
is cut to it (so `?perPage=100000` cannot load the whole table), and anything
that is not a number or is below 1 (empty, text, an array, zero, negative)
falls back to `default_per_page`. `default_per_page` is capped by
`max_per_page` too. This applies to both `DataTable` and `QueryBuilderTable`.

- `?perPage=-1` is **not** "all rows" — it gets the default page size. Export
  all rows server-side instead.
- A `max_per_page` below 1 is treated as a misconfiguration and falls back to
  50; it does not turn the bound off.
- If your frontend offers `perPageOptions` larger than 100, raise
  `max_per_page` accordingly.
- A `DataTableParams` you build and pass to `process()` yourself is used as-is.

---

## Backend usage

### Basic example

```php
use Givanov95\DataTable\DataTable;
use App\Models\User;

public function index()
{
    $table = (new DataTable(User::query()))
        ->setColumn('id', '#', searchable: true, orderable: true)
        ->setColumn('name', __('Name'), searchable: true, orderable: true)
        ->setColumn('email', __('Email'), searchable: true, orderable: true)
        ->setColumn('action', __('Action'))
        ->process();

    return Inertia::render('Users/Index', [
        'dataTable' => fn () => $table,
    ]);
}
```

Sorting requested by the client (`ordering[key]`, `ordering[direction]`) is only
applied to columns registered with `orderable: true` (for a `RelationColumn`, its
`relation.column` path). Any other key is ignored and the default ordering
(`id DESC`) is used. An ordering you set yourself with
`setOrdering(new Ordering(...))` is applied as-is.

`setColumn` accepts both shorthand positional arguments **and** a fully
constructed `Column` object — pick whichever reads better:

```php
use Givanov95\DataTable\Columns\Column;

$table
    ->setColumn(new Column('id', '#', searchable: true, orderable: true))
    ->setColumn('action', __('Action'));
```

### Lightweight spatie table (`QueryBuilderTable`)

For lean listings that don't need the column-type system, `QueryBuilderTable`
wraps [`spatie/laravel-query-builder`](https://github.com/spatie/laravel-query-builder)
and emits the **same** `{ data, meta, columns, state }` contract, so the same
frontend consumes it. It handles global search, sorting, the `trashed` toggle,
a `perPage` cap, and an optional `transform()` for whitelisting output (keep PII
out of the listing).

```php
use App\Models\User;
use Givanov95\DataTable\QueryBuilderTable;

$payload = QueryBuilderTable::for(User::query()->with('roles:id,name'), request())
    ->columns([
        ['key' => 'id', 'label' => __('ID'), 'sortable' => true],
        ['key' => 'first_name', 'label' => __('First name'), 'sortable' => true, 'searchable' => true],
        ['key' => 'email', 'label' => __('Email'), 'sortable' => true, 'searchable' => true],
    ])
    ->allowTrashed()
    ->defaultSort('id', 'desc')
    ->transform(fn (User $user) => [
        'id'         => $user->id,
        'first_name' => $user->first_name,
        'email'      => $user->email,
        'roles'      => $user->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->all(),
        'deleted_at' => $user->deleted_at?->toDateTimeString(),
    ])
    ->toArray();
```

The page size is bounded by `data-table.max_per_page` (see
[Configuration](#configuration)); call `->maxPerPage(50)` to override it for a
single table (it throws `InvalidArgumentException` below 1), and
`->defaultPerPage(25)` to change the fallback.

Use `DataTable` for column-rich tables; use `QueryBuilderTable` for lean listings
that want spatie's query power behind a small, reusable facade.

### Relation columns

```php
use Givanov95\DataTable\Columns\RelationColumn;

$table->setRelationColumn(
    new RelationColumn('user.name', __('User'), searchable: true, orderable: true)
);
```

### Translatable columns

Designed to plug into the common `translations` morphMany pattern
(`translatable_id`, `translatable_type`, `locale`, `key`, `text`):

```php
use Givanov95\DataTable\Columns\TranslatableColumn;

$table->setTranslatableColumn(
    new TranslatableColumn(
        locale: app()->getLocale(),
        translationKey: 'title',
        label: __('Title'),
        searchable: true,
        orderable: true,
    )
);
```

### Special column types

```php
// Enum filtering by case name
$table->setEnumColumn('status', App\Enums\OrderStatus::class);

// Numeric-only filtering (currency / numeric input)
$table->setPriceColumn('price');

// Date filtering with timezone-aware parsing
$table->setDateColumn('created_at', 'd.m.Y H:i:s');
```

#### Supported databases

Date filtering formats the column in SQL, so it needs a driver-specific
expression. It is supported on:

| Database             | Driver             | SQL used                    |
| -------------------- | ------------------ | --------------------------- |
| MySQL / MariaDB      | `mysql`, `mariadb` | `DATE_FORMAT(column, …)`    |
| PostgreSQL           | `pgsql`            | `TO_CHAR(column, …)`        |
| SQLite               | `sqlite`           | `strftime(…, column)`       |

On any other driver (e.g. SQL Server) filtering a date column throws
`Givanov95\DataTable\Exceptions\UnsupportedDriverException`. The other filters
(text, enum, price) are plain Eloquent `where` clauses and do not depend on it.

### Eager-loading relations

```php
$table->setRelation('translations');
$table->setRelation('user', ['id', 'name']);
```

### Custom query hooks

```php
$table->process(null, function ($query) {
    $query->where('owner_id', auth()->id());
});

// Or for free-form mutations:
$table->advancedSearch(fn ($q) => $q->whereJsonContains('tags', 'featured'));
```

---

## Frontend usage

```vue
<script setup lang="ts">
import { DataTable } from "@givanov95/vue-data-table";
import type { DataTableType } from "@givanov95/vue-data-table";

defineProps<{
    dataTable: DataTableType<{ id: number; name: string; email: string }>;
}>();
</script>

<template>
    <DataTable
        :data-table="dataTable"
        :global-search="true"
        :per-page-options="[15, 30, 50]"
    >
        <template #cell(action)="{ item }">
            <a :href="`/users/${item.id}/edit`">Edit</a>
        </template>
    </DataTable>
</template>
```

### Component props

| Prop                 | Type                          | Description                                           |
| -------------------- | ----------------------------- | ----------------------------------------------------- |
| `dataTable`          | `DataTableType<T>`            | The payload returned by `(new DataTable(...))->process()` |
| `propName`           | `string` (default `dataTable`) | Inertia prop key for partial reloads                  |
| `globalSearch`       | `boolean`                     | Show the global search input                          |
| `showTrashed`        | `boolean`                     | Show the "trashed" toggle                             |
| `advancedFilters`    | `boolean`                     | Reserve space for the advanced-filters slot           |
| `selectedRowIndexes` | `(string \| number)[]`        | Highlight matching rows                               |
| `selectedRowColumn`  | `string`                      | Column to match against `selectedRowIndexes`          |
| `rowClickLink`       | `string`                      | URL template (use `?id` placeholder) for row clicks   |
| `perPageOptions`     | `number[]`                    | Render a per-page dropdown                            |

### Slots

- `#additionalContent` — content inside the toolbar (e.g. "Create" buttons)
- `#advancedFilters` — content inside the advanced-filters toolbar slot
- `#cell(<column-key>)` — custom renderer for a column; receives `{ value, item }`
- `#cell(<relation.column>)` — custom renderer for relation columns

---

## Backend API reference

### `DataTable`

- `__construct(Builder $builder, ?Request $request = null)`
- `setColumn(string|Column $keyOrColumn, ?string $label = null, bool $searchable = false, bool $orderable = false, bool $exactMatch = false): self`
- `setRelationColumn(RelationColumn $column): self`
- `setTranslatableColumn(TranslatableColumn $column): self`
- `setEnumColumn(string $key, class-string<\BackedEnum> $enumClass): self`
- `setPriceColumn(string $key): self`
- `setDateColumn(string $key, string $format, string $dateDelimiter = '.', string $timeDelimiter = ':'): self`
- `setRelation(string $relationString, ?array $columnsToSelect = null): self`
- `setOrdering(Ordering $ordering): self`
- `setRawOrdering(?RawOrdering $rawOrdering): self`
- `process(?DataTableParams $params = null, ?callable $callbackBeforePaginate = null): self`
- `advancedSearch(callable $callback): self`
- `getData(): Collection`
- `getPaginator(): Paginator`
- `getBuilder(): Builder`
- `getColumnByKey(string $key): ?Column`

### Column classes

| Class                | Purpose                                              |
| -------------------- | ---------------------------------------------------- |
| `Column`             | Plain column (key, label, searchable, orderable…)    |
| `RelationColumn`     | Dot-notated relation column (`'user.name'`)          |
| `TranslatableColumn` | Pulls value from the configured translations table   |
| `EnumColumn`         | Internal — registered via `setEnumColumn`            |
| `PriceColumn`        | Internal — registered via `setPriceColumn`           |
| `DateColumn`         | Internal — registered via `setDateColumn`            |

---

## Repository layout

```
laravel-data-table/
├── composer.json              # PHP package manifest
├── package.json               # NPM package manifest
├── tsconfig.json
├── src/                       # PHP source
│   ├── DataTable.php
│   ├── DataTableConfig.php
│   ├── DataTableParams.php
│   ├── DataTableServiceProvider.php
│   ├── ColumnFilter.php
│   ├── Columns/
│   ├── Exceptions/
│   ├── Support/
│   └── config/data-table.php
└── resources/
    └── js/                    # Vue / TypeScript source
        ├── index.ts
        ├── install.ts
        ├── config.ts
        ├── Table.vue
        ├── components/
        ├── icons/
        ├── types/
        └── utils/
```

## License

MIT
