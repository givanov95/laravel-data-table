import type { App, Plugin } from "vue";

import { setConfig, type DataTableConfig } from "./config";

export interface DataTablePluginOptions extends Partial<DataTableConfig> {}

// Typed options, so `app.use(DataTablePlugin, { translator: (key, replacements) => … })` infers them.
export const DataTablePlugin: Plugin<[DataTablePluginOptions?]> = {
    install(_app: App, options: DataTablePluginOptions = {}) {
        setConfig(options);
    },
};
