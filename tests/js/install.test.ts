import { describe, it, expect } from "vitest";
import { createApp } from "vue";

import { DataTablePlugin } from "../../resources/js/install";
import { t, r, getReloadDebounceMs, resetConfig } from "../../resources/js/config";

describe("DataTablePlugin", () => {
    it("installs without options without crashing", () => {
        resetConfig();

        const app = createApp({});
        expect(() => app.use(DataTablePlugin)).not.toThrow();
        expect(t("Hello")).toBe("Hello");
    });

    it("wires the translator, router and debounce settings", () => {
        const app = createApp({});

        app.use(DataTablePlugin, {
            translator: (k: string) => `tr:${k}`,
            route: (name: unknown) => `route:${name}`,
            reloadDebounceMs: 333,
        });

        expect(t("Hello")).toBe("tr:Hello");
        expect(r("users.index")).toBe("route:users.index");
        expect(getReloadDebounceMs()).toBe(333);
    });
});
