<script setup lang="ts">
const props = withDefaults(
    defineProps<{
        name: string;
        id: string;
        value?: string;
        label: string;
        classes?: string;
        checked: boolean;
        disabled?: boolean;
    }>(),
    {
        classes:
            "peer-checked:bg-slate-500 peer-checked:text-white peer-checked:border-blue-200 border",
    },
);

const emit = defineEmits<{ (e: "click"): void }>();

const handleClick = () => {
    if (props.disabled) return;
    emit("click");
};

const disabledClasses = `bg-slate-300 cursor-default ${
    props.checked ? "bg-slate-500 text-white" : ""
}`;
</script>

<template>
    <input
        :id="id"
        type="radio"
        :value="value"
        :name="name"
        class="peer hidden"
        :checked="checked"
    />

    <label
        :for="id"
        :class="`${disabled ? disabledClasses : classes}
        ${disabled ? 'cursor-default' : 'cursor-pointer transition-all active:scale-95'}
        relative flex shadow-md text-slate-500 border border-blue-200 px-4 py-1.5`"
        @click="handleClick"
    >
        {{ label }}
    </label>
</template>
