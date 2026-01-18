<script setup>
import { ref, reactive, computed, watch } from "vue";
import {
    Shield,
    ShieldOff,
    Globe,
    Users,
    Calendar,
    Clock,
    AlertTriangle,
} from "lucide-vue-next";
import Modal from "../ui/Modal.vue";
import Input from "../ui/Input.vue";
import Button from "../ui/Button.vue";
import Switch from "../ui/Switch.vue";

const props = defineProps({
    open: Boolean,
    user: {
        type: Object,
        required: true,
    },
    teams: {
        type: Array,
        default: () => [],
    },
    permissions: {
        type: Array,
        default: () => [],
    },
    loading: Boolean,
});

const emit = defineEmits(["update:open", "submit"]);

const form = reactive({
    permission: "",
    type: "grant",
    scope: "global",
    team_id: null,
    is_temporary: false,
    expires_at: "",
    expiry_behavior: "auto_revoke",
    grace_period_days: 7,
    reason: "",
});

const errors = ref({});

const typeOptions = [
    {
        value: "grant",
        label: "Grant Permission",
        icon: Shield,
        description: "Allow the user to perform this action",
    },
    {
        value: "block",
        label: "Block Permission",
        icon: ShieldOff,
        description: "Prevent the user from this action",
    },
];

const scopeOptions = [
    {
        value: "global",
        label: "Global",
        icon: Globe,
        description: "Applies everywhere",
    },
    {
        value: "team",
        label: "Team-Scoped",
        icon: Users,
        description: "Applies to specific team",
    },
];

const expiryBehaviorOptions = [
    {
        value: "auto_revoke",
        label: "Auto Revoke",
        description: "Automatically remove when expired",
    },
    {
        value: "grace_period",
        label: "Grace Period",
        description: "Allow grace period before removal",
    },
];

const isValid = computed(() => {
    return (
        form.permission &&
        form.reason.length >= 10 &&
        (form.scope !== "team" || form.team_id) &&
        (!form.is_temporary || form.expires_at)
    );
});

const minDate = computed(() => {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    return tomorrow.toISOString().split("T")[0];
});

function handleSubmit() {
    if (!isValid.value) return;

    emit("submit", {
        permission: form.permission,
        type: form.type,
        scope: form.scope,
        team_id: form.team_id,
        is_temporary: form.is_temporary,
        expires_at: form.is_temporary ? form.expires_at : null,
        expiry_behavior: form.is_temporary ? form.expiry_behavior : null,
        grace_period_days:
            form.expiry_behavior === "grace_period"
                ? form.grace_period_days
                : null,
        reason: form.reason,
    });
}

function resetForm() {
    form.permission = "";
    form.type = "grant";
    form.scope = "global";
    form.team_id = null;
    form.is_temporary = false;
    form.expires_at = "";
    form.expiry_behavior = "auto_revoke";
    form.grace_period_days = 7;
    form.reason = "";
    errors.value = {};
}

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            resetForm();
        }
    }
);

watch(
    () => form.scope,
    (scope) => {
        if (scope === "global") {
            form.team_id = null;
        }
    }
);
</script>

<template>
    <Modal
        :open="open"
        title="Create Permission Override"
        :description="`Create a ${form.type} for ${user?.name}`"
        size="lg"
        @update:open="$emit('update:open', $event)"
    >
        <form @submit.prevent="handleSubmit" class="space-y-6">
            <!-- Permission Selection -->
            <div class="space-y-2">
                <label
                    class="block text-sm font-medium text-[var(--text-primary)]"
                >
                    Permission <span class="text-red-500">*</span>
                </label>
                <select
                    v-model="form.permission"
                    class="w-full px-3 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] focus:border-transparent"
                >
                    <option value="">Select a permission...</option>
                    <optgroup
                        v-for="group in permissions"
                        :key="group.category"
                        :label="group.label"
                    >
                        <option
                            v-for="perm in group.permissions"
                            :key="perm.name"
                            :value="perm.name"
                        >
                            {{ perm.label }} ({{ perm.name }})
                        </option>
                    </optgroup>
                </select>
            </div>

            <!-- Type Selection -->
            <div class="space-y-2">
                <label
                    class="block text-sm font-medium text-[var(--text-primary)]"
                >
                    Type <span class="text-red-500">*</span>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <button
                        v-for="option in typeOptions"
                        :key="option.value"
                        type="button"
                        @click="form.type = option.value"
                        :class="[
                            'flex items-center gap-3 p-4 rounded-lg border-2 transition-all text-left',
                            form.type === option.value
                                ? option.value === 'grant'
                                    ? 'border-green-500 bg-green-50 dark:bg-green-900/20'
                                    : 'border-red-500 bg-red-50 dark:bg-red-900/20'
                                : 'border-[var(--border-default)] hover:border-[var(--border-hover)]',
                        ]"
                    >
                        <component
                            :is="option.icon"
                            :class="[
                                'h-5 w-5',
                                form.type === option.value
                                    ? option.value === 'grant'
                                        ? 'text-green-600'
                                        : 'text-red-600'
                                    : 'text-[var(--text-muted)]',
                            ]"
                        />
                        <div>
                            <div class="font-medium text-[var(--text-primary)]">
                                {{ option.label }}
                            </div>
                            <div class="text-xs text-[var(--text-secondary)]">
                                {{ option.description }}
                            </div>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Scope Selection -->
            <div class="space-y-2">
                <label
                    class="block text-sm font-medium text-[var(--text-primary)]"
                >
                    Scope
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <button
                        v-for="option in scopeOptions"
                        :key="option.value"
                        type="button"
                        @click="form.scope = option.value"
                        :class="[
                            'flex items-center gap-3 p-3 rounded-lg border transition-all text-left',
                            form.scope === option.value
                                ? 'border-[var(--color-primary-500)] bg-[var(--color-primary-50)] dark:bg-[var(--color-primary-900)]/20'
                                : 'border-[var(--border-default)] hover:border-[var(--border-hover)]',
                        ]"
                    >
                        <component
                            :is="option.icon"
                            :class="[
                                'h-5 w-5',
                                form.scope === option.value
                                    ? 'text-[var(--color-primary-600)]'
                                    : 'text-[var(--text-muted)]',
                            ]"
                        />
                        <div>
                            <div class="font-medium text-[var(--text-primary)]">
                                {{ option.label }}
                            </div>
                            <div class="text-xs text-[var(--text-secondary)]">
                                {{ option.description }}
                            </div>
                        </div>
                    </button>
                </div>

                <!-- Team Selection (if scope is team) -->
                <div v-if="form.scope === 'team'" class="mt-3">
                    <select
                        v-model="form.team_id"
                        class="w-full px-3 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)]"
                    >
                        <option :value="null">Select a team...</option>
                        <option
                            v-for="team in teams"
                            :key="team.id"
                            :value="team.id"
                        >
                            {{ team.name }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Temporary Toggle -->
            <div
                class="flex items-center justify-between p-4 rounded-lg bg-[var(--surface-secondary)]"
            >
                <div class="flex items-center gap-3">
                    <Clock class="h-5 w-5 text-[var(--text-muted)]" />
                    <div>
                        <div class="font-medium text-[var(--text-primary)]">
                            Temporary Override
                        </div>
                        <div class="text-sm text-[var(--text-secondary)]">
                            Set an expiration date for this override
                        </div>
                    </div>
                </div>
                <Switch v-model="form.is_temporary" />
            </div>

            <!-- Expiry Options (if temporary) -->
            <div
                v-if="form.is_temporary"
                class="space-y-4 p-4 rounded-lg border border-[var(--border-default)]"
            >
                <div class="space-y-2">
                    <label
                        class="block text-sm font-medium text-[var(--text-primary)]"
                    >
                        Expires At <span class="text-red-500">*</span>
                    </label>
                    <Input
                        v-model="form.expires_at"
                        type="date"
                        :min="minDate"
                    />
                </div>

                <div class="space-y-2">
                    <label
                        class="block text-sm font-medium text-[var(--text-primary)]"
                    >
                        Expiry Behavior
                    </label>
                    <div class="space-y-2">
                        <label
                            v-for="option in expiryBehaviorOptions"
                            :key="option.value"
                            class="flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-all"
                            :class="
                                form.expiry_behavior === option.value
                                    ? 'border-[var(--color-primary-500)] bg-[var(--color-primary-50)] dark:bg-[var(--color-primary-900)]/20'
                                    : 'border-[var(--border-default)]'
                            "
                        >
                            <input
                                type="radio"
                                v-model="form.expiry_behavior"
                                :value="option.value"
                                class="h-4 w-4 text-[var(--color-primary-600)]"
                            />
                            <div>
                                <div
                                    class="font-medium text-[var(--text-primary)]"
                                >
                                    {{ option.label }}
                                </div>
                                <div
                                    class="text-xs text-[var(--text-secondary)]"
                                >
                                    {{ option.description }}
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <div
                    v-if="form.expiry_behavior === 'grace_period'"
                    class="space-y-2"
                >
                    <label
                        class="block text-sm font-medium text-[var(--text-primary)]"
                    >
                        Grace Period (days)
                    </label>
                    <Input
                        v-model.number="form.grace_period_days"
                        type="number"
                        min="1"
                        max="90"
                    />
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
                    placeholder="Provide a detailed reason for this permission override (min 10 characters)..."
                    class="w-full px-3 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] resize-none"
                />
                <p class="text-xs text-[var(--text-muted)]">
                    {{ form.reason.length }} / 10 characters minimum
                </p>
            </div>

            <!-- Warning for blocks -->
            <div
                v-if="form.type === 'block'"
                class="flex items-start gap-3 p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800"
            >
                <AlertTriangle
                    class="h-5 w-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5"
                />
                <p class="text-sm text-red-700 dark:text-red-300">
                    Blocking this permission will immediately prevent the user
                    from performing this action, even if their role normally
                    allows it.
                </p>
            </div>
        </form>

        <template #footer>
            <Button
                variant="ghost"
                @click="$emit('update:open', false)"
                :disabled="loading"
            >
                Cancel
            </Button>
            <Button
                :variant="form.type === 'grant' ? 'primary' : 'danger'"
                :loading="loading"
                :disabled="!isValid"
                @click="handleSubmit"
            >
                Create {{ form.type === "grant" ? "Grant" : "Block" }}
            </Button>
        </template>
    </Modal>
</template>
