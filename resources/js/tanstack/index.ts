/**
 * The TanStack Table side of `givanov95/laravel-data-table`: the composable that
 * drives a `DataTable::process()->toArray()` payload, and the translator the
 * published shadcn-vue components (`php artisan vendor:publish
 * --tag=data-table-shadcn`) read their texts through.
 *
 * Independent of the legacy `<DataTable>` widget of the package root.
 */
export { useLaravelDataTable } from "./useLaravelDataTable";
export type {
    LaravelDataTableColumn,
    LaravelDataTableMeta,
    LaravelDataTablePayload,
    LaravelDataTableState,
    UseLaravelDataTableOptions,
    UseLaravelDataTableReturn,
} from "./useLaravelDataTable";

export { DataTablePlugin } from "../install";
export type { DataTablePluginOptions } from "../install";
export { setConfig, t, fillPlaceholders } from "../config";
export type { Translator } from "../config";
