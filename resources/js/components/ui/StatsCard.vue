<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { ArrowUpRight, ArrowDownRight } from 'lucide-vue-next';

const props = defineProps({
    label: {
        type: String,
        required: true
    },
    value: {
        type: [String, Number],
        required: true
    },
    subValue: {
        type: String,
        default: ''
    },
    icon: {
        type: [Object, Function],
        default: null
    },
    trend: {
        type: Number,
        default: null
    },
    trendLabel: {
        type: String,
        default: ''
    },
    variant: {
        type: String,
        default: 'primary', // primary, success, warning, info
    }
});

const colorClasses = computed(() => {
    switch (props.variant) {
        case 'success':
            return 'bg-emerald-500/10 text-emerald-500 ring-emerald-500/20';
        case 'warning':
            return 'bg-orange-500/10 text-orange-500 ring-orange-500/20';
        case 'info':
            return 'bg-blue-500/10 text-blue-500 ring-blue-500/20';
        case 'primary':
        default:
            return 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)] ring-[var(--interactive-primary)]/20';
    }
});
</script>

<template>
    <div class="relative overflow-hidden rounded-xl border border-[var(--border-muted)] bg-[var(--surface-primary)] p-6 shadow-sm transition-all hover:shadow-md">
        <div class="flex items-center justify-between">
            <div class="space-y-1.5">
                <p class="text-sm font-medium text-[var(--text-secondary)]">{{ label }}</p>
                <div class="flex items-baseline gap-2">
                    <h3 class="text-2xl font-bold text-[var(--text-primary)] tracking-tight">
                        {{ value }}
                    </h3>
                    <span v-if="subValue" class="text-xs font-medium text-[var(--text-muted)]">
                        {{ subValue }}
                    </span>
                </div>
            </div>
            
            <div v-if="icon" 
                class="rounded-lg p-2.5 ring-1 flex items-center justify-center transition-colors"
                :class="colorClasses"
            >
                <component :is="icon" class="h-5 w-5" />
            </div>
        </div>

        <!-- Trend / Footer -->
        <div v-if="trend !== null || trendLabel" class="mt-4 flex items-center gap-2 text-xs">
            <span v-if="trend !== null" 
                class="flex items-center gap-0.5 font-medium"
                :class="trend >= 0 ? 'text-emerald-500' : 'text-red-500'"
            >
                <ArrowUpRight v-if="trend >= 0" class="h-3 w-3" />
                <ArrowDownRight v-else class="h-3 w-3" />
                {{ Math.abs(trend) }}%
            </span>
            <span class="text-[var(--text-muted)]">
                {{ trendLabel || 'vs last month' }}
            </span>
        </div>
    </div>
</template>
