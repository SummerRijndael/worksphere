<script setup>
import { computed } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
import { cn } from '@/lib/utils';

const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: ''
    },
    options: {
        type: Array,
        default: () => []
    },
    placeholder: {
        type: String,
        default: 'Select...'
    },
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v)
    },
    disabled: Boolean,
    valueKey: {
        type: String,
        default: 'value'
    },
    labelKey: {
        type: String,
        default: 'label'
    },
    showPlaceholder: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['update:modelValue']);

const sizeClasses = computed(() => ({
    'h-8 text-xs': props.size === 'sm',
    'h-9 text-sm': props.size === 'md',
    'h-10 text-sm': props.size === 'lg'
}));

const getOptionValue = (option) => {
    if (typeof option === 'object') {
        return option[props.valueKey];
    }
    return option;
};

const getOptionLabel = (option) => {
    if (typeof option === 'object') {
        return option[props.labelKey];
    }
    return option;
};
</script>

<template>
    <div class="relative">
        <select
            :value="modelValue"
            @change="$emit('update:modelValue', $event.target.value)"
            :disabled="disabled"
            :class="cn(
                'w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-[var(--text-primary)]',
                'hover:border-[var(--border-strong)]',
                'focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 focus:border-[var(--interactive-primary)]',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'transition-all duration-150',
                'appearance-none cursor-pointer',
                'pl-3 pr-9',
                sizeClasses
            )"
        >
            <option v-if="showPlaceholder" value="">{{ placeholder }}</option>
            <option
                v-for="option in options"
                :key="getOptionValue(option)"
                :value="getOptionValue(option)"
            >
                {{ getOptionLabel(option) }}
            </option>
        </select>
        <ChevronDown
            class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text-muted)] pointer-events-none"
        />
    </div>
</template>
