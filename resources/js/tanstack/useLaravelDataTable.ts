import type { VisitOptions } from "@inertiajs/core";
import { router, usePage } from "@inertiajs/vue3";
import type { ColumnFiltersState, PaginationState, SortingState, Updater } from "@tanstack/vue-table";
import { computed, onScopeDispose, reactive, ref, toValue, watch } from "vue";
import type { MaybeRefOrGetter } from "vue";

import { getReloadDebounceMs } from "../config";
import { queryKeyOf as queryKey, replacePlaceholder } from "../utils";

/**
 * The payload of `DataTable::process()->toArray()`: Laravel paginator naming
 * under `meta`, the ordered `columns` and the applied `state`.
 */
export interface LaravelDataTableMeta {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number;
    to: number;
}

/** An option of a select filter. */
export interface LaravelDataTableFilterOption {
    value: string | number;
    label: string;
}

/** How a filterable column is filtered (v4.1+). */
export interface LaravelDataTableColumnFilter {
    /** `select`: one or several of `options`; `date`: a `from` / `to` range of days; `text`: contains. */
    type: "select" | "date" | "text";
    options?: LaravelDataTableFilterOption[];
}

/** The value of a column filter: a text, the values of a select, or a date range (ISO days). */
export type LaravelDataTableFilterValue = string | string[] | { from?: string; to?: string };

export interface LaravelDataTableColumn {
    key: string;
    label: string | null;
    sortable: boolean;
    searchable: boolean;
    /** v4.1+. */
    filterable?: boolean;
    filter?: LaravelDataTableColumnFilter | null;
}

export interface LaravelDataTableState {
    search?: string;
    sort?: { column: string; direction: string } | null;
    trashed?: boolean;
    /** The column filters as applied (v4.1+); PHP sends an empty one as `[]`. */
    filters?: Record<string, LaravelDataTableFilterValue> | [];
}

/** The request keys of the server's config/data-table.php (dotted: `filter.global`). */
export interface LaravelDataTableParams {
    per_page: string;
    global_filter: string;
    trashed: string;
    ordering: string;
    /** Sent by v4+; next to `global_filter` (`filter.timeZone`) otherwise. */
    time_zone?: string;
    /** The parameter of the column filters (v4.1+; `filter` otherwise). */
    column_filters?: string;
}

export interface LaravelDataTablePayload<TData = Record<string, unknown>> {
    /** One entry per row, as the server serialised it. */
    data: TData[];
    meta: LaravelDataTableMeta;
    /** In definition order. */
    columns: LaravelDataTableColumn[];
    state: LaravelDataTableState;
    /** Sent by v3.4+; the package defaults otherwise. */
    params?: LaravelDataTableParams;
}

/** A query value: nested objects become `key[sub]`; empty values remove the key. */
export type QueryValue = string | number | boolean | null | undefined | QueryValue[] | { [key: string]: QueryValue };

export interface UseLaravelDataTableOptions {
    /** The Inertia prop that holds the payload; reloaded on every change. */
    propName?: string;
    /** Other props the page wants back on every reload (e.g. its own filters). */
    alsoReload?: string[];
    /** Wait after the last keystroke before searching; default: the package's `reloadDebounceMs` (1200). */
    searchDebounceMs?: number;
    /** Offered by the per-page select (the applied size is always added). */
    perPageOptions?: number[];
    /**
     * Where `onRestore(id)` POSTs: a URL with an `?id` placeholder
     * (`/posts/?id/restore`) or a function of the id. The route calls
     * `DataTable::restore($id)`. Without it, `onRestore` does nothing.
     */
    restoreUrl?: string | ((id: number | string) => string);
}

const DEFAULT_PROP_NAME = "dataTable";
const DEFAULT_PER_PAGE_OPTIONS = [10, 20, 50, 100];
const DEFAULT_PARAMS: LaravelDataTableParams = {
    per_page: "perPage",
    global_filter: "filter.global",
    trashed: "filter.trashed",
    ordering: "ordering",
};

/** TanStack makes a column id from `accessorKey` with dots as underscores. */
const columnId = (key: string): string => key.split(".").join("_");

function appendQuery(query: URLSearchParams, key: string, value: QueryValue): void {
    if (value === undefined || value === null || value === "") {
        return;
    }

    if (typeof value === "object") {
        for (const [sub, nested] of Object.entries(value)) {
            appendQuery(query, `${key}[${sub}]`, nested);
        }

        return;
    }

    query.append(key, String(value));
}

/**
 * Drives a `givanov95/laravel-data-table` payload with TanStack Table in manual
 * mode: sorting, search, page, page size and the archive go to the server as
 * partial Inertia reloads (`only: [propName]`). The result is reactive and
 * meant to be spread onto `<ServerDataTable v-bind="table">`.
 *
 * What the table shows comes from the payload (the server's applied state), so
 * back/forward and shared links stay right; only what the user is typing is
 * local until it is sent.
 */
export function useLaravelDataTable<TData = Record<string, unknown>>(
    dataTable: MaybeRefOrGetter<LaravelDataTablePayload<TData>>,
    options: UseLaravelDataTableOptions = {},
) {
    const propName = options.propName ?? DEFAULT_PROP_NAME;
    const only = [propName, ...(options.alsoReload ?? [])];
    const searchDebounceMs = options.searchDebounceMs ?? getReloadDebounceMs();
    const perPageOptions = options.perPageOptions ?? DEFAULT_PER_PAGE_OPTIONS;

    const page = usePage();
    const payload = computed(() => toValue(dataTable));
    const meta = computed(() => payload.value.meta);
    const params = computed(() => ({ ...DEFAULT_PARAMS, ...payload.value.params }));
    const keys = computed(() => {
        const global = queryKey(params.value.global_filter);
        const parent = params.value.global_filter.split(".").slice(0, -1);

        return {
            perPage: queryKey(params.value.per_page),
            global,
            // Sent with the search term: dates typed in it are read in the user's zone.
            timeZone: queryKey(params.value.time_zone ?? [...parent, "timeZone"].join(".")),
            trashed: queryKey(params.value.trashed),
            ordering: queryKey(params.value.ordering),
        };
    });

    // `page.url` follows every visit, partial reloads included.
    const searchParams = computed(() => {
        const url = page.url ?? "";
        const queryIndex = url.indexOf("?");

        return new URLSearchParams(queryIndex === -1 ? "" : url.slice(queryIndex + 1));
    });

    // ----- State from the payload ----------------------------------------------

    // Without `ordering` in the request the server still reports its default
    // (`id DESC`); only a declared sortable column shows as sorted, so a table
    // ordered by an undeclared key reads as unsorted.
    const sortableKeys = computed(
        () => new Set(payload.value.columns.filter((c) => c.sortable).map((c) => c.key)),
    );

    const sorting = computed<SortingState>(() => {
        const sort = payload.value.state?.sort;

        if (!sort?.column || !sortableKeys.value.has(sort.column)) {
            return [];
        }

        return [{ id: columnId(sort.column), desc: (sort.direction ?? "desc").toLowerCase() !== "asc" }];
    });

    const pagination = computed<PaginationState>(() => ({
        pageIndex: meta.value.current_page - 1,
        pageSize: meta.value.per_page,
    }));

    // The search box is typed into, so it is local: seeded from the payload and
    // re-synced when the server reports another term (back/forward). While a
    // search is waiting or in flight the local value wins, so a response with
    // the older term does not swallow what was typed meanwhile.
    const globalFilter = ref(payload.value.state?.search ?? "");
    let searchPending = false;
    let syncingSearch = false;

    watch(
        () => payload.value.state?.search ?? "",
        (serverValue) => {
            if (searchPending || serverValue === globalFilter.value) {
                return;
            }

            syncingSearch = true;
            globalFilter.value = serverValue;
        },
    );

    const trashed = ref(payload.value.state?.trashed ?? false);

    watch(
        () => payload.value.state?.trashed ?? false,
        (serverValue) => {
            trashed.value = serverValue;
        },
    );

    // The payload has only the applied size; `null` ("the server's default") is
    // a choice of its own, so the requested size is read from the URL.
    const readPerPage = (): number | null => Number(searchParams.value.get(keys.value.perPage)) || null;

    const perPage = ref<number | null>(readPerPage());

    watch(searchParams, () => {
        perPage.value = readPerPage();
    });

    // ----- Visits ----------------------------------------------------------------

    /**
     * Visit the current page with these query changes, keeping state and
     * scroll and reloading only the table's props. The URL is built here, key
     * by key (`filter[global]`, not a whole `filter` object), so the result
     * does not depend on how an Inertia version merges data into the query.
     */
    function visit(changes: Record<string, QueryValue>, visitOptions: Partial<VisitOptions> = {}): void {
        const url = new URL(page.url ?? "", window.location.origin);
        const query = url.searchParams;

        for (const [key, value] of Object.entries(changes)) {
            for (const existing of [...query.keys()]) {
                if (existing === key || existing.startsWith(`${key}[`)) {
                    query.delete(existing);
                }
            }

            appendQuery(query, key, value);
        }

        const search = query.toString();

        router.get(`${url.pathname}${search ? `?${search}` : ""}`, {}, {
            only,
            preserveState: true,
            preserveScroll: true,
            ...visitOptions,
        });
    }

    function timeZone(): string {
        return Intl.DateTimeFormat().resolvedOptions().timeZone;
    }

    /** The search term and its time zone, or neither. */
    function searchChanges(term: string): Record<string, QueryValue> {
        const value = term.trim() !== "" ? term : undefined;

        return { [keys.value.global]: value, [keys.value.timeZone]: value === undefined ? undefined : timeZone() };
    }

    // ----- Sorting ---------------------------------------------------------------

    function onSortingChange(updaterOrValue: Updater<SortingState>): void {
        const next = typeof updaterOrValue === "function" ? updaterOrValue(sorting.value) : updaterOrValue;
        const first = next[0];

        // The server's key for the TanStack id (`author.name` for `author_name`).
        const key = first
            ? (payload.value.columns.find((c) => columnId(c.key) === first.id)?.key ?? first.id)
            : undefined;

        // Page 1: row N under the old order has nothing to do with row N under the new one.
        visit({
            [keys.value.ordering]: first ? { key, direction: first.desc ? "desc" : "asc" } : undefined,
            page: 1,
        });
    }

    // ----- Search ------------------------------------------------------------------

    let searchTimer: ReturnType<typeof setTimeout> | undefined;
    // Each search sent gets a number: only the latest one, with nothing
    // waiting to be sent, ends the "pending" state.
    let searchSeq = 0;

    function cancelSearch(): void {
        clearTimeout(searchTimer);
        searchTimer = undefined;
    }

    // Leaving the page must not send its search to the next one.
    onScopeDispose(cancelSearch);

    function sendSearch(changes: Record<string, QueryValue> = {}): void {
        cancelSearch();
        searchPending = true;
        const seq = ++searchSeq;

        visit({ ...changes, ...searchChanges(globalFilter.value), page: 1 }, {
            onFinish: () => {
                if (seq === searchSeq && searchTimer === undefined) {
                    searchPending = false;
                }
            },
        });
    }

    watch(globalFilter, () => {
        // The echo of a server-driven sync (or of clearSearch) is not a new search.
        if (syncingSearch) {
            syncingSearch = false;

            return;
        }

        searchPending = true;
        cancelSearch();
        searchTimer = setTimeout(() => sendSearch(), searchDebounceMs);
    });

    function onGlobalFilterChange(updaterOrValue: Updater<string>): void {
        globalFilter.value =
            typeof updaterOrValue === "function" ? updaterOrValue(globalFilter.value) : updaterOrValue;
    }

    /**
     * Empty the search box and reload once, at once, with any other query
     * changes of the page (e.g. its own filters), on page 1.
     */
    function clearSearch(data: Record<string, QueryValue> = {}): void {
        cancelSearch();

        if (globalFilter.value !== "") {
            syncingSearch = true;
            globalFilter.value = "";
        }

        sendSearch(data);
    }

    // ----- Page size -------------------------------------------------------------

    function onPerPageChange(value: number | null): void {
        perPage.value = value;
        // `null` drops the key: the server's default.
        // Page 1: Laravel does not clamp a page past the end, it returns no rows.
        visit({ [keys.value.perPage]: value, page: 1 });
    }

    // ----- Archive -----------------------------------------------------------------

    function onTrashedChange(value: boolean): void {
        trashed.value = value;
        // Page 1: the archive is usually much shorter than the live list.
        visit({ [keys.value.trashed]: value ? "true" : undefined, page: 1 });
    }

    /**
     * Restore an archived row with a POST to `restoreUrl` (a GET that changes
     * data could be triggered from another site). The route redirects back,
     * so the table and the page's flash messages come back fresh.
     */
    function onRestore(id: number | string): void {
        const restoreUrl = options.restoreUrl;

        if (restoreUrl === undefined) {
            console.warn("useLaravelDataTable: pass the `restoreUrl` option to restore rows.");

            return;
        }

        const url = typeof restoreUrl === "function" ? restoreUrl(id) : replacePlaceholder(restoreUrl, id);

        router.post(url, {}, { preserveState: true, preserveScroll: true });
    }

    // ----- Column filters ----------------------------------------------------------

    /** The columns the server filters by, with how (in definition order). */
    const filterColumns = computed(() =>
        payload.value.columns.filter(
            (c): c is LaravelDataTableColumn & { filter: LaravelDataTableColumnFilter } => !!c.filterable && !!c.filter,
        ),
    );

    const isEmptyFilter = (value: LaravelDataTableFilterValue | undefined): boolean => {
        if (value === undefined || value === "") return true;
        if (Array.isArray(value)) return value.length === 0;
        if (typeof value === "object") return !value.from && !value.to;

        return false;
    };

    const serverFilters = (): Record<string, LaravelDataTableFilterValue> => {
        const applied = payload.value.state?.filters;

        return applied && !Array.isArray(applied) ? { ...applied } : {};
    };

    // Typed into (a text filter waits like the search), so local: seeded from
    // the payload and re-synced from it while nothing is waiting or in flight,
    // so the answer to one change does not undo the next (two quick ticks).
    const filters = ref<Record<string, LaravelDataTableFilterValue>>(serverFilters());
    let filterTimer: ReturnType<typeof setTimeout> | undefined;
    let filterSeq = 0;
    let filtersInFlight = false;

    watch(
        () => JSON.stringify(payload.value.state?.filters ?? {}),
        () => {
            if (filterTimer === undefined && !filtersInFlight) {
                filters.value = serverFilters();
            }
        },
    );

    const cancelFilters = (): void => {
        clearTimeout(filterTimer);
        filterTimer = undefined;
    };

    onScopeDispose(cancelFilters);

    /** `filter[status]`: the key is taken as it is, dots and all (`filter[author.name]`). */
    const filterQueryKey = (key: string): string => `${queryKey(params.value.column_filters ?? "filter")}[${key}]`;

    /** Every filterable column's current value, page 1, one visit. */
    function sendFilters(): void {
        cancelFilters();

        const changes: Record<string, QueryValue> = { page: 1 };

        for (const column of filterColumns.value) {
            const value = filters.value[column.key];

            changes[filterQueryKey(column.key)] = isEmptyFilter(value) ? undefined : (value as QueryValue);
        }

        const seq = ++filterSeq;
        filtersInFlight = true;

        visit(changes, {
            onFinish: () => {
                // Only the latest visit, with nothing waiting, hands back to the server.
                if (seq === filterSeq && filterTimer === undefined) {
                    filtersInFlight = false;
                    filters.value = serverFilters();
                }
            },
        });
    }

    /**
     * Set one column's filter. A text waits like the search box; a select or
     * a date range is sent at once. An empty value removes the filter.
     */
    function setColumnFilter(key: string, value: LaravelDataTableFilterValue | undefined): void {
        const next = { ...filters.value };

        if (isEmptyFilter(value)) {
            delete next[key];
        } else {
            next[key] = value as LaravelDataTableFilterValue;
        }

        filters.value = next;

        const type = filterColumns.value.find((c) => c.key === key)?.filter.type;

        cancelFilters();

        if (type === "text") {
            filterTimer = setTimeout(sendFilters, searchDebounceMs);

            return;
        }

        sendFilters();
    }

    function clearColumnFilters(): void {
        filters.value = {};
        sendFilters();
    }

    const hasColumnFilters = computed(() => Object.values(filters.value).some((v) => !isEmptyFilter(v)));

    // TanStack's view of the same filters (ids with dots as underscores).
    const columnFilters = computed<ColumnFiltersState>(() =>
        Object.entries(filters.value)
            .filter(([, value]) => !isEmptyFilter(value))
            .map(([key, value]) => ({ id: columnId(key), value })),
    );

    function onColumnFiltersChange(updaterOrValue: Updater<ColumnFiltersState>): void {
        const next = typeof updaterOrValue === "function" ? updaterOrValue(columnFilters.value) : updaterOrValue;
        const byId = new Map(filterColumns.value.map((c) => [columnId(c.key), c.key]));
        const values: Record<string, LaravelDataTableFilterValue> = {};

        for (const { id, value } of next) {
            const key = byId.get(id);

            if (key !== undefined && !isEmptyFilter(value as LaravelDataTableFilterValue)) {
                values[key] = value as LaravelDataTableFilterValue;
            }
        }

        filters.value = values;
        sendFilters();
    }

    // ----- Pages -------------------------------------------------------------------

    function goToPage(pageNumber: number): void {
        visit({ page: pageNumber });
    }

    function onPaginationChange(updaterOrValue: Updater<PaginationState>): void {
        const next = typeof updaterOrValue === "function" ? updaterOrValue(pagination.value) : updaterOrValue;

        if (next.pageSize !== pagination.value.pageSize) {
            onPerPageChange(next.pageSize);

            return;
        }

        if (next.pageIndex !== pagination.value.pageIndex) {
            goToPage(next.pageIndex + 1);
        }
    }

    return reactive({
        propName,
        // For TanStack manual mode.
        data: computed(() => payload.value.data),
        rowCount: computed(() => meta.value.total),
        pageCount: computed(() => meta.value.last_page),
        meta,
        state: computed(() => ({
            pagination: pagination.value,
            sorting: sorting.value,
            globalFilter: globalFilter.value,
            columnFilters: columnFilters.value,
        })),
        onColumnFiltersChange,
        filterColumns,
        filters,
        setColumnFilter,
        clearColumnFilters,
        hasColumnFilters,
        onPaginationChange,
        onSortingChange,
        onGlobalFilterChange,
        clearSearch,
        perPage,
        perPageOptions,
        onPerPageChange,
        trashed,
        onTrashedChange,
        onRestore,
        goToPage,
    });
}

export type UseLaravelDataTableReturn = ReturnType<typeof useLaravelDataTable>;
