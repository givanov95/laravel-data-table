import { describe, it, expect, beforeEach } from "vitest";
import { mount } from "@vue/test-utils";

import DataTable from "../../resources/js/Table.vue";
import { setConfig, resetConfig } from "../../resources/js/config";
import type { DataTable as DataTableType } from "../../resources/js/types";

const buildPayload = (rows: Array<{ id: number; name: string }>): DataTableType<{ id: number; name: string }> => ({
    data: rows,
    columns: {
        id: { label: "#", searchable: true, orderable: true },
        name: { label: "Name", searchable: true, orderable: false },
    },
    paginator: {
        itemsLength: rows.length,
        perPage: 10,
        links: {},
        currentPage: 1,
        lastPage: 1,
        lastPageUrl: "/?page=1",
        pagesRange: 2,
    },
});

describe("DataTable component", () => {
    beforeEach(() => {
        resetConfig();
    });

    it("renders one row per data item", () => {
        const wrapper = mount(DataTable as any, {
            props: {
                dataTable: buildPayload([
                    { id: 1, name: "Alice" },
                    { id: 2, name: "Bob" },
                ]),
            },
        });

        expect(wrapper.findAll("tbody tr").length).toBe(2);
        expect(wrapper.text()).toContain("Alice");
        expect(wrapper.text()).toContain("Bob");
    });

    it("renders the column labels in the header", () => {
        const wrapper = mount(DataTable as any, {
            props: {
                dataTable: buildPayload([{ id: 1, name: "Alice" }]),
            },
        });

        const headerText = wrapper.find("thead").text();
        expect(headerText).toContain("#");
        expect(headerText).toContain("Name");
    });

    it("renders the empty-state row when data is empty", () => {
        const wrapper = mount(DataTable as any, {
            props: {
                dataTable: buildPayload([]),
            },
        });

        expect(wrapper.text()).toContain("No found data");
    });

    it("respects a custom translator for the empty state", () => {
        setConfig({ translator: (k) => (k === "No found data" ? "Няма резултати" : k) });

        const wrapper = mount(DataTable as any, {
            props: {
                dataTable: buildPayload([]),
            },
        });

        expect(wrapper.text()).toContain("Няма резултати");
    });

    it("renders a custom cell slot", () => {
        const wrapper = mount(DataTable as any, {
            props: {
                dataTable: buildPayload([{ id: 1, name: "Alice" }]),
            },
            slots: {
                "cell(name)": '<template #default="{ value }"><strong class="custom">{{ value }}</strong></template>',
            },
        });

        const strong = wrapper.find("strong.custom");
        expect(strong.exists()).toBe(true);
        expect(strong.text()).toBe("Alice");
    });
});
