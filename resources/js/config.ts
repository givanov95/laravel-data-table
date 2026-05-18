/**
 * Runtime configuration for the data-table package.
 *
 * The components historically depended on a global `__()` translation helper
 * and ziggy's global `route()`. The package no longer assumes either exists:
 * consumers can register their own implementations through the Vue plugin, or
 * let the fallbacks below take over (identity translation, plain window.location
 * navigation).
 */

export type Translator = (key: string, replacements?: Record<string, unknown>) => string;

export type Router = (...args: unknown[]) => unknown;

export interface DataTableConfig {
    translator: Translator;
    route: Router | null;
    reloadDebounceMs: number;
}

const defaultTranslator: Translator = (key) => key;

const state: DataTableConfig = {
    translator: defaultTranslator,
    route: null,
    reloadDebounceMs: 1200,
};

export function setConfig(partial: Partial<DataTableConfig>): void {
    if (partial.translator) state.translator = partial.translator;
    if (partial.route !== undefined) state.route = partial.route;
    if (typeof partial.reloadDebounceMs === "number") {
        state.reloadDebounceMs = partial.reloadDebounceMs;
    }
}

export function t(key: string, replacements?: Record<string, unknown>): string {
    if (state.translator !== defaultTranslator) {
        return state.translator(key, replacements);
    }

    const globalT = (globalThis as any).__;
    if (typeof globalT === "function") {
        return globalT(key, replacements);
    }

    return key;
}

export function r(...args: unknown[]): unknown {
    if (state.route) {
        return state.route(...args);
    }

    const globalRoute = (globalThis as any).route;
    if (typeof globalRoute === "function") {
        return globalRoute(...args);
    }

    return undefined;
}

export function getReloadDebounceMs(): number {
    return state.reloadDebounceMs;
}
