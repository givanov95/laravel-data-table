<script setup lang="ts">
withDefaults(
    defineProps<{
        classes?: string;
        solid?: boolean;
    }>(),
    {
        classes: "w-6 h-6 flex-shrink-0",
        solid: false,
    },
);
</script>

<template>
    <svg
        v-if="solid"
        viewBox="0 0 25 25"
        fill="currentColor"
        :class="classes"
    >
        <path
            d="M5.88468 17C7.32466 19.1128 9.75033 20.5 12.5 20.5C16.9183 20.5 20.5 16.9183 20.5 12.5C20.5 8.08172 16.9183 4.5 12.5 4.5C8.08172 4.5 4.5 8.08172 4.5 12.5V13.5M12.5 8V12.5L15.5 15.5"
        />
        <path d="M7 11L4.5 13.5L2 11" />
    </svg>

    <svg
        v-else
        fill="none"
        viewBox="0 0 24 24"
        stroke-width="1.5"
        stroke="currentColor"
        :class="classes"
    >
        <path
            d="M5.88468 17C7.32466 19.1128 9.75033 20.5 12.5 20.5C16.9183 20.5 20.5 16.9183 20.5 12.5C20.5 8.08172 16.9183 4.5 12.5 4.5C8.08172 4.5 4.5 8.08172 4.5 12.5V13.5M12.5 8V12.5L15.5 15.5"
        />
        <path d="M7 11L4.5 13.5L2 11" />
    </svg>
</template>
