<script setup lang="ts">
withDefaults(defineProps<{ show: boolean }>(), { show: false });

const emit = defineEmits<{ (e: "close"): void }>();

const handleOverlayClick = () => {
    emit("close");
};
</script>

<template>
    <transition name="fade">
        <div
            v-if="show"
            class="fixed inset-0 z-50 grid place-items-center bg-black/50 px-4 py-6"
            @click.self="handleOverlayClick"
        >
            <div class="w-full max-w-2xl rounded-2xl bg-white shadow-xl overflow-hidden">
                <slot />
            </div>
        </div>
    </transition>
</template>
