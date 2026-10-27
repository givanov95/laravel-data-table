import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { defineComponent, h, nextTick, ref } from "vue";
import { mount } from "@vue/test-utils";

import { useLaravelDataTable } from "../../resources/js/tanstack";
import type { LaravelDataTablePayload, UseLaravelDataTableOptions } from "../../resources/js/tanstack";

const { reload, replace, page } = vi.hoisted(() => ({
    reload: vi.fn(),
    replace: vi.fn(),
    page: { url: "/posts" } as { url: string },
}));

vi.mock("@inertiajs/vue3", async () => {
    const { reactive } = await import("vue");
    const reactivePage = reactive(page);

    return {
        router: { reload, replace },
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
    ],
    state: { search: "", sort: { column: "id", direction: "desc" }, trashed: false },
    ...overrides,
});

/** Runs the composable inside a component, like a page does. */
function setup(initial = payload(), options: UseLaravelDataTableOptions = {}) {
    const data = ref(initial);
    let table!: ReturnType<typeof useLaravelDataTable<Row>>;

    mount(
        defineComponent({
            setup() {
                table = useLaravelDataTable<Row>(() => data.value, options);

                return () => h("div");
            },
        }),
    );

    return { table, data };
}

const lastReload = () => reload.mock.calls.at(-1)?.[0];

describe("useLaravelDataTable", () => {
    beforeEach(() => {
        vi.useFakeTimers();
        reload.mockReset();
        replace.mockReset();
        page.url = "/posts";
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("shows only a declared sortable column as sorted", () => {
        // The server's default `id DESC` is not a sortable column: unsorted.
        expect(setup().table.state.sorting).toEqual([]);

        const { table } = setup(payload({ state: { sort: { column: "created_at", direction: "desc" } } }));
        expect(table.state.sorting).toEqual([{ id: "created_at", desc: true }]);
    });

    it("goes back to page 1 on a new sort, page size or archive", () => {
        const { table } = setup();

        table.onSortingChange([{ id: "created_at", desc: false }]);
        expect(lastReload().data).toEqual({ ordering: { key: "created_at", direction: "asc" }, page: 1 });

        table.onSortingChange([]);
        expect(lastReload().data).toEqual({ ordering: undefined, page: 1 });

        table.onPerPageChange(50);
        expect(lastReload().data).toEqual({ perPage: 50, page: 1 });

        table.onTrashedChange(true);
        expect(lastReload().data).toEqual({ filter: { trashed: true }, page: 1 });
    });

    it("searches once after the debounce, with the time zone, on page 1", async () => {
        const { table } = setup(payload(), { searchDebounceMs: 300 });

        table.onGlobalFilterChange("al");
        await nextTick();
        table.onGlobalFilterChange("alice");
        await nextTick();
        vi.advanceTimersByTime(299);
        expect(reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(reload).toHaveBeenCalledTimes(1);
        expect(lastReload().data).toEqual({
            filter: { global: "alice", timeZone: expect.any(String) },
            page: 1,
        });
    });

    it("clears the search in one reload with the page's own changes, cancelling a pending one", async () => {
        const { table } = setup(payload({ state: { search: "alice" } }), { searchDebounceMs: 300 });

        table.onGlobalFilterChange("alic");
        await nextTick();
        table.clearSearch({ who: undefined });
        await nextTick();
        vi.advanceTimersByTime(1000);

        expect(reload).toHaveBeenCalledTimes(1);
        expect(lastReload().data).toEqual({ who: undefined, filter: undefined, page: 1 });
        expect(table.state.globalFilter).toBe("");
    });

    it("follows the server's search term (back/forward) without searching again", async () => {
        const { table, data } = setup(payload({ state: { search: "alice" } }));

        data.value = payload({ state: { search: "bob" } });
        await nextTick();
        await nextTick();
        vi.advanceTimersByTime(5000);

        expect(table.state.globalFilter).toBe("bob");
        expect(reload).not.toHaveBeenCalled();
    });

    it("reloads the payload prop and the props the page asks for", () => {
        const { table } = setup(payload(), { propName: "posts", alsoReload: ["filters"] });

        table.goToPage(2);

        expect(lastReload()).toMatchObject({ data: { page: 2 }, only: ["posts", "filters"] });
    });

    it("reads the requested page size from the URL", async () => {
        page.url = "/posts?perPage=50&page=2";
        const { table } = setup();

        expect(table.perPage).toBe(50);
        expect(table.state.pagination).toEqual({ pageIndex: 2, pageSize: 20 });
    });
});
