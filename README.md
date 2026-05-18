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

### Backend (Laravel / PHP 8.3+)

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
];
```

These keys map directly to the HTTP query parameters the frontend sends.

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

`setColumn` accepts both shorthand positional arguments **and** a fully
constructed `Column` object — pick whichever reads better:

```php
use Givanov95\DataTable\Columns\Column;

$table
    ->setColumn(new Column('id', '#', searchable: true, orderable: true))
    ->setColumn('action', __('Action'));
```

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
