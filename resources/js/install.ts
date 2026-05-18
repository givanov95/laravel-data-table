import type { App, Plugin } from "vue";

import { setConfig, type DataTableConfig } from "./config";

export interface DataTablePluginOptions extends Partial<DataTableConfig> {}

export const DataTablePlugin: Plugin = {
    install(_app: App, options: DataTablePluginOptions = {}) {
        setConfig(options);
    },
};
