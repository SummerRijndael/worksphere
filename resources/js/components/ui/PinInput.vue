<script setup lang="ts">
import { PinInputRoot, PinInputInput } from 'reka-ui';
import { computed } from 'vue';

const props = withDefaults(defineProps<{
    modelValue?: string;
    length?: number;
    disabled?: boolean;
    error?: string | boolean;
    placeholder?: string;
}>(), {
    modelValue: '',
    length: 6,
    disabled: false,
    placeholder: '○',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'complete', value: string): void;
}>();

// Convert single string to array for Reka UI
const internalValue = computed({
    get: () => props.modelValue ? props.modelValue.split('') : [],
    set: (val: string[]) => {
        emit('update:modelValue', val.join(''));
    }
});

function onComplete(value: string[]) {
    emit('complete', value.join(''));
}

const hasError = computed(() => !!props.error);
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <PinInputRoot
            v-model="internalValue"
            :placeholder="placeholder"
            type="number"
            otp
            :disabled="disabled"
            class="flex gap-2"
            @complete="onComplete"
        >
            <PinInputInput
                v-for="(_, index) in length"
                :key="index"
                :index="index"
                :class="[
                    'w-12 h-14 text-center text-2xl font-mono font-semibold',
                    'rounded-lg border-2 outline-none transition-all duration-200',
                    'bg-[var(--surface-secondary)]',
                    'focus:ring-2 focus:ring-[var(--interactive-primary)] focus:border-[var(--interactive-primary)]',
                    hasError 
                        ? 'border-[var(--state-error)] text-[var(--state-error)]' 
                        : 'border-[var(--border-default)] text-[var(--text-primary)]',
                    disabled && 'opacity-50 cursor-not-allowed',
                ]"
            />
        </PinInputRoot>
        <p v-if="typeof error === 'string' && error" class="text-sm text-[var(--state-error)]">
            {{ error }}
        </p>
    </div>
</template>
