# Changelog

## v3.3.0

### Added
- `data-table.max_per_page` (default `100`) — a hard upper bound for the page size
  read from the request. `DataTableConfig::getMaxPerPage()` exposes it.
- `QueryBuilderTable::maxPerPage()` to override that bound for a single table.
- `DataTableParams::clampPerPage()` — the shared rule both `DataTable` and
  `QueryBuilderTable` use to turn a requested page size into an effective one.

### Fixed
- `perPage` from the request is no longer taken as-is. `?perPage=100000` used to be
  accepted and returned every row at once; it is now cut to `max_per_page`. A value
  below 1 (zero, negative, non-numeric) falls back to `default_per_page`.
  `DataTable` and `QueryBuilderTable` now apply the same rule (the latter used to
  have a hardcoded, non-configurable cap of 100).

### Changed
- Requests asking for more than `max_per_page` rows per page now get `max_per_page`
  rows. Applications whose `perPageOptions` go above 100 must raise `max_per_page`.
- A `DataTableParams` built by hand and passed to `process()` is still used as-is.

## v3.2.1

### Fixed
- `ordering[key]` from the request is now resolved against the declared columns:
  it is only applied to a column registered with `orderable: true` (for a
  `RelationColumn`, its `relation.column` path); any other key falls back to the
  default ordering. Relation joins are built from the path declared on the
  `RelationColumn`, not from the request. An ordering set with
  `setOrdering(new Ordering(...))` is still applied as-is.
- `toArray()['state']['sort']` reports the ordering that was actually applied.

### Changed
- Columns with `orderable: false` can no longer be sorted by crafting the
  `ordering[key]` request parameter.
- Requesting a `RelationColumn` by the key it is registered under (the bare
  column name, e.g. `name`) now sorts by the related column instead of a
  same-named column on the main table.

## v3.2.0

### Added
- `Givanov95\DataTable\QueryBuilderTable` — a lightweight, spatie-based table
  adapter for simple server-side listings. It wraps `spatie/laravel-query-builder`
  and emits the **same** `{ data, meta, columns, state }` contract as
  `DataTable::process()->toArray()`, so the existing TanStack / headless frontend
  (`useLaravelDataTable` + `ServerDataTable`) consumes it unchanged.

  Handles: global search (OR across the searchable columns), sorting restricted
  to the sortable columns, the soft-delete (`trashed`) toggle, a `perPage` cap,
  and an optional row `transform()` for whitelisting output columns (keep PII out
  of the payload). It also registers the composable's `filter[timeZone]` /
  `filter[trashed]` params, so a search or archived-toggle does not trip Spatie's
  `InvalidFilterQuery`.

  Use `DataTable` for column-rich tables (enum/date/translatable/price/relation
  columns); use `QueryBuilderTable` for lean listings that want spatie's query
  power behind a small, reusable facade.

### Requires
- `spatie/laravel-query-builder: ^7.3`
