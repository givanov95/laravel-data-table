<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { ref } from "vue";

import { getReloadDebounceMs, r, t } from "../config";
import { debounce } from "../utils";
import Magnifying from "../icons/Magnifying.vue";

const props = defineProps<{
    propName: string;
}>();

const filterGlobalValue = new URLSearchParams(window.location.search).get(
    "filter[global]",
);

const inputValue = ref<string | null>(filterGlobalValue);

const triggerReload = (): void => {
    const data: Record<string, unknown> = {
        filter: {
            global: inputValue.value,
            timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        },
        page: 1,
    };

    const thisRoute = r() as { current?: () => string; params?: Record<string, unknown> } | undefined;
    const target =
        thisRoute && typeof thisRoute.current === "function"
            ? (r(thisRoute.current() as string, { ...(thisRoute.params ?? {}), page: 1 }) as string | undefined)
            : undefined;

    if (target) {
        router.visit(target, {
            method: "get",
            data,
            preserveState: true,
            preserveScroll: true,
            only: [props.propName],
        });
        return;
    }

    router.reload({
        data,
        only: [props.propName],
    });
};

const globalSearch = debounce(triggerReload, getReloadDebounceMs());
</script>

<template>
    <div class="md:flex items-center justify-between">
        <div class="md:w-80 lg:w-96 relative">
            <div
                class="absolute inset-y-0 left-0 flex items-center pl-3 z-10 cursor-pointer"
                @click="triggerReload"
            >
                <Magnifying stroke="2" classes="w-5 h-5 text-gray-400" />
            </div>

            <div class="relative">
                <input
                    v-model="inputValue"
                    class="border border-gray-200 text-gray-900 pl-10 pr-4 text-sm rounded-md focus:outline-none focus:ring-0 focus:border-gray-300 block w-full p-2.5 placeholder-gray-400 peer transition hover:bg-gray-50 focus:bg-gray-50 z-0"
                    :placeholder="t('Search') + '...'"
                    @input="globalSearch"
                />
            </div>
        </div>
    </div>
</template>
