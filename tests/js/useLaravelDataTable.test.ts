import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, nextTick, ref } from "vue";
import { mount } from "@vue/test-utils";

import { resetConfig, setConfig } from "../../resources/js/config";
import { useLaravelDataTable } from "../../resources/js/tanstack";
import type { LaravelDataTablePayload, UseLaravelDataTableOptions } from "../../resources/js/tanstack";

const { get, replace, page } = vi.hoisted(() => ({
    get: vi.fn(),
    replace: vi.fn(),
    page: { url: "/posts" } as { url: string },
}));

vi.mock("@inertiajs/vue3", async () => {
    const { reactive } = await import("vue");
    const reactivePage = reactive(page);

    return {
        router: { get, replace },
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
        const params = { per_page: "per_page", global_filter: "q.term", trashed: "archived", ordering: "sort", restore_id: "restore" };
        const { table } = setup(payload({ params }));

        expect(table.perPage).toBe(50);

        table.onSortingChange([{ id: "created_at", desc: false }]);
        expect(lastQuery()).toMatchObject({ "sort[key]": "created_at", "sort[direction]": "asc" });

        table.onTrashedChange(true);
        expect(lastQuery()).toMatchObject({ archived: "true" });
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
