import { beforeEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { h } from "vue";

import DataTable from "../../resources/js/Table.vue";
import { resetConfig } from "../../resources/js/config";
import type { DataTable as DataTableType } from "../../resources/js/types";

const { post, reload, get } = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn(), get: vi.fn() }));

vi.mock("@inertiajs/vue3", async (importOriginal) => {
    const original = await importOriginal<typeof import("@inertiajs/vue3")>();

    return { ...original, router: { ...original.router, post, reload, get } };
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
        get.mockReset();
    });

    it("restores an archived row with a POST to restoreUrl", async () => {
        const wrapper = mountTable(payload({ trashed: true }), { restoreUrl: "/posts/?id/restore" });

        await wrapper.find("button[title='Restore']").trigger("click");

        expect(post).toHaveBeenCalledWith("/posts/5/restore", {}, expect.objectContaining({ preserveState: true, preserveScroll: true }));
        expect(reload).not.toHaveBeenCalled();
    });

    it("identifies a row by the model's key from the payload", async () => {
        const wrapper = mount(DataTable as any, {
            props: {
                dataTable: { ...payload({ trashed: true, keyName: "uid" }), data: [{ uid: 12, name: "Alice" }] },
                restoreUrl: "/shops/?id/restore",
            },
            slots: { "cell(action)": () => h("a", "Edit") },
        });

        await wrapper.find("button[title='Restore']").trigger("click");

        expect(post.mock.calls.at(-1)?.[0]).toBe("/shops/12/restore");
    });

    it("takes the row key from rowKey, for row clicks too", async () => {
        const wrapper = mount(DataTable as any, {
            props: {
                dataTable: { ...payload({ trashed: false }), data: [{ id: 1, code: "A-1", name: "Alice" }] },
                rowKey: "code",
                rowClickLink: "/items/?id",
            },
        });

        await wrapper.find("tbody td").trigger("click");

        expect(get).toHaveBeenCalledWith("/items/A-1");
    });

    it("hides the archive toggle when the server does not allow the archive", () => {
        const wrapper = mountTable(payload({ trashed: false, trashedAllowed: false }), { showTrashed: true, globalSearch: true });

        expect(wrapper.find("label[for='yes_filter[trashed]']").exists()).toBe(false);
    });

    it("sends one restore for a double click", async () => {
        const wrapper = mountTable(payload({ trashed: true }), { restoreUrl: "/posts/?id/restore" });
        const button = wrapper.find("button[title='Restore']");

        await button.trigger("click");
        await button.trigger("click");

        expect(post).toHaveBeenCalledOnce();
        post.mock.calls[0][2].onFinish();
        await wrapper.vm.$nextTick();
        expect((wrapper.find("button[title='Restore']").element as HTMLButtonElement).disabled).toBe(false);
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

    it("puts the toggle back when the server does not show the archive", async () => {
        // The reload comes back with `trashed: false` (the table or the user may not see the archive).
        reload.mockImplementation((options: { onSuccess: () => void; onFinish: () => void }) => {
            options.onSuccess();
            options.onFinish();
        });
        const wrapper = mountTable(payload({ trashed: false }), { showTrashed: true, globalSearch: true });

        await wrapper.find("label[for='yes_filter[trashed]']").trigger("click");
        await new Promise((resolve) => setTimeout(resolve));

        expect((wrapper.find("input[id='yes_filter[trashed]']").element as HTMLInputElement).checked).toBe(false);
        expect((wrapper.find("input[id='no_filter[trashed]']").element as HTMLInputElement).checked).toBe(true);
    });
});
