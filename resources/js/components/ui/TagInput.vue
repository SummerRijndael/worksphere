<script setup>
import { ref, computed, nextTick } from 'vue';
import { cn } from '@/lib/utils';
import { X } from 'lucide-vue-next';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: 'Add tags...',
    },
    separator: {
        type: String,
        default: ',',
    },
    maxTags: {
        type: Number,
        default: null,
    },
    disabled: Boolean,
    label: String,
    hint: String,
    error: String,
});

const emit = defineEmits(['update:modelValue']);

const inputValue = ref('');
const inputRef = ref(null);
const isFocused = ref(false);

const tags = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value),
});

const canAddMore = computed(() => {
    if (!props.maxTags) return true;
    return tags.value.length < props.maxTags;
});

function addTag(value) {
    const trimmed = value.trim();
    if (!trimmed || !canAddMore.value) return false;
    if (tags.value.includes(trimmed)) return false;

    tags.value = [...tags.value, trimmed];
    return true;
}

function removeTag(index) {
    const newTags = [...tags.value];
    newTags.splice(index, 1);
    tags.value = newTags;
}

function removeLastTag() {
    if (tags.value.length > 0 && inputValue.value === '') {
        removeTag(tags.value.length - 1);
    }
}

function processInput(value) {
    if (value.includes(props.separator)) {
        const parts = value.split(props.separator);
        parts.forEach((part, i) => {
            if (i < parts.length - 1) {
                addTag(part);
            } else {
                inputValue.value = part;
            }
        });
    } else {
        inputValue.value = value;
    }
}

function handleInput(e) {
    processInput(e.target.value);
}

function handleKeydown(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        if (addTag(inputValue.value)) {
            inputValue.value = '';
        }
    } else if (e.key === 'Backspace') {
        removeLastTag();
    }
}

function handlePaste(e) {
    e.preventDefault();
    const pastedText = e.clipboardData.getData('text');
    const parts = pastedText.split(props.separator);

    parts.forEach((part, i) => {
        if (i < parts.length - 1) {
            addTag(part);
        } else {
            inputValue.value += part;
        }
    });
}

function handleBlur() {
    isFocused.value = false;
    if (inputValue.value.trim()) {
        addTag(inputValue.value);
        inputValue.value = '';
    }
}

function focusInput() {
    if (!props.disabled) {
        inputRef.value?.focus();
    }
}

const containerClasses = computed(() =>
    cn(
        'flex flex-wrap gap-2 min-h-[44px] rounded-xl border px-3 py-2 cursor-text transition-all',
        'bg-[var(--surface-elevated)]',
        props.disabled && 'opacity-50 cursor-not-allowed bg-[var(--surface-secondary)]',
        props.error
            ? 'border-[var(--color-error)] focus-within:ring-2 focus-within:ring-[var(--color-error)]/20'
            : isFocused.value
                ? 'border-[var(--interactive-primary)] ring-2 ring-[var(--interactive-primary)]/20'
                : 'border-[var(--border-default)] hover:border-[var(--border-strong)]'
    )
);
</script>

<template>
    <div class="space-y-1.5">
        <label v-if="label" class="block text-sm font-medium text-[var(--text-primary)]">
            {{ label }}
        </label>

        <div :class="containerClasses" @click="focusInput">
            <!-- Tags -->
            <span
                v-for="(tag, index) in tags"
                :key="index"
                class="inline-flex items-center gap-1 rounded-lg bg-[var(--interactive-primary)] px-2.5 py-1 text-sm font-medium text-white"
            >
                <span class="max-w-[150px] truncate">{{ tag }}</span>
                <button
                    type="button"
                    class="shrink-0 rounded p-0.5 hover:bg-white/20 transition-colors"
                    :disabled="disabled"
                    @click.stop="removeTag(index)"
                >
                    <X class="h-3 w-3" />
                </button>
            </span>

            <!-- Input -->
            <input
                ref="inputRef"
                :value="inputValue"
                :placeholder="tags.length === 0 ? placeholder : ''"
                :disabled="disabled || !canAddMore"
                class="flex-1 min-w-[120px] bg-transparent text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] outline-none"
                @input="handleInput"
                @keydown="handleKeydown"
                @paste="handlePaste"
                @focus="isFocused = true"
                @blur="handleBlur"
            />
        </div>

        <p v-if="hint && !error" class="text-xs text-[var(--text-muted)]">
            {{ hint }}
        </p>
        <p v-if="error" class="text-xs text-[var(--color-error)]">
            {{ error }}
        </p>
    </div>
</template>
