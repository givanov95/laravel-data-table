import { describe, it, expect, beforeEach, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { nextTick } from "vue";

import Pagination from "../../resources/js/components/Pagination.vue";
import { resetConfig } from "../../resources/js/config";
import type { Paginator } from "../../resources/js/types";

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock("@inertiajs/vue3", async () => {
    const { defineComponent, h } = await import("vue");

    return {
        router: { reload },
        Link: defineComponent({
            setup: (_, { slots }) => () => h("a", slots.default?.()),
        }),
    };
});

const OPTIONS = [25, 50, 250];

const paginator = (perPage: number): Paginator => ({
    itemsLength: 300,
    perPage,
    links: { 1: "/?page=1", 2: "/?page=2" },
    currentPage: 1,
    lastPage: 3,
    lastPageUrl: "/?page=3",
    pagesRange: 2,
});

const mountPagination = (perPage: number, perPageOptions: number[] | null = OPTIONS) =>
    mount(Pagination as any, { props: { paginator: paginator(perPage), perPageOptions: perPageOptions ?? undefined } });

const select = (wrapper: VueWrapper) => wrapper.find("select").element as HTMLSelectElement;

/**
 * Finishes the pending reload the way Inertia does: after the response's props
 * are in place (or without any, when the request failed or was cancelled).
 */
const finishReload = () => (reload.mock.lastCall![0] as { onFinish?: () => void }).onFinish?.();

/** The option the select currently shows, as the user sees it. */
const shown = (wrapper: VueWrapper) => {
    const el = select(wrapper);

    return el.options[el.selectedIndex]?.textContent?.trim();
};

describe("Pagination per-page select", () => {
    beforeEach(() => {
        resetConfig();
        reload.mockReset();
        reload.mockImplementation(() => undefined);
    });

    it("shows the page size the server applied on first render", () => {
        expect(shown(mountPagination(50))).toBe("50");
    });

    it("shows the server default as Default (N) when it is not one of the options", () => {
        const wrapper = mountPagination(15);

        expect(select(wrapper).selectedIndex).toBe(0);
        expect(shown(wrapper)).toBe("Default (15)");
    });

    it("keeps a plain Default label while a concrete option is selected", () => {
        const wrapper = mountPagination(50);

        expect(wrapper.findAll("option")[0].text()).toBe("Default");
    });

    it("asks the server for the chosen page size", async () => {
        const wrapper = mountPagination(15);

        await wrapper.find("select").setValue("250");

        expect(reload).toHaveBeenCalledWith(expect.objectContaining({ data: { perPage: 250 }, only: ["dataTable"] }));
    });

    it("asks for the default page size when Default is chosen", async () => {
        const wrapper = mountPagination(50);

        await wrapper.findAll("option")[0].setSelected();

        expect(reload).toHaveBeenCalledWith(expect.objectContaining({ data: { perPage: null } }));
    });

    it("falls back to the applied size when the server cuts the requested one", async () => {
        const wrapper = mountPagination(15);

        await wrapper.find("select").setValue("250");
        expect(shown(wrapper)).toBe("250");

        // The server clamped 250 to max_per_page and answered with 100 rows.
        await wrapper.setProps({ paginator: paginator(100) });

        expect(select(wrapper).selectedIndex).toBe(0);
        expect(shown(wrapper)).toBe("Default (100)");
    });

    it("falls back even when the applied size is the one it already had", async () => {
        const wrapper = mountPagination(100);

        await wrapper.find("select").setValue("250");
        expect(shown(wrapper)).toBe("250");

        // 100 before and 100 after: only the paginator object is new.
        await wrapper.setProps({ paginator: paginator(100) });
        await nextTick();

        expect(shown(wrapper)).toBe("Default (100)");
    });

    it("goes back to what the table shows when the reload fails", async () => {
        const wrapper = mountPagination(15);

        await wrapper.find("select").setValue("250");
        expect(shown(wrapper)).toBe("250");

        // No new paginator: the request failed or was cancelled, so nothing changed.
        finishReload();
        await flushPromises();

        expect(shown(wrapper)).toBe("Default (15)");
    });

    it("stays on the new size once the reload has finished", async () => {
        const wrapper = mountPagination(15);

        await wrapper.find("select").setValue("50");
        await wrapper.setProps({ paginator: paginator(50) });
        finishReload();
        await flushPromises();

        expect(shown(wrapper)).toBe("50");
    });

    it("follows the paginator when the page size changes on its own", async () => {
        const wrapper = mountPagination(15);

        await wrapper.setProps({ paginator: paginator(50) });

        expect(shown(wrapper)).toBe("50");
    });

    it("renders no select without perPageOptions", () => {
        const wrapper = mountPagination(15, null);

        expect(wrapper.find("select").exists()).toBe(false);
    });
});
