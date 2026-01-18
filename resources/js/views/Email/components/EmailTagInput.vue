<template>
    <div class="relative">
        <div
            class="flex flex-wrap items-center gap-1.5 min-h-[38px] px-2 py-1.5 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg focus-within:ring-2 focus-within:ring-[var(--interactive-primary)]/50 focus-within:border-[var(--interactive-primary)] transition-all cursor-text"
            :class="{ 'border-amber-500/50': warningMessage, 'border-red-500/50': errorMessage }"
            @click="focusInput"
        >
            <!-- Email Chips -->
            <TransitionGroup name="chip">
                <span
                    v-for="(email, index) in modelValue"
                    :key="email"
                    :title="email"
                    class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-md bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)] border border-[var(--interactive-primary)]/20 cursor-default"
                >
                    <span class="max-w-[150px] truncate">{{ email }}</span>
                    <button
                        @click.stop="removeEmail(index)"
                        class="p-0.5 rounded hover:bg-[var(--interactive-primary)]/20 transition-colors"
                        type="button"
                        title="Remove"
                    >
                        <XIcon class="w-3 h-3" />
                    </button>
                </span>
            </TransitionGroup>

            <!-- Input -->
            <input
                ref="inputRef"
                v-model="inputValue"
                type="text"
                class="flex-1 min-w-[120px] bg-transparent text-sm text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none"
                :placeholder="modelValue.length === 0 ? placeholder : ''"
                :disabled="modelValue.length >= MAX_EMAILS"
                @keydown="handleKeydown"
                @paste="handlePaste"
                @blur="commitCurrentInput"
            />

            <!-- Clear All Button -->
            <button
                v-if="modelValue.length > 1"
                @click.stop="clearAll"
                class="p-1 rounded text-[var(--text-muted)] hover:text-[var(--color-error)] hover:bg-[var(--color-error)]/10 transition-colors"
                type="button"
                title="Clear all"
            >
                <XCircleIcon class="w-4 h-4" />
            </button>
        </div>

        <!-- Warning Message -->
        <Transition name="fade">
            <div 
                v-if="warningMessage" 
                class="absolute top-full left-0 mt-1 px-2 py-1 text-xs font-medium text-amber-600 bg-amber-500/10 border border-amber-500/20 rounded-md z-10"
            >
                {{ warningMessage }}
            </div>
        </Transition>

        <!-- Error Message -->
        <Transition name="fade">
            <div 
                v-if="errorMessage" 
                class="absolute top-full left-0 mt-1 px-2 py-1 text-xs font-medium text-red-500 bg-red-500/10 border border-red-500/20 rounded-md z-10"
            >
                {{ errorMessage }}
            </div>
        </Transition>
    </div>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { XIcon, XCircleIcon } from "lucide-vue-next";

const MAX_EMAILS = 10;
const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const props = withDefaults(
    defineProps<{
        modelValue: string[];
        placeholder?: string;
    }>(),
    {
        placeholder: "Enter email addresses",
    }
);

const emit = defineEmits<{
    "update:modelValue": [emails: string[]];
}>();

const inputRef = ref<HTMLInputElement | null>(null);
const inputValue = ref("");
const warningMessage = ref("");
const errorMessage = ref("");

function focusInput() {
    inputRef.value?.focus();
}

function showWarning(message: string) {
    warningMessage.value = message;
    errorMessage.value = "";
    setTimeout(() => {
        warningMessage.value = "";
    }, 3000);
}

function showError(message: string) {
    errorMessage.value = message;
    warningMessage.value = "";
    setTimeout(() => {
        errorMessage.value = "";
    }, 3000);
}

function isValidEmail(email: string): boolean {
    return EMAIL_REGEX.test(email.trim());
}

function addEmails(emailList: string[]) {
    const newEmails: string[] = [];
    const invalidEmails: string[] = [];
    const currentEmails = new Set(props.modelValue.map(e => e.toLowerCase()));
    const slotsAvailable = MAX_EMAILS - props.modelValue.length;
    
    let omittedCount = 0;
    
    for (const email of emailList) {
        const trimmed = email.trim().toLowerCase();
        if (!trimmed) continue;
        
        // Validate email format
        if (!isValidEmail(trimmed)) {
            invalidEmails.push(trimmed);
            continue;
        }
        
        if (!currentEmails.has(trimmed) && !newEmails.includes(trimmed)) {
            if (newEmails.length < slotsAvailable) {
                newEmails.push(trimmed);
                currentEmails.add(trimmed);
            } else {
                omittedCount++;
            }
        }
    }
    
    if (newEmails.length > 0) {
        emit("update:modelValue", [...props.modelValue, ...newEmails]);
    }
    
    // Show appropriate message
    if (invalidEmails.length > 0 && omittedCount > 0) {
        showError(`${invalidEmails.length} invalid, ${omittedCount} omitted (max ${MAX_EMAILS})`);
    } else if (invalidEmails.length > 0) {
        showError(`${invalidEmails.length} invalid email(s) skipped`);
    } else if (omittedCount > 0) {
        showWarning(`${omittedCount} email(s) omitted (max ${MAX_EMAILS})`);
    }
}

function removeEmail(index: number) {
    const newEmails = [...props.modelValue];
    newEmails.splice(index, 1);
    emit("update:modelValue", newEmails);
    warningMessage.value = "";
    errorMessage.value = "";
}

function clearAll() {
    emit("update:modelValue", []);
    warningMessage.value = "";
    errorMessage.value = "";
}

function commitCurrentInput() {
    if (inputValue.value.trim()) {
        const emails = inputValue.value
            .split(/[,;\s]+/)
            .filter((e) => e.trim());
        addEmails(emails);
        inputValue.value = "";
    }
}

function handleKeydown(e: KeyboardEvent) {
    if (props.modelValue.length >= MAX_EMAILS) {
        if (e.key !== "Backspace" && e.key !== "Delete") {
            e.preventDefault();
            showWarning(`Maximum ${MAX_EMAILS} emails allowed`);
            return;
        }
    }
    
    if (
        e.key === "Enter" ||
        e.key === "," ||
        e.key === ";" ||
        e.key === " " ||
        e.key === "Tab"
    ) {
        if (inputValue.value.trim()) {
            e.preventDefault();
            commitCurrentInput();
        }
    } else if (
        e.key === "Backspace" &&
        !inputValue.value &&
        props.modelValue.length > 0
    ) {
        removeEmail(props.modelValue.length - 1);
    }
}

function handlePaste(e: ClipboardEvent) {
    e.preventDefault();
    const pastedText = e.clipboardData?.getData("text") || "";
    const emails = pastedText.split(/[,;\s\n\t]+/).filter((e) => e.trim());
    addEmails(emails);
}
</script>

<style scoped>
.chip-enter-active,
.chip-leave-active {
    transition: all 0.2s ease;
}
.chip-enter-from {
    opacity: 0;
    transform: scale(0.8);
}
.chip-leave-to {
    opacity: 0;
    transform: scale(0.8);
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
