<script setup>
import { computed } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps({
    modelValue: {
        type: String,
        default: "",
    },
    placeholder: String,
    label: String,
    hint: String,
    error: String,
    disabled: Boolean,
    rows: {
        type: [Number, String],
        default: 3,
    },
});

const emit = defineEmits(["update:modelValue"]);

const textareaClasses = computed(() =>
    cn(
        "w-full px-3 py-2 text-sm rounded-lg border transition-colors resize-y",
        "bg-[var(--surface-primary)] text-[var(--text-primary)]",
        "placeholder:text-[var(--text-muted)]",
        "focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20",
        props.error
            ? "border-red-500 focus:border-red-500"
            : "border-[var(--border-default)] focus:border-[var(--interactive-primary)]",
        props.disabled && "opacity-50 cursor-not-allowed"
    )
);

function handleInput(event) {
    emit("update:modelValue", event.target.value);
}
</script>

<template>
    <div class="space-y-1.5">
        <label
            v-if="label"
            class="block text-sm font-medium text-[var(--text-primary)]"
        >
            {{ label }}
        </label>
        <textarea
            :value="modelValue"
            :placeholder="placeholder"
            :disabled="disabled"
            :rows="rows"
            :class="textareaClasses"
            @input="handleInput"
        />
        <p v-if="hint && !error" class="text-xs text-[var(--text-muted)]">
            {{ hint }}
        </p>
        <p v-if="error" class="text-xs text-red-500">
            {{ error }}
        </p>
    </div>
</template>
