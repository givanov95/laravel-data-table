import type { Ref } from "vue";

import type { FilterValues } from "../types";

type Callback = (...args: unknown[]) => void;

export function debounce<T extends Callback>(callback: T, delay = 300): T {
    let timer: ReturnType<typeof setTimeout> | undefined;

    return function (this: unknown, ...args: unknown[]) {
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => callback.apply(this, args), delay);
    } as T;
}

export function relationWithColumn(relationColumnArr: string | string[]): string {
    if (!Array.isArray(relationColumnArr)) return relationColumnArr;
    return relationColumnArr.join(".");
}

export const fillValues = (
    filterValues: Ref<FilterValues>,
    column: string,
    value: string,
): void => {
    const { filter } = filterValues.value;
    const { columns } = filter;

    filterValues.value = {
        ...filterValues.value,
        filter: {
            ...filter,
            columns: {
                ...columns,
                [column]: value,
            },
        },
    };
};

export function getFilterColumnValuesFromUrl(): Record<string, string> {
    const urlParams = new URLSearchParams(window.location.search);
    const filterColumnsParams = [...urlParams.keys()].filter((key) =>
        key.startsWith("filter[columns]"),
    );

    const out: Record<string, string> = {};

    filterColumnsParams.forEach((key) => {
        const matches = key.match(/\[([^[\]]+)\]$/);
        if (matches && matches.length > 1) {
            const lastKey = matches[1];
            const value = urlParams.get(key);
            if (lastKey && value) out[lastKey] = value;
        }
    });

    return out;
}

export function camelCaseToSnakeCase(input: string): string {
    return input.replace(/[A-Z]/g, (match) => `_${match.toLowerCase()}`);
}

export function replacePlaceholder(
    inputString: string,
    replacement: number | string,
    placeholder = "?id",
): string {
    return inputString.replace(placeholder, String(replacement));
}

export const limitCharacters = (
    str: string | number | null | undefined,
    limit: number,
): string | number | null | undefined => {
    if (typeof str !== "string") return str;
    if (str.length > limit) return `${str.substring(0, limit)}...`;
    return str;
};

export function buildUrlWithParam(
    paramKey: string,
    paramValue: number | string,
): string {
    const url = new URL(window.location.href);
    url.searchParams.delete(paramKey);
    url.searchParams.set(paramKey, String(paramValue));
    return url.toString();
}
