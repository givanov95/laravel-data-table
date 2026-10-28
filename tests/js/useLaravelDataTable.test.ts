import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, nextTick, ref } from "vue";
import { mount } from "@vue/test-utils";

import { resetConfig, setConfig } from "../../resources/js/config";
import { useLaravelDataTable } from "../../resources/js/tanstack";
import type { LaravelDataTablePayload, UseLaravelDataTableOptions } from "../../resources/js/tanstack";

const { get, post, replace, page } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    replace: vi.fn(),
    page: { url: "/posts" } as { url: string },
}));

vi.mock("@inertiajs/vue3", async () => {
    const { reactive } = await import("vue");
    const reactivePage = reactive(page);

    return {
        router: { get, post, replace },
        usePage: () => reactivePage,
    };
});

type Row = { id: number };

const payload = (overrides: Partial<LaravelDataTablePayload<Row>> = {}): LaravelDataTablePayload<Row> => ({
    data: [{ id: 1 }],
    meta: { current_page: 3, per_page: 20, total: 45, last_page: 3, from: 41, to: 45 },
    columns: [
        { key: "id", label: "#", sortable: false, searchable: true },
        { key: "created_at", label: "When", sortable: true, searchable: false },
        { key: "author.name", label: "Author", sortable: true, searchable: false },
    ],
    state: { search: "", sort: { column: "id", direction: "desc" }, trashed: false },
    ...overrides,
});

/** Runs the composable inside a component, like a page does. */
function setup(initial = payload(), options: UseLaravelDataTableOptions = {}) {
    const data = ref(initial);
    let table!: ReturnType<typeof useLaravelDataTable<Row>>;

    const wrapper = mount(
        defineComponent({
            setup() {
                table = useLaravelDataTable<Row>(() => data.value, options);

                return () => h("div");
            },
        }),
    );

    return { table, data, wrapper };
}

/** The query of the last visit, decoded (`filter[global]=a`). */
const lastQuery = (): Record<string, string> => {
    const url = new URL(String(get.mock.calls.at(-1)?.[0]), "http://localhost");

    return Object.fromEntries(url.searchParams.entries());
};
const lastOptions = () => get.mock.calls.at(-1)?.[2];

describe("useLaravelDataTable", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        get.mockReset();
        post.mockReset();
        replace.mockReset();
        resetConfig();
        page.url = "/posts";
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("shows only a declared sortable column as sorted, with TanStack's id", () => {
        // The server's default `id DESC` is not a sortable column: unsorted.
        expect(setup().table.state.sorting).toEqual([]);
        expect(setup(payload({ state: { sort: null } })).table.state.sorting).toEqual([]);

        const { table } = setup(payload({ state: { sort: { column: "author.name", direction: "asc" } } }));
        expect(table.state.sorting).toEqual([{ id: "author_name", desc: false }]);
    });

    it("sends the server's key for a nested column and goes back to page 1", () => {
        page.url = "/posts?page=3&ordering%5Bkey%5D=id";
        const { table } = setup();

        table.onSortingChange([{ id: "author_name", desc: true }]);
        expect(lastQuery()).toEqual({ page: "1", "ordering[key]": "author.name", "ordering[direction]": "desc" });
        expect(lastOptions()).toMatchObject({ only: ["dataTable"], preserveState: true, preserveScroll: true });

        table.onSortingChange([]);
        expect(lastQuery()).toEqual({ page: "1" });
    });

    it("goes back to page 1 on a new page size or archive, keeping the rest of the query", () => {
        page.url = "/posts?page=3&filter%5Bglobal%5D=al&filter%5BtimeZone%5D=UTC&who=team";
        const { table } = setup();

        table.onPerPageChange(50);
        expect(lastQuery()).toMatchObject({ page: "1", perPage: "50", "filter[global]": "al", who: "team" });

        table.onTrashedChange(true);
        expect(lastQuery()).toMatchObject({ page: "1", "filter[trashed]": "true", "filter[global]": "al" });

        page.url = "/posts?page=2&filter%5Btrashed%5D=true&filter%5Bglobal%5D=al&filter%5BtimeZone%5D=UTC";
        table.onTrashedChange(false);
        expect(lastQuery()).toEqual({ page: "1", "filter[global]": "al", "filter[timeZone]": "UTC" });
    });

    it("searches once after the debounce, with the time zone, on page 1", async () => {
        const { table } = setup(payload(), { searchDebounceMs: 300 });

        table.onGlobalFilterChange("al");
        await nextTick();
        table.onGlobalFilterChange("alice");
        await nextTick();
        vi.advanceTimersByTime(299);
        expect(get).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(get).toHaveBeenCalledTimes(1);
        expect(lastQuery()).toEqual({ "filter[global]": "alice", "filter[timeZone]": expect.any(String), page: "1" });
    });

    it("takes the package's reloadDebounceMs by default", async () => {
        setConfig({ reloadDebounceMs: 400 });
        const { table } = setup();

        table.onGlobalFilterChange("al");
        await nextTick();
        vi.advanceTimersByTime(399);
        expect(get).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(get).toHaveBeenCalledTimes(1);
    });

    it("clears the search in one visit with the page's own changes, cancelling a pending one", async () => {
        page.url = "/posts?filter%5Bglobal%5D=alice&filter%5BtimeZone%5D=UTC&filter%5Btrashed%5D=true&who=team&page=2";
        const { table } = setup(payload({ state: { search: "alice", trashed: true } }), { searchDebounceMs: 300 });

        table.onGlobalFilterChange("alic");
        await nextTick();
        table.clearSearch({ who: undefined });
        await nextTick();
        vi.advanceTimersByTime(1000);

        expect(get).toHaveBeenCalledTimes(1);
        // The search goes, the archive stays.
        expect(lastQuery()).toEqual({ "filter[trashed]": "true", page: "1" });
        expect(table.state.globalFilter).toBe("");
    });

    it("does not send a waiting search after the page is left", async () => {
        const { table, wrapper } = setup(payload(), { searchDebounceMs: 300 });

        table.onGlobalFilterChange("al");
        await nextTick();
        wrapper.unmount();
        vi.advanceTimersByTime(1000);

        expect(get).not.toHaveBeenCalled();
    });

    it("keeps what is typed while an older search finishes", async () => {
        const { table, data } = setup(payload(), { searchDebounceMs: 300 });

        table.onGlobalFilterChange("abc");
        await nextTick();
        vi.advanceTimersByTime(300);
        const finishAbc = lastOptions().onFinish as () => void;

        table.onGlobalFilterChange("abcd");
        await nextTick();
        // The "abc" response arrives while "abcd" waits for its debounce.
        data.value = payload({ state: { search: "abc" } });
        finishAbc();
        await nextTick();

        expect(table.state.globalFilter).toBe("abcd");
        vi.advanceTimersByTime(300);
        expect(lastQuery()["filter[global]"]).toBe("abcd");
    });

    it("follows the server's search term (back/forward) without searching again", async () => {
        const { table, data } = setup(payload({ state: { search: "alice" } }));

        data.value = payload({ state: { search: "bob" } });
        await nextTick();
        await nextTick();
        vi.advanceTimersByTime(5000);

        expect(table.state.globalFilter).toBe("bob");
        expect(get).not.toHaveBeenCalled();
    });

    it("sends the request keys the server names in params", () => {
        page.url = "/posts?per_page=50";
        const params = { per_page: "per_page", global_filter: "q.term", trashed: "archived", ordering: "sort" };
        const { table } = setup(payload({ params }));

        expect(table.perPage).toBe(50);

        table.onSortingChange([{ id: "created_at", desc: false }]);
        expect(lastQuery()).toMatchObject({ "sort[key]": "created_at", "sort[direction]": "asc" });

        table.onTrashedChange(true);
        expect(lastQuery()).toMatchObject({ archived: "true" });
    });

    it("sends the time zone under the key the server names", async () => {
        const params = { per_page: "perPage", global_filter: "q", trashed: "archived", ordering: "sort", time_zone: "tz" };
        const { table } = setup(payload({ params }), { searchDebounceMs: 300 });

        table.onGlobalFilterChange("28.10.2026");
        await nextTick();
        vi.advanceTimersByTime(300);

        expect(lastQuery()).toMatchObject({ q: "28.10.2026", tz: Intl.DateTimeFormat().resolvedOptions().timeZone });
    });

    it("puts the time zone next to the search key when the server does not name it", async () => {
        const params = { per_page: "perPage", global_filter: "search.term", trashed: "archived", ordering: "sort" };
        const { table } = setup(payload({ params }), { searchDebounceMs: 300 });

        table.onGlobalFilterChange("al");
        await nextTick();
        vi.advanceTimersByTime(300);

        expect(Object.keys(lastQuery())).toEqual(expect.arrayContaining(["search[term]", "search[timeZone]"]));
    });

    describe("column filters", () => {
        const filtered = (state: Record<string, unknown> = {}) =>
            payload({
                columns: [
                    { key: "status", label: "Status", sortable: false, searchable: false, filterable: true, filter: { type: "select", options: [{ value: "paid", label: "Paid" }, { value: "open", label: "Open" }] } },
                    { key: "created_at", label: "Created", sortable: true, searchable: false, filterable: true, filter: { type: "date" } },
                    { key: "author.name", label: "Author", sortable: false, searchable: true, filterable: true, filter: { type: "text" } },
                    { key: "id", label: "#", sortable: false, searchable: false },
                ],
                state: { search: "", sort: null, trashed: false, filters: [], ...state },
            });

        it("lists the filterable columns", () => {
            const { table } = setup(filtered());

            expect(table.filterColumns.map((c) => c.key)).toEqual(["status", "created_at", "author.name"]);
        });

        it("sends a select at once and a date range a moment later, on page 1, next to the search", () => {
            const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            page.url = "/posts?page=3&filter%5Bglobal%5D=al&filter%5BtimeZone%5D=UTC";
            const { table } = setup(filtered({ search: "al" }));

            table.setColumnFilter("status", ["paid", "open"]);
            expect(lastQuery()).toEqual({
                page: "1",
                "filter[global]": "al",
                "filter[timeZone]": zone,
                "filter[status][0]": "paid",
                "filter[status][1]": "open",
            });

            // Typing a year passes through 0002, 0020…: only the last is sent.
            page.url = "/posts?filter%5Bstatus%5D%5B0%5D=paid&filter%5Bstatus%5D%5B1%5D=open";
            get.mockReset();
            table.setColumnFilter("created_at", { from: "0002-10-01" });
            table.setColumnFilter("created_at", { from: "2026-10-01", to: "2026-10-31" });
            expect(get).not.toHaveBeenCalled();

            vi.advanceTimersByTime(500);
            expect(get).toHaveBeenCalledOnce();
            expect(lastQuery()).toEqual({
                page: "1",
                "filter[status][0]": "paid",
                "filter[status][1]": "open",
                "filter[created_at][from]": "2026-10-01",
                "filter[created_at][to]": "2026-10-31",
                // The server reads the days in the user's zone, with or without a search.
                "filter[timeZone]": zone,
            });
        });

        it("waits like the search for a text, and keeps a dotted key whole", () => {
            const { table } = setup(filtered(), { searchDebounceMs: 300 });

            table.setColumnFilter("author.name", "bo");
            expect(get).not.toHaveBeenCalled();

            vi.advanceTimersByTime(300);
            expect(lastQuery()).toEqual({ page: "1", "filter[author.name]": "bo" });
        });

        it("removes a filter that is emptied, and clears them all", () => {
            page.url = "/posts?filter%5Bstatus%5D%5B0%5D=paid&filter%5Bcreated_at%5D%5Bfrom%5D=2026-10-01&filter%5Bglobal%5D=al";
            const { table } = setup(filtered({ filters: { status: ["paid"], created_at: { from: "2026-10-01" } } }));

            expect(table.hasColumnFilters).toBe(true);

            const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

            table.setColumnFilter("status", []);
            expect(lastQuery()).toEqual({ page: "1", "filter[created_at][from]": "2026-10-01", "filter[global]": "al", "filter[timeZone]": zone });

            // No date filter and no search: no zone either.
            table.clearColumnFilters();
            expect(lastQuery()).toEqual({ page: "1", "filter[global]": "al" });
            expect(table.hasColumnFilters).toBe(false);
        });

        it("follows the filters the server applied", async () => {
            const { table, data } = setup(filtered());

            data.value = filtered({ filters: { status: ["open"] } });
            await nextTick();

            expect(table.filters).toEqual({ status: ["open"] });
            expect(table.state.columnFilters).toEqual([{ id: "status", value: ["open"] }]);
        });

        it("keeps a second quick change while the first is in flight", async () => {
            const { table, data } = setup(filtered());

            table.setColumnFilter("status", ["paid"]);
            const first = lastOptions();
            table.setColumnFilter("status", ["paid", "open"]);
            const second = lastOptions();

            // The answer to the first comes back while the second is on its way.
            data.value = filtered({ filters: { status: ["paid"] } });
            await nextTick();
            first.onFinish();
            expect(table.filters).toEqual({ status: ["paid", "open"] });

            data.value = filtered({ filters: { status: ["paid", "open"] } });
            await nextTick();
            second.onFinish();
            expect(table.filters).toEqual({ status: ["paid", "open"] });
        });

        it("takes TanStack's column filters, by column id", () => {
            const { table } = setup(filtered());

            table.onColumnFiltersChange([{ id: "author_name", value: "bo" }, { id: "nope", value: "x" }]);

            expect(lastQuery()).toEqual({ page: "1", "filter[author.name]": "bo" });
        });

        it("uses the parameter the server names", () => {
            const params = { per_page: "perPage", global_filter: "q", trashed: "archived", ordering: "sort", column_filters: "where" };
            const { table } = setup({ ...filtered(), params });

            table.setColumnFilter("status", ["paid"]);

            expect(lastQuery()).toEqual({ page: "1", "where[status][0]": "paid" });
        });
    });

    it("restores a row with a POST to restoreUrl", () => {
        page.url = "/posts?filter%5Btrashed%5D=true&page=2";
        const { table } = setup(payload(), { restoreUrl: "/posts/?id/restore" });

        table.onRestore(7);

        expect(get).not.toHaveBeenCalled();
        expect(post).toHaveBeenCalledWith("/posts/7/restore", {}, { preserveState: true, preserveScroll: true });
        expect(replace).not.toHaveBeenCalled();
    });

    it("takes restoreUrl as a function of the id", () => {
        const { table } = setup(payload(), { restoreUrl: (id) => `/admin/posts/${id}/restore` });

        table.onRestore("a1");

        expect(post.mock.calls.at(-1)?.[0]).toBe("/admin/posts/a1/restore");
    });

    it("does not restore without restoreUrl", () => {
        const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
        const { table } = setup();

        table.onRestore(7);

        expect(post).not.toHaveBeenCalled();
        expect(get).not.toHaveBeenCalled();
        expect(warn).toHaveBeenCalledOnce();
        warn.mockRestore();
    });

    it("reloads the payload prop and the props the page asks for", () => {
        const { table } = setup(payload(), { propName: "posts", alsoReload: ["filters"] });

        table.goToPage(2);

        expect(lastQuery()).toEqual({ page: "2" });
        expect(lastOptions()).toMatchObject({ only: ["posts", "filters"] });
    });

    it("reads the requested page size from the URL", () => {
        page.url = "/posts?perPage=50&page=2";
        const { table } = setup();

        expect(table.perPage).toBe(50);
        expect(table.state.pagination).toEqual({ pageIndex: 2, pageSize: 20 });
    });
});
