<script setup>
import { ref, reactive, computed, watch } from "vue";
import { UserX, UserCheck, AlertCircle, Ban, Clock } from "lucide-vue-next";
import Modal from "../ui/Modal.vue";
import Input from "../ui/Input.vue";
import Button from "../ui/Button.vue";
import ConfirmPasswordModal from "../ui/ConfirmPasswordModal.vue";

const props = defineProps({
    open: Boolean,
    user: {
        type: Object,
        required: true,
    },
    loading: Boolean,
});

const emit = defineEmits(["update:open", "submit"]);

const form = reactive({
    new_status: "",
    reason: "",
});

const showPasswordModal = ref(false);

const statusOptions = computed(() => {
    const current = props.user?.status || "active";

    return [
        {
            value: "active",
            label: "Active",
            description: "User can log in and use the system",
            icon: UserCheck,
            color: "green",
            disabled: current === "active",
        },
        {
            value: "suspended",
            label: "Suspended",
            description: "Temporarily prevent access",
            icon: Clock,
            color: "amber",
            disabled: current === "suspended",
        },
        {
            value: "blocked",
            label: "Blocked",
            description: "Permanently prevent access",
            icon: Ban,
            color: "red",
            disabled: current === "blocked",
        },
    ];
});

const selectedStatus = computed(() => {
    return statusOptions.value.find((s) => s.value === form.new_status);
});

const isValid = computed(() => {
    return form.new_status && form.reason.length >= 10;
});

function handleSubmit() {
    if (!isValid.value) return;
    showPasswordModal.value = true;
}

function handlePasswordConfirm(password) {
    emit("submit", {
        user_id: props.user?.id,
        new_status: form.new_status,
        reason: form.reason,
        password,
    });
    showPasswordModal.value = false;
}

function resetForm() {
    form.new_status = "";
    form.reason = "";
}

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            resetForm();
        }
    }
);
</script>

<template>
    <Modal
        :open="open"
        title="Change User Status"
        :description="`Update status for ${user?.name}`"
        size="md"
        @update:open="$emit('update:open', $event)"
    >
        <div class="space-y-6">
            <!-- Current Status -->
            <div
                class="flex items-center gap-3 p-3 rounded-lg bg-[var(--surface-secondary)]"
            >
                <span class="text-sm text-[var(--text-secondary)]"
                    >Current Status:</span
                >
                <span
                    :class="[
                        'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium',
                        user?.status === 'active'
                            ? 'bg-green-100 text-green-900 dark:bg-green-900/30 dark:text-green-400'
                            : user?.status === 'suspended'
                            ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                            : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                    ]"
                >
                    {{ user?.status || "Unknown" }}
                </span>
            </div>

            <!-- Status Selection -->
            <div class="space-y-2">
                <label
                    class="block text-sm font-medium text-[var(--text-primary)]"
                >
                    New Status <span class="text-red-500">*</span>
                </label>
                <div class="space-y-2">
                    <button
                        v-for="option in statusOptions"
                        :key="option.value"
                        type="button"
                        :disabled="option.disabled"
                        @click="form.new_status = option.value"
                        :class="[
                            'w-full flex items-center gap-3 p-4 rounded-lg border-2 transition-all text-left',
                            option.disabled
                                ? 'opacity-50 cursor-not-allowed border-[var(--border-default)]'
                                : form.new_status === option.value
                                ? option.color === 'green'
                                    ? 'border-green-500 bg-green-50 dark:bg-green-900/20'
                                    : option.color === 'amber'
                                    ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20'
                                    : 'border-red-500 bg-red-50 dark:bg-red-900/20'
                                : 'border-[var(--border-default)] hover:border-[var(--border-hover)]',
                        ]"
                    >
                        <component
                            :is="option.icon"
                            :class="[
                                'h-5 w-5',
                                option.color === 'green'
                                    ? 'text-green-600'
                                    : option.color === 'amber'
                                    ? 'text-amber-600'
                                    : 'text-red-600',
                            ]"
                        />
                        <div>
                            <div class="font-medium text-[var(--text-primary)]">
                                {{ option.label }}
                                <span
                                    v-if="option.disabled"
                                    class="text-xs text-[var(--text-muted)]"
                                    >(current)</span
                                >
                            </div>
                            <div class="text-xs text-[var(--text-secondary)]">
                                {{ option.description }}
                            </div>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Reason -->
            <div class="space-y-2">
                <label
                    class="block text-sm font-medium text-[var(--text-primary)]"
                >
                    Reason <span class="text-red-500">*</span>
                </label>
                <textarea
                    v-model="form.reason"
                    rows="3"
                    placeholder="Provide a detailed reason for this status change (min 10 characters)..."
                    class="w-full px-3 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] resize-none"
                />
                <p class="text-xs text-[var(--text-muted)]">
                    {{ form.reason.length }} / 10 characters minimum
                </p>
            </div>

            <!-- Warning -->
            <div
                v-if="form.new_status && form.new_status !== 'active'"
                class="flex items-start gap-3 p-3 rounded-lg"
                :class="
                    form.new_status === 'blocked'
                        ? 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800'
                        : 'bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800'
                "
            >
                <AlertCircle
                    :class="[
                        'h-5 w-5 flex-shrink-0 mt-0.5',
                        form.new_status === 'blocked'
                            ? 'text-red-600 dark:text-red-400'
                            : 'text-amber-600 dark:text-amber-400',
                    ]"
                />
                <div
                    :class="[
                        'text-sm',
                        form.new_status === 'blocked'
                            ? 'text-red-700 dark:text-red-300'
                            : 'text-amber-700 dark:text-amber-300',
                    ]"
                >
                    <p v-if="form.new_status === 'blocked'">
                        <strong>This will immediately log out the user</strong>
                        and prevent them from accessing the system. All active
                        sessions will be terminated.
                    </p>
                    <p v-else>
                        <strong>This will temporarily restrict access.</strong>
                        The user will be logged out and cannot log back in until
                        reactivated.
                    </p>
                </div>
            </div>
        </div>

        <template #footer>
            <Button
                variant="ghost"
                @click="$emit('update:open', false)"
                :disabled="loading"
            >
                Cancel
            </Button>
            <Button
                :variant="
                    form.new_status === 'active'
                        ? 'primary'
                        : form.new_status === 'blocked'
                        ? 'danger'
                        : 'warning'
                "
                :loading="loading"
                :disabled="!isValid"
                @click="handleSubmit"
            >
                Change Status
            </Button>
        </template>
    </Modal>

    <!-- Password Confirmation -->
    <ConfirmPasswordModal
        v-model:open="showPasswordModal"
        title="Confirm Status Change"
        :description="`Enter your password to ${
            form.new_status === 'active' ? 'reactivate' : form.new_status
        } this user.`"
        :submit-text="`Confirm ${form.new_status}`"
        :submit-variant="form.new_status === 'blocked' ? 'danger' : 'primary'"
        :loading="loading"
        @confirm="handlePasswordConfirm"
    />
</template>
