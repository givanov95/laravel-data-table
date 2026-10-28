import { beforeEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { h } from "vue";

import DataTable from "../../resources/js/Table.vue";
import { resetConfig } from "../../resources/js/config";
import type { DataTable as DataTableType } from "../../resources/js/types";

const { post, reload } = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));

vi.mock("@inertiajs/vue3", async (importOriginal) => {
    const original = await importOriginal<typeof import("@inertiajs/vue3")>();

    return { ...original, router: { ...original.router, post, reload } };
});

type Row = { id: number; name: string };

const payload = (extra: Partial<DataTableType<Row>> = {}): DataTableType<Row> => ({
    data: [{ id: 5, name: "Alice" }],
    columns: {
        name: { label: "Name", searchable: false, orderable: false },
        action: { label: "", searchable: false, orderable: false },
    },
    paginator: {
        itemsLength: 1,
        perPage: 10,
        links: {},
        currentPage: 1,
        lastPage: 1,
        lastPageUrl: "/?page=1",
        pagesRange: 2,
    },
    ...extra,
});

const mountTable = (dataTable: DataTableType<Row>, props: Record<string, unknown> = {}) =>
    mount(DataTable as any, {
        props: { dataTable, ...props },
        slots: { "cell(action)": () => h("a", { class: "edit" }, "Edit") },
    });

describe("DataTable archive", () => {
    beforeEach(() => {
        resetConfig();
        post.mockReset();
        reload.mockReset();
    });

    it("restores an archived row with a POST to restoreUrl", async () => {
        const wrapper = mountTable(payload({ trashed: true }), { restoreUrl: "/posts/?id/restore" });

        await wrapper.find("button[title='Restore']").trigger("click");

        expect(post).toHaveBeenCalledWith("/posts/5/restore", {}, { preserveState: true, preserveScroll: true });
        expect(reload).not.toHaveBeenCalled();
    });

    it("has no restore button without restoreUrl", () => {
        const wrapper = mountTable(payload({ trashed: true }));

        expect(wrapper.find("button[title='Restore']").exists()).toBe(false);
    });

    it("follows the archive the server applied, not the URL", () => {
        // `trashed: false`: the table does not allow the archive, so the rows are live.
        const wrapper = mountTable(payload({ trashed: false }), { restoreUrl: "/posts/?id/restore" });

        expect(wrapper.find("button[title='Restore']").exists()).toBe(false);
        expect(wrapper.find("a.edit").exists()).toBe(true);
    });

    it("toggles the archive under the trashed key of the server", async () => {
        const wrapper = mountTable(
            payload({
                trashed: false,
                requestKeys: { per_page: "perPage", global_filter: "filter.global", trashed: "filter.archived", ordering: "ordering" },
            }),
            { showTrashed: true, globalSearch: true },
        );

        await wrapper.find("label[for='yes_filter[archived]']").trigger("click");

        expect(reload.mock.calls.at(-1)?.[0]).toMatchObject({ data: { filter: { archived: true } }, only: ["dataTable"] });
    });
});
