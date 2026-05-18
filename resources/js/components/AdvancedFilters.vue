<script setup lang="ts">
import { useForm } from "@inertiajs/vue3";
import { ref } from "vue";

import { getReloadDebounceMs, r, t } from "../config";
import type { FilterValues } from "../types";
import { debounce } from "../utils";
import Modal from "./Modal.vue";

const props = defineProps<{
    reloadRoute: string;
    reloadRouteParams?: Record<string, unknown>;
    filterValues: FilterValues;
}>();

const showModal = ref<boolean>(false);

const closeModal = () => {
    showModal.value = false;
};

const advancedSearch = debounce(() => {
    const form = useForm(props.filterValues);
    const target = r(props.reloadRoute, props.reloadRouteParams) as string | undefined;

    if (!target) {
        // No router configured — fall back to a noop so we don't blow up.
        return;
    }

    form.get(target, {
        preserveState: true,
        onSuccess: () => {
            showModal.value = false;
        },
    });
}, getReloadDebounceMs() / 2);
</script>

<template>
    <div>
        <button
            class="w-full border border-[#E9E7E7] rounded-md px-5 py-1.5 active:scale-95 transition hover:bg-gray-50 my-2 sm:my-0"
            @click="showModal = true"
        >
            {{ t("Filters") }}
        </button>

        <Modal :show="showModal" @close="closeModal">
            <div class="border-b border-[#E9E7E7] px-3.5 p-3 text-xl font-medium">
                {{ t("Filters") }}
            </div>

            <slot />

            <div class="col-span-2 flex justify-end gap-3 mt-2 pt-1 pb-3 px-4">
                <button
                    class="bg-[#F0F0F0] px-12 py-2 rounded hover:opacity-80 active:scale-95 transition"
                    @click="closeModal"
                >
                    {{ t("Cancel") }}
                </button>

                <button
                    class="bg-[#00A793] text-white px-12 py-2 rounded hover:opacity-80 active:scale-95 transition"
                    @click="advancedSearch()"
                >
                    {{ t("Search") }}
                </button>
            </div>
        </Modal>
    </div>
</template>
