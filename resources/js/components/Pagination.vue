<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { computed, nextTick, ref, watch } from "vue";

import { t } from "../config";
import type { Paginator } from "../types";
import { buildUrlWithParam } from "../utils";
import IconChevronDoubleLeft from "../icons/ChevronDoubleLeft.vue";
import IconChevronLeft from "../icons/ChevronLeft.vue";
import IconChevronRight from "../icons/ChevronRight.vue";

const props = withDefaults(
    defineProps<{
        paginator: Paginator;
        propName?: string;
        perPageOptions?: number[];
    }>(),
    { propName: "dataTable" },
);

/**
 * The option that matches the page size the server applied. `null` is the
 * "Default" option: the server's own default, or a size that is not one of the
 * options (e.g. a requested one cut to `max_per_page`).
 */
const appliedOption = computed<number | null>(() =>
    props.perPageOptions?.includes(props.paginator.perPage) ? props.paginator.perPage : null,
);

const defaultLabel = computed(() =>
    appliedOption.value === null ? `${t("Default")} (${props.paginator.perPage})` : t("Default"),
);

const selectedPerPageOption = ref<number | null>(appliedOption.value);

// Every response brings a new paginator, even when the page size is the one it
// already had, so the select is put back on what the server applied rather than
// staying on what the user asked for.
watch([() => props.paginator, () => props.perPageOptions], () => {
    selectedPerPageOption.value = appliedOption.value;
});

const handlePerPageItems = async () => {
    // `onFinish` follows a success, a failure and a cancellation alike.
    await new Promise<void>((resolve) => {
        router.reload({
            data: { perPage: selectedPerPageOption.value },
            only: [props.propName],
            onFinish: () => resolve(),
        });
    });

    // After a success the watcher above has already done this. After a failure or a
    // cancellation no new paginator came, so the select would stay on the size that
    // was asked for while the table still shows the old one.
    await nextTick();
    selectedPerPageOption.value = appliedOption.value;
};
</script>

<template>
    <div
        v-if="Object.keys(paginator.links).length > 1"
        class="flex justify-center sm:justify-between my-2 px-5 items-center"
    >
        <div class="hidden sm:block text-sm text-gray-500">
            {{ t("Showing") }}
            <span class="font-semibold">
                {{ paginator.currentPage * paginator.perPage - (paginator.perPage - 1) }}
            </span>
            {{ t("to") }}
            <span class="font-semibold">
                {{
                    paginator.currentPage === paginator.lastPage
                        ? paginator.itemsLength
                        : paginator.currentPage * paginator.perPage
                }}
            </span>
            {{ t("of") }}
            <span class="font-semibold">
                {{ paginator.itemsLength }}
            </span>
            {{ t("Entries") }}
        </div>

        <div v-if="perPageOptions" class="flex gap-x-2">
            <select
                v-model="selectedPerPageOption"
                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block"
                @change="handlePerPageItems"
            >
                <option :value="null">{{ defaultLabel }}</option>
                <option v-for="option in perPageOptions" :key="option" :value="option">
                    {{ option }}
                </option>
            </select>
        </div>

        <div class="flex gap-x-2">
            <Link
                v-if="paginator.currentPage - paginator.pagesRange > 1"
                class="rounded w-9 h-9 flex items-center justify-center leading-4 text-sm transition text-gray-500 hover:text-white bg-white hover:bg-[#008FE3]"
                :only="[propName, 'paginator']"
                :preserve-state="true"
                :href="buildUrlWithParam('page', 1)"
                preserve-scroll
            >
                <IconChevronDoubleLeft />
            </Link>

            <Link
                v-if="paginator.currentPage !== 1"
                class="rounded w-9 h-9 flex items-center justify-center leading-4 text-sm transition text-gray-500 hover:text-white bg-white hover:bg-[#008FE3]"
                :href="buildUrlWithParam('page', paginator.currentPage - 1)"
            >
                <IconChevronLeft />
            </Link>

            <div v-for="(link, key) in paginator.links" :key="key">
                <Link
                    class="rounded w-9 h-9 flex items-center justify-center leading-4 text-sm transition"
                    :only="[propName, 'paginator']"
                    preserve-scroll
                    :preserve-state="true"
                    :href="link"
                    :class="
                        Number(key) === paginator.currentPage
                            ? 'bg-[#008FE3] text-white'
                            : 'text-gray-500 hover:text-white bg-white hover:bg-[#008FE3]'
                    "
                >
                    {{ key }}
                </Link>
            </div>

            <div
                v-if="paginator.currentPage + paginator.pagesRange < paginator.lastPage"
                class="flex items-center gap-2 ml-2"
            >
                <div class="text-xl tracking-widest mt-2">...</div>
                <Link
                    class="rounded w-9 h-9 flex items-center justify-center leading-4 text-sm transition text-gray-500 hover:text-white bg-white hover:bg-[#008FE3]"
                    :only="[propName]"
                    preserve-scroll
                    :preserve-state="true"
                    :href="paginator.lastPageUrl"
                >
                    {{ paginator.lastPage }}
                </Link>
            </div>

            <Link
                v-if="paginator.currentPage < paginator.lastPage"
                class="rounded w-9 h-9 flex items-center justify-center leading-4 text-sm transition text-gray-500 hover:text-white bg-white hover:bg-[#008FE3]"
                :only="[propName]"
                preserve-scroll
                :preserve-state="true"
                :href="buildUrlWithParam('page', paginator.currentPage + 1)"
            >
                <IconChevronRight />
            </Link>
        </div>
    </div>
</template>
