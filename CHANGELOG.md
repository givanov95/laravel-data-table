# Changelog

## v3.3.0

### Added
- `data-table.max_per_page` (default `100`) — a hard upper bound for the page size
  read from the request. `DataTableConfig::getMaxPerPage()` exposes it. A value
  below 1 is treated as a misconfiguration and falls back to 50
  (`DataTableParams::FALLBACK_MAX_PER_PAGE`) instead of disabling the bound.
- `QueryBuilderTable::maxPerPage()` to override that bound for a single table; it
  throws `InvalidArgumentException` for a value below 1.
- `DataTableParams::clampPerPage()` — the shared rule both `DataTable` and
  `QueryBuilderTable` use to turn a requested page size into an effective one.
- `Support\DateSqlExpression` and `Exceptions\UnsupportedDriverException` — date
  filters now build their SQL for the connection's driver (see Fixed).
- `Support\LikeExpression` — the "contains" match the search and column filters use,
  with `%`, `_` and `\` in the value matched literally (see Fixed).

### Fixed
- The per-page select of `Pagination.vue` now shows the page size the server applied
  (`paginator.perPage`) on first render and after every reload, instead of a local
  value that only followed the user's choice. With `perPageOptions=[25, 50, 250]`,
  choosing 250 used to return 100 rows (cut to `max_per_page`) while the select kept
  showing 250; it now falls back to the "Default" entry. That entry's label carries
  the applied size when no option matches it (`Default (15)`, `Default (100)`), so
  it reads `Default (15)` where it used to read `Default`.
- `QueryBuilderTable` now reads the per-page, search, trashed and ordering request
  keys from `config/data-table.php` (`per_page`, `global_filter`, `trashed`,
  `ordering`) like `DataTable` does, instead of the hardcoded `perPage`,
  `filter.global`, `filter.trashed` and `ordering.key` / `ordering.direction`. An
  app that renamed a key used to get it honoured by `DataTable` and ignored here
  (e.g. `per_page => pageSize` always returned the default page size).
  The search is applied by the table itself rather than by a Spatie filter, so its
  key may be any request key, not only one under `filter`; the filters named by the
  configured keys are accepted so Spatie does not reject them, and the default
  `filter[global]`, `filter[timeZone]` and `filter[trashed]` are still ignored
  rather than rejected after a key is changed.
- Searching `QueryBuilderTable` for the text `true` or `false` is now a search:
  Spatie turned the value into a boolean, which was ignored.
- `%`, `_` and `\` typed into the search box are now matched literally. They used to
  reach `LIKE` unescaped and act as wildcards, so searching for `%` returned every
  row and `a_c` also found `abc`. This covers the global search and the column
  filters of `DataTable` (plain, `RelationColumn` and `TranslatableColumn` columns)
  and the global search of `QueryBuilderTable`. The pattern is a bound parameter and
  the `ESCAPE` clause follows the connection driver: MySQL/MariaDB and PostgreSQL
  already escape with a backslash, other drivers (SQLite, SQL Server) get an
  explicit `ESCAPE '\'`.
- A price column (`setPriceColumn`) filter is built from the digits of the raw input
  only. A `%` in it used to survive and act as a wildcard (`1%9` also found `100.9`).
- Date filters (`setDateColumn`) no longer hardcode MySQL's `` DATE_FORMAT(`t`.`c`) ``,
  which failed on PostgreSQL and SQLite. The expression now follows the connection
  driver — `DATE_FORMAT` (`mysql`, `mariadb`), `TO_CHAR` (`pgsql`), `strftime`
  (`sqlite`) — and identifiers are quoted by the query grammar, so a table prefix
  is honoured too. On any other driver (e.g. `sqlsrv`) a date filter throws
  `UnsupportedDriverException` instead of failing with a SQL error. The format is
  now a bound parameter rather than interpolated into the SQL.
- A date filter on a `RelationColumn` now queries the related model's real table
  instead of the snake-cased relation name (`author` instead of `authors`), which
  made the query fail on every driver.
- A time filter with seconds (`H:i:s`) is no longer translated to MySQL's
  `%H:%i:s`, where the trailing `s` was a literal and nothing could match.
- `perPage` from the request is no longer taken as-is. `?perPage=100000` used to be
  accepted and returned every row at once; it is now cut to `max_per_page`. A value
  that is not a number or is below 1 (empty, text, an array, zero, negative) falls
  back to `default_per_page`. `DataTable` and `QueryBuilderTable` now apply the
  same rule (the latter used to have a hardcoded, non-configurable cap of 100).
- An empty `perPage` (what the per-page select's "default" option sends) no longer
  reaches `paginate()` as `0`, which divided by zero in `DataTable`.
- `?perPage[]=x` is no longer read as `1`; arrays count as "not a number".

### Changed
- Requirements are now stated as they really were: PHP `^8.3` (was `^8.4`) and
  `illuminate/*` `^12.0|^13.0` (was `^10.0|^11.0|^12.0|^13.0`). Laravel 10 and 11
  could not be installed anyway, because `spatie/laravel-query-builder ^7.3` needs
  Laravel 12+. Nothing in the package needs PHP 8.4. CI now runs PHP 8.3, 8.4 and
  8.5 against Laravel 12 and 13.
- Requests asking for more than `max_per_page` rows per page now get `max_per_page`
  rows. Applications whose `perPageOptions` go above 100 must raise `max_per_page`.
- `?perPage=-1` (and `0`) no longer mean "all rows": Laravel's `limit()` ignored a
  negative value, so `-1` used to return everything. It now returns the default
  page size. Export all rows server-side instead.
- `default_per_page` (and `QueryBuilderTable::defaultPerPage()`) is capped by
  `max_per_page`: a default above the maximum now yields the maximum.
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
