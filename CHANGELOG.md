# Changelog

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
