<script setup lang="ts">
withDefaults(
    defineProps<{
        classes?: string;
        stroke?: string;
    }>(),
    {
        classes: "mr-[1px] w-5 h-5 flex-shrink-0",
        stroke: "2",
    },
);
</script>

<template>
    <svg
        fill="none"
        viewBox="0 0 24 24"
        :stroke-width="stroke"
        stroke="currentColor"
        :class="classes"
    >
        <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5"
        />
    </svg>
</template>
