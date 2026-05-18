<script setup lang="ts">
import { Link, router } from "@inertiajs/vue3";
import { ref } from "vue";

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

const selectedPerPageOption = ref<number | null>(null);

const handlePerPageItems = async () => {
    await new Promise((resolve, reject) => {
        router.reload({
            data: { perPage: selectedPerPageOption.value },
            only: [props.propName],
            onSuccess: resolve,
            onError: reject,
        });
    });
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
                <option :value="null">{{ t("Default") }}</option>
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
