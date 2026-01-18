<script setup>
import { computed } from "vue";
import { SwitchRoot, SwitchThumb } from "reka-ui";
import { cn } from "@/lib/utils";

const props = defineProps({
    modelValue: Boolean,
    disabled: Boolean,
    label: String,
    description: String,
    size: {
        type: String,
        default: "md",
        validator: (v) => ["sm", "md", "lg"].includes(v),
    },
});

const emit = defineEmits(["update:modelValue"]);

// Use a computed with getter/setter for proper v-model binding
const checked = computed({
    get: () => props.modelValue,
    set: (value) => emit("update:modelValue", value),
});

const switchClasses = computed(() =>
    cn(
        "relative inline-flex shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent",
        "transition-colors duration-200 ease-in-out",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--interactive-primary)]/20",
        "disabled:cursor-not-allowed disabled:opacity-50",

        // Size variants
        {
            "h-5 w-9": props.size === "sm",
            "h-6 w-11": props.size === "md",
            "h-7 w-14": props.size === "lg",
        },

        // State
        checked.value
            ? "bg-[var(--interactive-primary)]"
            : "bg-[var(--surface-tertiary)]"
    )
);

const thumbClasses = computed(() =>
    cn(
        "pointer-events-none block rounded-full bg-white shadow-lg ring-0 transition-transform duration-200",

        // Size variants
        {
            "h-4 w-4": props.size === "sm",
            "h-5 w-5": props.size === "md",
            "h-6 w-6": props.size === "lg",
        },

        // Position
        checked.value
            ? {
                  "translate-x-4": props.size === "sm",
                  "translate-x-5": props.size === "md",
                  "translate-x-7": props.size === "lg",
              }
            : "translate-x-0"
    )
);
</script>

<template>
    <div :class="cn('flex items-center gap-3', label && 'justify-between')">
        <div v-if="label || description" class="space-y-0.5">
            <label
                v-if="label"
                :class="
                    cn(
                        'text-sm font-medium text-[var(--text-primary)]',
                        disabled && 'opacity-50'
                    )
                "
            >
                {{ label }}
            </label>
            <p v-if="description" class="text-sm text-[var(--text-muted)]">
                {{ description }}
            </p>
        </div>

        <SwitchRoot
            v-model="checked"
            :disabled="disabled"
            :class="switchClasses"
        >
            <SwitchThumb :class="thumbClasses" />
        </SwitchRoot>
    </div>
</template>
