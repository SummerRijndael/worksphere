<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (v) => ['primary', 'secondary', 'outline', 'ghost', 'danger', 'link', 'success'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['xs', 'sm', 'md', 'lg', 'xl', 'icon', 'icon-sm', 'icon-xs'].includes(v),
    },
    loading: Boolean,
    disabled: Boolean,
    fullWidth: Boolean,
    as: {
        type: String,
        default: 'button',
    },
});

const emit = defineEmits(['click']);

const classes = computed(() =>
    cn(
        // Base styles
        'inline-flex items-center justify-center gap-2 font-medium transition-all duration-150',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2',
        'disabled:pointer-events-none disabled:opacity-50',
        'rounded-lg whitespace-nowrap cursor-pointer',

        // Size variants
        {
            'h-7 px-2 text-xs': props.size === 'xs',
            'h-8 px-3 text-sm': props.size === 'sm',
            'h-10 px-4 text-sm': props.size === 'md',
            'h-11 px-6 text-base': props.size === 'lg',
            'h-12 px-8 text-base': props.size === 'xl',
            'h-10 w-10 p-0': props.size === 'icon',
            'h-8 w-8 p-0': props.size === 'icon-sm',
            'h-6 w-6 p-0': props.size === 'icon-xs',
        },

        // Variant styles
        {
            // Primary
            'bg-[var(--interactive-primary)] text-white hover:bg-[var(--interactive-primary-hover)] active:bg-[var(--interactive-primary-active)] focus-visible:ring-[var(--interactive-primary)]':
                props.variant === 'primary',

            // Secondary
            'bg-[var(--surface-tertiary)] text-[var(--text-primary)] hover:bg-[var(--border-strong)] focus-visible:ring-[var(--border-strong)]':
                props.variant === 'secondary',

            // Outline
            'border border-[var(--border-default)] bg-transparent text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] focus-visible:ring-[var(--border-default)]':
                props.variant === 'outline',

            // Ghost
            'bg-transparent text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)] hover:text-[var(--text-primary)] focus-visible:ring-[var(--border-default)]':
                props.variant === 'ghost',

            // Danger
            'bg-[var(--color-error)] text-white hover:opacity-90 focus-visible:ring-[var(--color-error)]':
                props.variant === 'danger',

            // Link
            'bg-transparent text-[var(--interactive-primary)] underline-offset-4 hover:underline p-0 h-auto':
                props.variant === 'link',

            // Success
            'bg-emerald-600 text-white hover:bg-emerald-700 active:bg-emerald-800 focus-visible:ring-emerald-600':
                props.variant === 'success',
        },

        // Full width
        props.fullWidth && 'w-full',

        // Loading state
        props.loading && 'cursor-wait'
    )
);

function handleClick(e) {
    if (!props.loading && !props.disabled) {
        emit('click', e);
    }
}
</script>

<template>
    <component
        :is="as"
        :type="as === 'button' ? 'button' : undefined"
        :class="classes"
        :disabled="disabled || loading"
        @click="handleClick"
    >
        <svg
            v-if="loading"
            class="h-4 w-4 animate-spin shrink-0"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
        >
            <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
            />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>
        <slot v-if="!loading || size === 'icon'" />
    </component>
</template>
