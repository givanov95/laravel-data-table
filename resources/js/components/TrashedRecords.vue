<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { ref } from "vue";

import { t } from "../config";
import type { RadioToggleInput, ThisRoute } from "../types";
import { r } from "../config";
import RadioButtonToggle from "./RadioButtonToggle.vue";

const props = defineProps<{
    propName: string;
}>();

const thisRoute = (r() as ThisRoute | undefined) ?? { params: {} };

const archivedOptionSelected = ref<boolean>(
    thisRoute.params.filter?.trashed === "true",
);

const handleShowTrashedRecords = async (input: RadioToggleInput) => {
    await new Promise((resolve, reject) => {
        router.reload({
            data: {
                filter: {
                    trashed: input.value,
                },
            },
            only: [props.propName],
            onSuccess: resolve,
            onError: reject,
        });
    });
};
</script>

<template>
    <RadioButtonToggle
        v-model="archivedOptionSelected"
        name="filter[trashed]"
        :left-button-label="t('Archived')"
        :right-button-label="t('Not Archived')"
        @change="handleShowTrashedRecords"
    />
</template>
