// Published from givanov95/laravel-data-table (php artisan vendor:publish --tag=data-table-shadcn).
// Change it in the package and re-publish with --force: local edits are overwritten.
import type { ColumnDef, RowData } from "@tanstack/vue-table"
import {
  columnFilteringFeature,
  columnVisibilityFeature,
  createFilteredRowModel,
  createPaginatedRowModel,
  createSortedRowModel,
  filterFn_includesString,
  globalFilteringFeature,
  rowPaginationFeature,
  rowSelectionFeature,
  rowSortingFeature,
  sortFn_alphanumeric,
  sortFn_basic,
  sortFn_datetime,
  sortFn_text,
  tableFeatures,
} from "@tanstack/vue-table"

/**
 * TanStack Table v9 is opt-in: only the features registered here exist on the
 * table instance. One set serves both `DataTable` (client-side row models) and
 * `ServerDataTable` (manual mode, where the row-model slots are skipped because
 * the server already sorted, filtered and paginated the rows).
 */
export const dataTableFeatures = tableFeatures({
  columnFilteringFeature,
  globalFilteringFeature,
  columnVisibilityFeature,
  rowSortingFeature,
  rowPaginationFeature,
  rowSelectionFeature,
  filteredRowModel: createFilteredRowModel(),
  sortedRowModel: createSortedRowModel(),
  paginatedRowModel: createPaginatedRowModel(),
  filterFns: { includesString: filterFn_includesString },
  sortFns: {
    alphanumeric: sortFn_alphanumeric,
    basic: sortFn_basic,
    datetime: sortFn_datetime,
    text: sortFn_text,
  },
})

export type DataTableFeatures = typeof dataTableFeatures

/** Column definition for any table built on {@link dataTableFeatures}. */
export type DataTableColumnDef<TData extends RowData, TValue = unknown> = ColumnDef<
  DataTableFeatures,
  TData,
  TValue
>
