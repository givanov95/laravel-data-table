<script setup lang="ts">
import { router } from "@inertiajs/vue3";
import { ref, watch } from "vue";

import { t } from "../config";
import type { RadioToggleInput } from "../types";
import { nestedOf, queryKeyOf } from "../utils";
import RadioButtonToggle from "./RadioButtonToggle.vue";

const props = withDefaults(
    defineProps<{
        propName: string;
        /** The `trashed` request key of config/data-table.php, dotted. */
        trashedKey?: string;
        /** Whether the archive is shown, as the server applied it. */
        trashed?: boolean;
    }>(),
    {
        trashedKey: "filter.trashed",
        trashed: false,
    },
);

const archivedOptionSelected = ref<boolean>(props.trashed);

watch(
    () => props.trashed,
    (value) => {
        archivedOptionSelected.value = value;
    },
);

const handleShowTrashedRecords = async (input: RadioToggleInput) => {
    await new Promise((resolve, reject) => {
        router.reload({
            data: nestedOf(props.trashedKey, input.value),
            only: [props.propName],
            onSuccess: resolve,
            onError: reject,
            // The server may not show the archive (not allowed for the table or
            // the user): then `trashed` stays false, the watch above does not
            // fire, and the toggle has to be put back by hand.
            onFinish: () => {
                archivedOptionSelected.value = props.trashed;
            },
        });
    });
};
</script>

<template>
    <RadioButtonToggle
        v-model="archivedOptionSelected"
        :name="queryKeyOf(trashedKey)"
        :left-button-label="t('Archived')"
        :right-button-label="t('Not Archived')"
        @change="handleShowTrashedRecords"
    />
</template>
