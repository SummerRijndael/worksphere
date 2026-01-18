<script setup>
import { computed, ref } from 'vue';
import { cn } from '@/lib/utils';
import { Eye, EyeOff } from 'lucide-vue-next';

const props = defineProps({
    modelValue: [String, Number],
    type: {
        type: String,
        default: 'text',
    },
    placeholder: String,
    disabled: Boolean,
    error: String,
    label: String,
    hint: String,
    icon: [Object, Function],
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
});

const emit = defineEmits(['update:modelValue', 'focus', 'blur']);

const showPassword = ref(false);
const isFocused = ref(false);

const inputType = computed(() => {
    if (props.type === 'password') {
        return showPassword.value ? 'text' : 'password';
    }
    return props.type;
});

const wrapperClasses = computed(() => cn('relative'));

const inputClasses = computed(() =>
    cn(
        // Base styles
        'w-full rounded-lg border bg-[var(--surface-elevated)] text-[var(--text-primary)]',
        'transition-all duration-150 ease-out',
        'placeholder:text-[var(--text-muted)]',
        'focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 focus:border-[var(--interactive-primary)]',
        'disabled:cursor-not-allowed disabled:opacity-50',

        // Size variants
        {
            'h-8 px-3 text-sm': props.size === 'sm',
            'h-10 px-3.5 text-sm': props.size === 'md',
            'h-12 px-4 text-base': props.size === 'lg',
        },

        // Icon padding
        props.icon && {
            'pl-10': props.size === 'md',
            'pl-9': props.size === 'sm',
            'pl-11': props.size === 'lg',
        },

        // Password toggle padding
        props.type === 'password' && {
            'pr-10': props.size === 'md',
            'pr-9': props.size === 'sm',
            'pr-11': props.size === 'lg',
        },

        // State styles
        props.error
            ? 'border-[var(--color-error)] focus:ring-[var(--color-error)]/20 focus:border-[var(--color-error)]'
            : 'border-[var(--border-default)] hover:border-[var(--border-strong)]'
    )
);

function handleInput(e) {
    emit('update:modelValue', e.target.value);
}

function handleFocus(e) {
    isFocused.value = true;
    emit('focus', e);
}

function handleBlur(e) {
    isFocused.value = false;
    emit('blur', e);
}
</script>

<template>
    <div class="space-y-1.5">
        <label
            v-if="label"
            class="block text-sm font-medium text-[var(--text-primary)]"
        >
            {{ label }}
        </label>

        <div :class="wrapperClasses">
            <!-- Leading icon -->
            <div
                v-if="icon"
                class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)]"
            >
                <component :is="icon" class="h-4 w-4" />
            </div>

            <input
                :type="inputType"
                :value="modelValue"
                :placeholder="placeholder"
                :disabled="disabled"
                :class="inputClasses"
                @input="handleInput"
                @focus="handleFocus"
                @blur="handleBlur"
            />

            <!-- Password toggle -->
            <button
                v-if="type === 'password'"
                type="button"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] hover:text-[var(--text-secondary)] transition-colors"
                tabindex="-1"
                @click="showPassword = !showPassword"
            >
                <EyeOff v-if="showPassword" class="h-4 w-4" />
                <Eye v-else class="h-4 w-4" />
            </button>
        </div>

        <!-- Error message -->
        <p v-if="error" class="text-sm text-[var(--color-error)]">
            {{ error }}
        </p>

        <!-- Hint text -->
        <p v-else-if="hint" class="text-sm text-[var(--text-muted)]">
            {{ hint }}
        </p>
    </div>
</template>
