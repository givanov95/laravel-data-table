# Changelog

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
