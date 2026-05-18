import { describe, it, expect, vi } from "vitest";

import {
    debounce,
    relationWithColumn,
    replacePlaceholder,
    limitCharacters,
    camelCaseToSnakeCase,
    buildUrlWithParam,
    getFilterColumnValuesFromUrl,
} from "../../resources/js/utils";

describe("relationWithColumn", () => {
    it("returns strings unchanged", () => {
        expect(relationWithColumn("user.name")).toBe("user.name");
    });

    it("joins arrays with dots", () => {
        expect(relationWithColumn(["user", "profile", "name"])).toBe("user.profile.name");
    });
});

describe("replacePlaceholder", () => {
    it("replaces the default ?id placeholder", () => {
        expect(replacePlaceholder("/users/?id/edit", 42)).toBe("/users/42/edit");
    });

    it("supports custom placeholders", () => {
        expect(replacePlaceholder("/post/:slug", "hello-world", ":slug")).toBe("/post/hello-world");
    });
});

describe("limitCharacters", () => {
    it("truncates long strings with ellipsis", () => {
        expect(limitCharacters("Lorem ipsum dolor sit amet", 5)).toBe("Lorem...");
    });

    it("leaves short strings alone", () => {
        expect(limitCharacters("Short", 10)).toBe("Short");
    });

    it("passes through non-strings", () => {
        expect(limitCharacters(42, 5)).toBe(42);
        expect(limitCharacters(null, 5)).toBe(null);
    });
});

describe("camelCaseToSnakeCase", () => {
    it("converts camelCase to snake_case", () => {
        expect(camelCaseToSnakeCase("createdAt")).toBe("created_at");
        expect(camelCaseToSnakeCase("CreatedAt")).toBe("_created_at");
    });
});

describe("buildUrlWithParam", () => {
    it("sets a query parameter on the current URL", () => {
        window.history.replaceState({}, "", "http://localhost:3000/users?page=2");
        const result = buildUrlWithParam("page", 5);
        expect(result).toBe("http://localhost:3000/users?page=5");
    });

    it("adds a missing parameter", () => {
        window.history.replaceState({}, "", "http://localhost:3000/users");
        expect(buildUrlWithParam("perPage", 50)).toBe("http://localhost:3000/users?perPage=50");
    });
});

describe("getFilterColumnValuesFromUrl", () => {
    it("returns column filter values from the URL", () => {
        window.history.replaceState(
            {},
            "",
            "http://localhost:3000/?filter[columns][name]=Alice&filter[columns][email]=a@b.c&page=1",
        );
        expect(getFilterColumnValuesFromUrl()).toEqual({
            name: "Alice",
            email: "a@b.c",
        });
    });
});

describe("debounce", () => {
    it("collapses rapid calls into a single trailing call", async () => {
        vi.useFakeTimers();
        const fn = vi.fn();
        const debounced = debounce(fn, 50);

        debounced();
        debounced();
        debounced();
        expect(fn).not.toHaveBeenCalled();

        vi.advanceTimersByTime(60);
        expect(fn).toHaveBeenCalledTimes(1);

        vi.useRealTimers();
    });
});
