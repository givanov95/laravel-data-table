export { default as DataTable } from "./Table.vue";
export { default as DataTableGlobalSearch } from "./components/GlobalSearch.vue";
export { default as DataTablePagination } from "./components/Pagination.vue";
export { default as DataTableTrashedRecords } from "./components/TrashedRecords.vue";
export { default as DataTableAdvancedFilters } from "./components/AdvancedFilters.vue";
export { default as DataTableModal } from "./components/Modal.vue";
export { default as DataTableRadioButton } from "./components/RadioButton.vue";
export { default as DataTableRadioButtonToggle } from "./components/RadioButtonToggle.vue";

export { DataTablePlugin } from "./install";
export { setConfig, t, r, getReloadDebounceMs } from "./config";
export type { DataTableConfig, Translator, Router } from "./config";
export type { DataTablePluginOptions } from "./install";

export {
    debounce,
    relationWithColumn,
    fillValues,
    getFilterColumnValuesFromUrl,
    camelCaseToSnakeCase,
    replacePlaceholder,
    limitCharacters,
    buildUrlWithParam,
} from "./utils";

export type {
    DataTable as DataTableType,
    DataTableColumn,
    ColumnMap,
    ColumnRelation,
    Paginator,
    FilterValues,
    RadioToggleInput,
    RouteParams,
    ThisRoute,
} from "./types";
