<script setup>
import { computed } from 'vue';
import { Search, X } from 'lucide-vue-next';
import { cn } from '@/lib/utils';

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    },
    placeholder: {
        type: String,
        default: 'Search...'
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v)
    },
    disabled: Boolean,
    clearable: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['update:modelValue', 'clear']);

const sizeClasses = computed(() => ({
    'h-8 text-xs': props.size === 'sm',
    'h-10 text-sm': props.size === 'md',
    'h-12 text-base': props.size === 'lg'
}));

const iconSizeClasses = computed(() => ({
    'w-3.5 h-3.5': props.size === 'sm',
    'w-4 h-4': props.size === 'md',
    'w-5 h-5': props.size === 'lg'
}));

const paddingClasses = computed(() => ({
    'pl-9': props.size === 'sm',
    'pl-10': props.size === 'md',
    'pl-12': props.size === 'lg'
}));

const handleClear = () => {
    emit('update:modelValue', '');
    emit('clear');
};
</script>

<template>
    <div class="relative">
        <Search
            :class="cn(
                'absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] pointer-events-none',
                iconSizeClasses
            )"
        />
        <input
            :value="modelValue"
            @input="$emit('update:modelValue', $event.target.value)"
            type="text"
            :placeholder="placeholder"
            :disabled="disabled"
            :class="cn(
                'w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-[var(--text-primary)]',
                'placeholder:text-[var(--text-muted)]',
                'hover:border-[var(--border-strong)]',
                'focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 focus:border-[var(--interactive-primary)]',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'transition-all duration-150',
                sizeClasses,
                paddingClasses,
                clearable && modelValue ? 'pr-9' : 'pr-3'
            )"
        />
        <button
            v-if="clearable && modelValue"
            type="button"
            @click="handleClear"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
        >
            <X :class="iconSizeClasses" />
        </button>
    </div>
</template>
