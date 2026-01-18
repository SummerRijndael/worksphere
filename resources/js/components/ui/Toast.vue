<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { X, CheckCircle, AlertCircle, AlertTriangle, Info } from 'lucide-vue-next';

const props = defineProps({
    id: [String, Number],
    type: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'success', 'error', 'warning', 'info'].includes(v),
    },
    title: String,
    message: String,
    duration: {
        type: Number,
        default: 5000,
    },
    closable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close']);

const icons = {
    success: CheckCircle,
    error: AlertCircle,
    warning: AlertTriangle,
    info: Info,
    default: Info,
};

const iconClasses = {
    success: 'text-green-500',
    error: 'text-red-500',
    warning: 'text-yellow-500',
    info: 'text-blue-500',
    default: 'text-[var(--text-muted)]',
};

const toastClasses = computed(() =>
    cn(
        'pointer-events-auto relative flex w-full items-start gap-3 overflow-hidden rounded-xl p-4',
        'bg-[var(--surface-elevated)] border shadow-lg',
        {
            'border-[var(--border-default)]': props.type === 'default',
            'border-green-200 dark:border-green-800': props.type === 'success',
            'border-red-200 dark:border-red-800': props.type === 'error',
            'border-yellow-200 dark:border-yellow-800': props.type === 'warning',
            'border-blue-200 dark:border-blue-800': props.type === 'info',
        }
    )
);
</script>

<template>
    <div :class="toastClasses">
        <component
            :is="icons[type]"
            :class="['h-5 w-5 shrink-0 mt-0.5', iconClasses[type]]"
        />
        <div class="flex-1 min-w-0">
            <p v-if="title" class="text-sm font-semibold text-[var(--text-primary)]">
                {{ title }}
            </p>
            <p v-if="message" :class="['text-sm', title ? 'text-[var(--text-secondary)] mt-0.5' : 'text-[var(--text-primary)]']">
                {{ message }}
            </p>
        </div>
        <button
            v-if="closable"
            class="shrink-0 p-1 rounded-lg text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] transition-colors"
            @click="emit('close')"
        >
            <X class="h-4 w-4" />
        </button>
    </div>
</template>
