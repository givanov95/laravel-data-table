import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { setConfig, resetConfig, t, r, getReloadDebounceMs } from "../../resources/js/config";

describe("config", () => {
    beforeEach(() => {
        resetConfig();
        delete (globalThis as any).__;
        delete (globalThis as any).route;
    });

    afterEach(() => {
        delete (globalThis as any).__;
        delete (globalThis as any).route;
    });

    describe("t (translator)", () => {
        it("returns the key unchanged by default", () => {
            expect(t("Hello")).toBe("Hello");
        });

        it("uses the configured translator", () => {
            setConfig({ translator: (k) => `[${k}]` });
            expect(t("Hello")).toBe("[Hello]");
        });

        it("falls back to a global __ function when no translator is configured", () => {
            (globalThis as any).__ = (k: string) => `<${k}>`;
            expect(t("World")).toBe("<World>");
        });

        it("passes replacements through to the translator", () => {
            const fn = vi.fn((k: string) => k);
            setConfig({ translator: fn });
            t("greeting", { name: "Alice" });
            expect(fn).toHaveBeenCalledWith("greeting", { name: "Alice" });
        });
    });

    describe("r (router)", () => {
        it("returns undefined by default", () => {
            expect(r()).toBeUndefined();
        });

        it("uses the configured router", () => {
            setConfig({ route: (name) => `/route/${name}` });
            expect(r("users.index")).toBe("/route/users.index");
        });

        it("falls back to a global route function", () => {
            (globalThis as any).route = (name: string) => `global:${name}`;
            expect(r("home")).toBe("global:home");
        });

        it("explicit configured router beats global fallback", () => {
            (globalThis as any).route = () => "global";
            setConfig({ route: () => "configured" });
            expect(r("anything")).toBe("configured");
        });
    });

    describe("getReloadDebounceMs", () => {
        it("returns the default debounce delay", () => {
            expect(getReloadDebounceMs()).toBe(1200);
        });

        it("respects overrides", () => {
            setConfig({ reloadDebounceMs: 250 });
            expect(getReloadDebounceMs()).toBe(250);
        });
    });
});
