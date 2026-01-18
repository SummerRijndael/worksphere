<script setup>
import { computed } from 'vue';
import { Check } from 'lucide-vue-next';
import { cn } from '@/lib/utils';

const props = defineProps({
    modelValue: Boolean,
    disabled: Boolean,
    label: String,
    description: String,
    id: String,
});

const emit = defineEmits(['update:modelValue']);

const checkboxId = computed(() => props.id || `checkbox-${Math.random().toString(36).slice(2, 9)}`);

function handleChange(event) {
    emit('update:modelValue', event.target.checked);
}
</script>

<template>
    <div class="flex items-start gap-3">
        <div class="relative">
            <input
                type="checkbox"
                :id="checkboxId"
                :checked="modelValue"
                :disabled="disabled"
                class="peer sr-only"
                @change="handleChange"
            />
            <div
                :class="cn(
                    'h-5 w-5 shrink-0 rounded-md border transition-all duration-150 flex items-center justify-center cursor-pointer',
                    'peer-focus-visible:outline-none peer-focus-visible:ring-2 peer-focus-visible:ring-[var(--interactive-primary)]/20',
                    'peer-disabled:cursor-not-allowed peer-disabled:opacity-50',
                    modelValue
                        ? 'bg-[var(--interactive-primary)] border-[var(--interactive-primary)]'
                        : 'border-[var(--border-default)] bg-[var(--surface-elevated)] hover:border-[var(--border-strong)]'
                )"
                @click="!disabled && emit('update:modelValue', !modelValue)"
            >
                <Check
                    v-if="modelValue"
                    class="h-3.5 w-3.5 text-white"
                    :stroke-width="3"
                />
            </div>
        </div>

        <div v-if="label || description || $slots.default" class="space-y-0.5">
            <label
                :for="checkboxId"
                :class="cn(
                    'text-sm font-medium text-[var(--text-primary)] cursor-pointer select-none',
                    disabled && 'cursor-not-allowed opacity-50'
                )"
            >
                <slot>{{ label }}</slot>
            </label>
            <p v-if="description" class="text-sm text-[var(--text-muted)]">
                {{ description }}
            </p>
        </div>
    </div>
</template>
