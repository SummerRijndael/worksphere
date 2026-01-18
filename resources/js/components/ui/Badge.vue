<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'primary', 'secondary', 'success', 'warning', 'error', 'danger', 'outline', 'neutral', 'info'].includes(v),
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['xs', 'sm', 'md', 'lg'].includes(v),
    },
    dot: Boolean,
});

const badgeClasses = computed(() =>
    cn(
        'inline-flex items-center gap-1.5 font-medium rounded-full whitespace-nowrap',

        // Size variants
        {
            'px-1.5 py-0.5 text-[10px]': props.size === 'xs',
            'px-2 py-0.5 text-xs': props.size === 'sm',
            'px-2.5 py-0.5 text-xs': props.size === 'md',
            'px-3 py-1 text-sm': props.size === 'lg',
        },

        // Variant styles - Maximum contrast for all modes
        {
            'bg-gray-200 text-gray-900 dark:bg-gray-800 dark:text-gray-300': props.variant === 'default',
            // Primary: blue background with black text for maximum readability
            'bg-blue-200 text-blue-900 dark:bg-blue-900/50 dark:text-blue-200': props.variant === 'primary',
            // Secondary: gray with black text
            'bg-gray-300 text-gray-950 dark:bg-gray-700 dark:text-gray-200': props.variant === 'secondary',
            // Success: green with black text
            'bg-emerald-200 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-200': props.variant === 'success',
            // Warning: amber with black text for maximum contrast
            'bg-amber-200 text-amber-900 dark:bg-amber-900/50 dark:text-amber-200': props.variant === 'warning',
            // Error/Danger: red with black text
            'bg-red-200 text-red-900 dark:bg-red-900/50 dark:text-red-200': props.variant === 'error' || props.variant === 'danger',
            // Neutral with border
            'bg-gray-100 text-gray-800 border border-gray-300 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700': props.variant === 'neutral',
            // Info: cyan with black text
            'bg-cyan-200 text-cyan-900 dark:bg-cyan-900/50 dark:text-cyan-200': props.variant === 'info',
            // Outline: transparent with border
            'border border-gray-400 bg-transparent text-gray-900 dark:border-gray-600 dark:text-gray-300': props.variant === 'outline',
        }
    )
);

const dotClasses = computed(() =>
    cn(
        'h-1.5 w-1.5 rounded-full',
        {
            'bg-[var(--text-muted)]': props.variant === 'default',
            'bg-[var(--color-primary-500)]': props.variant === 'primary',
            'bg-[var(--text-secondary)]': props.variant === 'secondary',
            'bg-green-500': props.variant === 'success',
            'bg-yellow-500': props.variant === 'warning',
            'bg-[var(--text-muted)]': props.variant === 'neutral',
            'bg-red-500': props.variant === 'error' || props.variant === 'danger',
            'bg-[var(--text-muted)]': props.variant === 'outline',
        }
    )
);
</script>

<template>
    <span :class="badgeClasses">
        <span v-if="dot" :class="dotClasses" />
        <slot />
    </span>
</template>
