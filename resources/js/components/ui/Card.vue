<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps({
    padding: {
        type: String,
        default: 'md',
        validator: (v) => ['none', 'sm', 'md', 'lg', 'xl'].includes(v),
    },
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'ghost', 'outline'].includes(v),
    },
    hover: Boolean,
    clickable: Boolean,
});

const cardClasses = computed(() =>
    cn(
        'rounded-xl transition-all duration-200',

        // Variant styles
        {
            'bg-[var(--surface-elevated)] border border-[var(--border-subtle)] shadow-sm': props.variant === 'default',
            'bg-[var(--surface-secondary)]': props.variant === 'ghost',
            'border border-[var(--border-subtle)] bg-transparent': props.variant === 'outline',
        },

        // Padding
        {
            'p-0': props.padding === 'none',
            'p-3': props.padding === 'sm',
            'p-4': props.padding === 'md',
            'p-6': props.padding === 'lg',
            'p-8': props.padding === 'xl',
        },

        // Interactive states
        props.hover && 'hover:shadow-lg hover:border-[var(--border-strong)]',
        props.clickable && 'cursor-pointer active:scale-[0.99]'
    )
);
</script>

<template>
    <div :class="cardClasses">
        <slot />
    </div>
</template>
