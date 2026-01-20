<script setup>
import { ref, computed } from 'vue';
import { Button, Card, Modal, Input, Badge, Alert, SelectFilter, Textarea } from '@/components/ui';
import api from '@/lib/api';
import { toast } from 'vue-sonner';
import {
    Shield,
    Ban,
    Clock,
    CheckCircle,
    AlertTriangle,
    Lock,
    UserCog,
    History,
    ChevronDown,
} from 'lucide-vue-next';
import { useRoles } from '@/composables/useRoles';

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['updated']);

// Status change state
const showStatusModal = ref(false);
const selectedStatus = ref('');
const statusReason = ref('');
const suspendedUntil = ref('');
const isUpdatingStatus = ref(false);

// Role change state
const showRoleModal = ref(false);
const selectedRole = ref('');
const roleReason = ref('');
const showPasswordConfirm = ref(false);
const adminPassword = ref('');
const isUpdatingRole = ref(false);

// History state
const showStatusHistory = ref(false);
const showRoleHistory = ref(false);
const statusHistory = ref([]);
const roleHistory = ref([]);
const isLoadingHistory = ref(false);

const statusOptions = [
    { value: 'active', label: 'Active', description: 'Full access to the system' },
    { value: 'suspended', label: 'Suspended', description: 'Temporarily blocked until date' },
    { value: 'blocked', label: 'Blocked', description: 'Permanently blocked' },
    { value: 'disabled', label: 'Disabled', description: 'Account is disabled' },
];

const { roleOptions, fetchRoles } = useRoles();

// Fetch roles if not already loaded
fetchRoles();

const statusBadgeVariant = computed(() => {
    const variants = {
        active: 'success',
        pending: 'warning',
        suspended: 'warning',
        blocked: 'error',
        disabled: 'secondary',
    };
    return variants[props.user.status] || 'secondary';
});

const currentRole = computed(() => props.user.roles?.[0]?.name || 'user');

const requiresReason = computed(() =>
    ['blocked', 'suspended'].includes(selectedStatus.value)
);

const requiresSuspendDate = computed(() =>
    selectedStatus.value === 'suspended'
);

function openStatusModal() {
    selectedStatus.value = props.user.status;
    statusReason.value = '';
    suspendedUntil.value = '';
    showStatusModal.value = true;
}

function openRoleModal() {
    selectedRole.value = currentRole.value;
    roleReason.value = '';
    showRoleModal.value = true;
}

async function updateStatus() {
    if (requiresReason.value && !statusReason.value) {
        toast.error('Please provide a reason');
        return;
    }
    if (requiresReason.value && statusReason.value.length < 10) {
        toast.error('Reason must be at least 10 characters');
        return;
    }
    if (requiresSuspendDate.value && !suspendedUntil.value) {
        toast.error('Please specify suspension end date');
        return;
    }

    isUpdatingStatus.value = true;
    try {
        await api.put(`/api/users/${props.user.public_id}/status`, {
            status: selectedStatus.value,
            reason: statusReason.value || undefined,
            suspended_until: suspendedUntil.value || undefined,
        });

        toast.success('User status updated');
        showStatusModal.value = false;
        emit('updated');
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to update status');
    } finally {
        isUpdatingStatus.value = false;
    }
}

function confirmRoleChange() {
    if (!roleReason.value) {
        toast.error('Please provide a reason');
        return;
    }
    if (roleReason.value.length < 10) {
        toast.error('Reason must be at least 10 characters');
        return;
    }
    showRoleModal.value = false;
    showPasswordConfirm.value = true;
}

async function updateRole() {
    if (!adminPassword.value) {
        toast.error('Please enter your password');
        return;
    }

    isUpdatingRole.value = true;
    try {
        await api.put(`/api/users/${props.user.public_id}/role`, {
            role: selectedRole.value,
            reason: roleReason.value,
            password: adminPassword.value,
        });

        toast.success('User role updated');
        showPasswordConfirm.value = false;
        adminPassword.value = '';
        emit('updated');
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to update role');
    } finally {
        isUpdatingRole.value = false;
    }
}

async function loadStatusHistory() {
    showStatusHistory.value = true;
    isLoadingHistory.value = true;
    try {
        const response = await api.get(`/api/users/${props.user.public_id}/status-history`);
        statusHistory.value = response.data.data || [];
    } catch (error) {
        toast.error('Failed to load history');
    } finally {
        isLoadingHistory.value = false;
    }
}

async function loadRoleHistory() {
    showRoleHistory.value = true;
    isLoadingHistory.value = true;
    try {
        const response = await api.get(`/api/users/${props.user.public_id}/role-history`);
        roleHistory.value = response.data.data || [];
    } catch (error) {
        toast.error('Failed to load history');
    } finally {
        isLoadingHistory.value = false;
    }
}

function formatDate(date) {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function getRoleLabel(role) {
    const option = roleOptions.value.find(r => r.value === role);
    return option?.label || role;
}

function getMinDateTime() {
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    return now.toISOString().slice(0, 16);
}
</script>

<template>
    <div class="space-y-4">
        <!-- Current Status Display -->
        <Card padding="md">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-[var(--text-muted)] mb-1">Account Status</p>
                    <div class="flex items-center gap-2">
                        <Badge :variant="statusBadgeVariant">
                            {{ user.status }}
                        </Badge>
                        <span v-if="user.status_reason" class="text-sm text-[var(--text-secondary)]">
                            - {{ user.status_reason }}
                        </span>
                    </div>
                    <p v-if="user.suspended_until" class="text-xs text-[var(--text-muted)] mt-1">
                        Until: {{ formatDate(user.suspended_until) }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="ghost" size="sm" @click="loadStatusHistory">
                        <History class="w-4 h-4" />
                    </Button>
                    <Button variant="outline" size="sm" @click="openStatusModal">
                        <UserCog class="w-4 h-4" />
                        Change Status
                    </Button>
                </div>
            </div>
        </Card>

        <!-- Current Role Display -->
        <Card padding="md">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-[var(--text-muted)] mb-1">Role</p>
                    <Badge variant="primary">
                        {{ getRoleLabel(currentRole) }}
                    </Badge>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="ghost" size="sm" @click="loadRoleHistory">
                        <History class="w-4 h-4" />
                    </Button>
                    <Button variant="outline" size="sm" @click="openRoleModal">
                        <Shield class="w-4 h-4" />
                        Change Role
                    </Button>
                </div>
            </div>
        </Card>

        <!-- Status Change Modal -->
        <Modal
            :open="showStatusModal"
            @update:open="showStatusModal = $event"
            title="Change User Status"
        >
            <div class="space-y-4">
                <SelectFilter
                    v-model="selectedStatus"
                    :options="statusOptions"
                    label="New Status"
                    placeholder="Select status..."
                />

                <div v-if="requiresReason">
                    <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">
                        Reason <span class="text-red-500">*</span>
                    </label>
                    <Textarea
                        v-model="statusReason"
                        rows="3"
                        placeholder="Explain why this action is being taken (min 10 characters)..."
                    />
                </div>

                <div v-if="requiresSuspendDate">
                    <Input
                        v-model="suspendedUntil"
                        type="datetime-local"
                        label="Suspend Until"
                        :min="getMinDateTime()"
                    />
                </div>

                <Alert v-if="['blocked', 'suspended'].includes(selectedStatus)" variant="warning">
                    <AlertTriangle class="w-4 h-4" />
                    <span>This will immediately log out the user and prevent them from accessing the system.</span>
                </Alert>

                <div class="flex gap-3 pt-2">
                    <Button variant="outline" class="flex-1" @click="showStatusModal = false">
                        Cancel
                    </Button>
                    <Button
                        class="flex-1"
                        :loading="isUpdatingStatus"
                        @click="updateStatus"
                    >
                        Update Status
                    </Button>
                </div>
            </div>
        </Modal>

        <!-- Role Change Modal -->
        <Modal
            :open="showRoleModal"
            @update:open="showRoleModal = $event"
            title="Change User Role"
        >
            <div class="space-y-4">
                <SelectFilter
                    v-model="selectedRole"
                    :options="roleOptions"
                    label="New Role"
                    placeholder="Select role..."
                />

                <div>
                    <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">
                        Reason <span class="text-red-500">*</span>
                    </label>
                    <Textarea
                        v-model="roleReason"
                        rows="3"
                        placeholder="Explain why this role change is being made (min 10 characters)..."
                    />
                </div>

                <Alert variant="info">
                    The user will be notified and asked to log out for changes to take effect.
                </Alert>

                <div class="flex gap-3 pt-2">
                    <Button variant="outline" class="flex-1" @click="showRoleModal = false">
                        Cancel
                    </Button>
                    <Button class="flex-1" @click="confirmRoleChange">
                        Continue
                    </Button>
                </div>
            </div>
        </Modal>

        <!-- Password Confirmation Modal -->
        <Modal
            :open="showPasswordConfirm"
            @update:open="showPasswordConfirm = $event; adminPassword = ''"
            title="Confirm Your Identity"
        >
            <div class="space-y-4">
                <p class="text-sm text-[var(--text-secondary)]">
                    Please enter your password to confirm this role change.
                </p>

                <Input
                    v-model="adminPassword"
                    type="password"
                    label="Your Password"
                    placeholder="Enter your password"
                    :icon="Lock"
                    @keyup.enter="updateRole"
                />

                <div class="flex gap-3 pt-2">
                    <Button
                        variant="outline"
                        class="flex-1"
                        @click="showPasswordConfirm = false; adminPassword = ''"
                    >
                        Cancel
                    </Button>
                    <Button
                        class="flex-1"
                        :loading="isUpdatingRole"
                        @click="updateRole"
                    >
                        Confirm & Update
                    </Button>
                </div>
            </div>
        </Modal>

        <!-- Status History Modal -->
        <Modal
            :open="showStatusHistory"
            @update:open="showStatusHistory = $event"
            title="Status Change History"
            size="lg"
        >
            <div v-if="isLoadingHistory" class="flex justify-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--interactive-primary)]"></div>
            </div>
            <div v-else-if="statusHistory.length === 0" class="text-center py-8 text-[var(--text-muted)]">
                No status changes recorded.
            </div>
            <div v-else class="space-y-3 max-h-96 overflow-y-auto">
                <div
                    v-for="entry in statusHistory"
                    :key="entry.id"
                    class="p-3 rounded-lg bg-[var(--surface-secondary)]"
                >
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <Badge variant="secondary" size="sm">{{ entry.from_status }}</Badge>
                            <span class="text-[var(--text-muted)]">→</span>
                            <Badge :variant="entry.to_status === 'active' ? 'success' : 'warning'" size="sm">
                                {{ entry.to_status }}
                            </Badge>
                        </div>
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ formatDate(entry.created_at) }}
                        </span>
                    </div>
                    <p v-if="entry.reason" class="text-sm text-[var(--text-secondary)]">
                        {{ entry.reason }}
                    </p>
                    <p v-if="entry.changed_by_user" class="text-xs text-[var(--text-muted)] mt-1">
                        By: {{ entry.changed_by_user.name }}
                    </p>
                </div>
            </div>
        </Modal>

        <!-- Role History Modal -->
        <Modal
            :open="showRoleHistory"
            @update:open="showRoleHistory = $event"
            title="Role Change History"
            size="lg"
        >
            <div v-if="isLoadingHistory" class="flex justify-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--interactive-primary)]"></div>
            </div>
            <div v-else-if="roleHistory.length === 0" class="text-center py-8 text-[var(--text-muted)]">
                No role changes recorded.
            </div>
            <div v-else class="space-y-3 max-h-96 overflow-y-auto">
                <div
                    v-for="entry in roleHistory"
                    :key="entry.id"
                    class="p-3 rounded-lg bg-[var(--surface-secondary)]"
                >
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <Badge variant="secondary" size="sm">{{ getRoleLabel(entry.from_role) }}</Badge>
                            <span class="text-[var(--text-muted)]">→</span>
                            <Badge variant="primary" size="sm">{{ getRoleLabel(entry.to_role) }}</Badge>
                        </div>
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ formatDate(entry.created_at) }}
                        </span>
                    </div>
                    <p v-if="entry.reason" class="text-sm text-[var(--text-secondary)]">
                        {{ entry.reason }}
                    </p>
                    <p v-if="entry.changed_by_user" class="text-xs text-[var(--text-muted)] mt-1">
                        By: {{ entry.changed_by_user.name }}
                    </p>
                </div>
            </div>
        </Modal>
    </div>
</template>
