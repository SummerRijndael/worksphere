<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import {
    CheckCircle,
    AlertCircle,
    AlertTriangle,
    Info,
    X,
} from 'lucide-vue-next';

const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'success', 'error', 'warning', 'info'].includes(v),
    },
    title: String,
    dismissible: Boolean,
    icon: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['dismiss']);

const iconMap = {
    default: Info,
    success: CheckCircle,
    error: AlertCircle,
    warning: AlertTriangle,
    info: Info,
};

const AlertIcon = computed(() => iconMap[props.variant]);

const classes = computed(() =>
    cn(
        'relative flex gap-3 rounded-xl border p-4',
        {
            // Default
            'border-[var(--border-default)] bg-[var(--surface-secondary)] text-[var(--text-primary)]':
                props.variant === 'default',

            // Success
            'border-green-300 bg-green-50 text-green-900 dark:border-green-700 dark:bg-green-900/20 dark:text-green-300':
                props.variant === 'success',

            // Error
            'border-red-300 bg-red-50 text-red-900 dark:border-red-700 dark:bg-red-900/20 dark:text-red-300':
                props.variant === 'error',

            // Warning
            'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-600 dark:bg-amber-900/20 dark:text-amber-300':
                props.variant === 'warning',

            // Info
            'border-blue-300 bg-blue-50 text-blue-900 dark:border-blue-700 dark:bg-blue-900/20 dark:text-blue-300':
                props.variant === 'info',
        }
    )
);

const iconClasses = computed(() =>
    cn(
        'h-5 w-5 shrink-0 mt-0.5',
        {
            'text-[var(--text-secondary)]': props.variant === 'default',
            'text-green-800 dark:text-green-400': props.variant === 'success',
            'text-red-800 dark:text-red-400': props.variant === 'error',
            'text-amber-800 dark:text-amber-400': props.variant === 'warning',
            'text-blue-800 dark:text-blue-400': props.variant === 'info',
        }
    )
);
</script>

<template>
    <div :class="classes" role="alert">
        <component v-if="icon" :is="AlertIcon" :class="iconClasses" />
        <div class="flex-1 min-w-0">
            <h5 v-if="title" class="font-semibold mb-1">{{ title }}</h5>
            <div class="text-sm">
                <slot />
            </div>
        </div>
        <button
            v-if="dismissible"
            class="shrink-0 p-1 rounded-lg opacity-60 hover:opacity-100 hover:bg-black/10 dark:hover:bg-white/10 transition-all"
            @click="emit('dismiss')"
        >
            <X class="h-4 w-4" />
        </button>
    </div>
</template>
