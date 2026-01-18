<script setup>
import { ref, watch, computed } from "vue";
import { ShieldAlert, Eye, EyeOff } from "lucide-vue-next";
import Modal from "./Modal.vue";
import Input from "./Input.vue";
import Button from "./Button.vue";

const props = defineProps({
    open: Boolean,
    title: {
        type: String,
        default: "Confirm Action",
    },
    description: {
        type: String,
        default: "Please enter your password to confirm this action.",
    },
    loading: Boolean,
    submitText: {
        type: String,
        default: "Confirm",
    },
    submitVariant: {
        type: String,
        default: "primary",
    },
    externalError: {
        type: String,
        default: "",
    },
    showReason: Boolean,
});

const emit = defineEmits(["update:open", "confirm", "cancel"]);

const password = ref("");
const reason = ref("");
const showPassword = ref(false);
const internalError = ref("");

// Combined error - show external error or internal error
const displayError = computed(() => props.externalError || internalError.value);

function handleConfirm() {
    if (!password.value) {
        internalError.value = "Password is required";
        return;
    }
    if (props.showReason && !reason.value.trim()) {
        internalError.value = "Reason is required";
        return;
    }
    internalError.value = "";
    emit("confirm", password.value, reason.value);
}

function handleCancel() {
    emit("cancel");
    emit("update:open", false);
}

function handleClose() {
    password.value = "";
    reason.value = "";
    showPassword.value = false;
    internalError.value = "";
}

// Reset when modal closes
watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            handleClose();
        }
    }
);

// Clear internal error when typing
watch(password, () => {
    if (internalError.value) {
        internalError.value = "";
    }
});
</script>

<template>
    <Modal
        :open="open"
        :title="title"
        :description="description"
        size="sm"
        @update:open="$emit('update:open', $event)"
        @close="handleClose"
    >
        <div class="space-y-4">
            <div
                class="flex items-center gap-3 p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800"
            >
                <ShieldAlert
                    class="h-5 w-5 text-amber-600 dark:text-amber-400 flex-shrink-0"
                />
                <p class="text-sm text-amber-700 dark:text-amber-300">
                    This action requires password verification for security.
                </p>
            </div>

            <div class="space-y-2">
                <label
                    class="block text-sm font-medium text-[var(--text-primary)]"
                >
                    Your Password
                </label>
                <div class="relative">
                    <Input
                        v-model="password"
                        :type="showPassword ? 'text' : 'password'"
                        placeholder="Enter your password"
                        :class="{ 'border-red-500 dark:border-red-600': displayError }"
                        @keydown.enter="handleConfirm"
                        autofocus
                    />
                    <button
                        type="button"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] hover:text-[var(--text-secondary)]"
                        @click="showPassword = !showPassword"
                    >
                        <Eye v-if="!showPassword" class="h-4 w-4" />
                        <EyeOff v-else class="h-4 w-4" />
                    </button>
                </div>
                <p v-if="displayError" class="text-sm text-red-500 dark:text-red-400">{{ displayError }}</p>
            </div>

            <div v-if="showReason" class="space-y-2">
                <label class="block text-sm font-medium text-[var(--text-primary)]">
                    Reason <span class="text-red-500">*</span>
                </label>
                <textarea
                    v-model="reason"
                    rows="2"
                    class="w-full px-3 py-2.5 rounded-xl border bg-[var(--surface-secondary)] text-[var(--text-primary)] border-[var(--border-default)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)] focus:border-transparent resize-none text-sm placeholder:text-[var(--text-muted)]"
                    placeholder="Provide a reason for this action..."
                    @keydown.enter.stop
                ></textarea>
            </div>

            <!-- Additional content slot -->
            <slot />
        </div>

        <template #footer>
            <Button variant="ghost" @click="handleCancel" :disabled="loading">
                Cancel
            </Button>
            <Button
                :variant="submitVariant"
                :loading="loading"
                @click="handleConfirm"
            >
                {{ submitText }}
            </Button>
        </template>
    </Modal>
</template>
