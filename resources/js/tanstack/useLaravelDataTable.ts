import type { FormDataConvertible } from "@inertiajs/core";
import { router, usePage } from "@inertiajs/vue3";
import type { PaginationState, SortingState, Updater } from "@tanstack/vue-table";
import { computed, reactive, ref, toValue, watch } from "vue";
import type { MaybeRefOrGetter } from "vue";

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

export interface LaravelDataTableColumn {
    key: string;
    label: string | null;
    sortable: boolean;
    searchable: boolean;
}

export interface LaravelDataTableState {
    search?: string;
    sort?: { column: string; direction: string } | null;
    trashed?: boolean;
}

export interface LaravelDataTablePayload<TData = Record<string, unknown>> {
    /** One entry per row, as the server serialised it. */
    data: TData[];
    meta: LaravelDataTableMeta;
    /** In definition order. */
    columns: LaravelDataTableColumn[];
    state: LaravelDataTableState;
}

export interface UseLaravelDataTableOptions {
    /** The Inertia prop that holds the payload; reloaded on every change. */
    propName?: string;
    /** Other props the page wants back on every reload (e.g. its own filters). */
    alsoReload?: string[];
    /** Wait after the last keystroke before searching. */
    searchDebounceMs?: number;
    /** Offered by the per-page select (the applied size is always added). */
    perPageOptions?: number[];
}

const DEFAULT_PROP_NAME = "dataTable";
const DEFAULT_SEARCH_DEBOUNCE_MS = 1200;
const DEFAULT_PER_PAGE_OPTIONS = [10, 20, 50, 100];

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
    const searchDebounceMs = options.searchDebounceMs ?? DEFAULT_SEARCH_DEBOUNCE_MS;
    const perPageOptions = options.perPageOptions ?? DEFAULT_PER_PAGE_OPTIONS;

    const page = usePage();
    const payload = computed(() => toValue(dataTable));
    const meta = computed(() => payload.value.meta);

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

        return [{ id: sort.column, desc: (sort.direction ?? "desc").toLowerCase() !== "asc" }];
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
    const readPerPage = (): number | null =>
        searchParams.value.has("perPage") ? Number(searchParams.value.get("perPage")) || null : null;

    const perPage = ref<number | null>(readPerPage());

    watch(searchParams, () => {
        perPage.value = readPerPage();
    });

    // ----- Reloads ---------------------------------------------------------------

    // `router.reload` visits the current URL and keeps state and scroll.
    function reload(data: Record<string, FormDataConvertible>, onFinish?: () => void): void {
        router.reload({ data, only, onFinish });
    }

    function timeZone(): string {
        return Intl.DateTimeFormat().resolvedOptions().timeZone;
    }

    /**
     * The whole `filter` object: Inertia merges reload data into the query
     * shallowly, so a `filter` sent replaces the old one and every key to keep
     * is rebuilt. `undefined` removes `filter[...]` from the URL.
     */
    function buildFilter(
        overrides: { global?: string | null; trashed?: boolean } = {},
    ): Record<string, FormDataConvertible> | undefined {
        const rawGlobal = overrides.global !== undefined ? overrides.global : globalFilter.value;
        const global = rawGlobal && rawGlobal.trim() !== "" ? rawGlobal : null;
        const isTrashed = overrides.trashed !== undefined ? overrides.trashed : trashed.value;
        const filter: Record<string, FormDataConvertible> = {};

        if (global !== null) {
            filter.global = global;
            filter.timeZone = timeZone();
        }

        if (isTrashed) {
            filter.trashed = true;
        }

        return Object.keys(filter).length > 0 ? filter : undefined;
    }

    // ----- Sorting ---------------------------------------------------------------

    function onSortingChange(updaterOrValue: Updater<SortingState>): void {
        const next = typeof updaterOrValue === "function" ? updaterOrValue(sorting.value) : updaterOrValue;
        const first = next[0];

        // Page 1: row N under the old order has nothing to do with row N under the new one.
        reload({
            ordering: first ? { key: first.id, direction: first.desc ? "desc" : "asc" } : undefined,
            page: 1,
        });
    }

    // ----- Search ------------------------------------------------------------------

    let searchTimer: ReturnType<typeof setTimeout> | undefined;

    function cancelSearch(): void {
        clearTimeout(searchTimer);
        searchTimer = undefined;
    }

    function sendSearch(): void {
        cancelSearch();
        reload({ filter: buildFilter(), page: 1 }, () => {
            searchPending = false;
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
        searchTimer = setTimeout(sendSearch, searchDebounceMs);
    });

    function onGlobalFilterChange(updaterOrValue: Updater<string>): void {
        globalFilter.value =
            typeof updaterOrValue === "function" ? updaterOrValue(globalFilter.value) : updaterOrValue;
    }

    /**
     * Empty the search box and reload once, at once, with any other query
     * changes of the page (e.g. its own filters), on page 1.
     */
    function clearSearch(data: Record<string, FormDataConvertible> = {}): void {
        cancelSearch();

        if (globalFilter.value !== "") {
            syncingSearch = true;
            globalFilter.value = "";
        }

        searchPending = true;
        reload({ ...data, filter: buildFilter({ global: null }), page: 1 }, () => {
            searchPending = false;
        });
    }

    // ----- Page size -------------------------------------------------------------

    function onPerPageChange(value: number | null): void {
        perPage.value = value;
        // `null` goes as `perPage=`, which falls back to the server's default.
        // Page 1: Laravel does not clamp a page past the end, it returns no rows.
        reload({ perPage: value, page: 1 });
    }

    // ----- Archive -----------------------------------------------------------------

    function onTrashedChange(value: boolean): void {
        trashed.value = value;
        // Page 1: the archive is usually much shorter than the live list.
        reload({ filter: buildFilter({ trashed: value }), page: 1 });
    }

    function onRestore(id: number): void {
        router.reload({
            data: { restore_id: id },
            only,
            onSuccess: () => {
                const url = new URL(window.location.href);
                url.searchParams.delete("restore_id");

                router.replace({ url: url.pathname + url.search, preserveState: true, preserveScroll: true });
            },
        });
    }

    // ----- Pages -------------------------------------------------------------------

    function goToPage(pageNumber: number): void {
        reload({ page: pageNumber });
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
        })),
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
