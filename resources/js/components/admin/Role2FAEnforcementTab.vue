<script setup>
import { ref, onMounted } from 'vue';
import { Button, Card, Modal, Badge, Alert, Checkbox } from '@/components/ui';
import api from '@/lib/api';
import { toast } from 'vue-sonner';
import {
    Shield,
    ShieldCheck,
    ShieldOff,
    Smartphone,
    Key,
    Mail,
    RefreshCw,
} from 'lucide-vue-next';

// 2FA methods configuration
const twoFactorMethods = [
    { value: 'totp', label: 'Authenticator App', icon: Smartphone, description: 'Time-based one-time password apps like Google Authenticator' },
    { value: 'sms', label: 'SMS Verification', icon: Mail, description: 'One-time codes sent via text message' },
    { value: 'webauthn', label: 'Security Key', icon: Key, description: 'Hardware security keys or passkeys' },
];

// State
const roleEnforcements = ref([]);
const isLoadingRoles = ref(false);
const showEnforceModal = ref(false);
const selectedRoleId = ref(null);
const roleMethods = ref(['totp']);
const availableRoles = ref([]);
const isEnforcingRole = ref(false);

async function loadRoleEnforcements() {
    isLoadingRoles.value = true;
    try {
        const response = await api.get('/api/admin/2fa-enforcement/roles');
        roleEnforcements.value = response.data.enforcements || [];
    } catch (error) {
        toast.error('Failed to load role enforcements');
    } finally {
        isLoadingRoles.value = false;
    }
}

async function loadAvailableRoles() {
    try {
        const response = await api.get('/api/roles');
        availableRoles.value = response.data.data || [];
    } catch (error) {
        console.error('Failed to load roles:', error);
    }
}

function openEnforceModal() {
    loadAvailableRoles();
    selectedRoleId.value = null;
    roleMethods.value = ['totp'];
    showEnforceModal.value = true;
}

function toggleRoleMethod(method) {
    const index = roleMethods.value.indexOf(method);
    if (index === -1) {
        roleMethods.value.push(method);
    } else if (roleMethods.value.length > 1) {
        roleMethods.value.splice(index, 1);
    }
}

async function enforceForRole() {
    if (!selectedRoleId.value) {
        toast.error('Please select a role');
        return;
    }
    if (roleMethods.value.length === 0) {
        toast.error('Please select at least one 2FA method');
        return;
    }

    isEnforcingRole.value = true;
    try {
        const role = availableRoles.value.find(r => r.id === selectedRoleId.value);
        if (!role) {
            toast.error('Role not found');
            return;
        }
        await api.post('/api/admin/2fa-enforcement', {
            target_type: 'role',
            target_id: role.name,
            allowed_methods: roleMethods.value,
            enforce: true,
        });

        toast.success('2FA enforcement applied to role');
        showEnforceModal.value = false;
        loadRoleEnforcements();
    } catch (error) {
        toast.error(error.response?.data?.message || 'Failed to enforce 2FA for role');
    } finally {
        isEnforcingRole.value = false;
    }
}

async function removeRoleEnforcement(roleId, roleName) {
    if (!confirm('Remove 2FA enforcement for this role?')) return;

    try {
        await api.post('/api/admin/2fa-enforcement', {
            target_type: 'role',
            target_id: roleName,
            enforce: false,
        });
        toast.success('Role enforcement removed');
        loadRoleEnforcements();
    } catch (error) {
        toast.error('Failed to remove role enforcement');
    }
}

function getMethodLabel(methodValue) {
    const method = twoFactorMethods.find(m => m.value === methodValue);
    return method?.label || methodValue;
}

onMounted(() => {
    loadRoleEnforcements();
});
</script>

<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-[var(--text-primary)]">Role-Based 2FA Enforcement</h2>
                <p class="text-sm text-[var(--text-secondary)]">
                    Enforce two-factor authentication for all users with specific roles.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="ghost" size="sm" @click="loadRoleEnforcements" :loading="isLoadingRoles">
                    <RefreshCw class="w-4 h-4" />
                </Button>
                <Button variant="primary" @click="openEnforceModal">
                    <ShieldCheck class="w-4 h-4 mr-2" />
                    Add Role Enforcement
                </Button>
            </div>
        </div>

        <!-- Info Alert -->
        <Alert variant="info">
            <span>When 2FA is enforced for a role, all users with that role must complete 2FA setup before accessing the application.</span>
        </Alert>

        <!-- Loading State -->
        <div v-if="isLoadingRoles" class="flex items-center justify-center py-12">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--interactive-primary)]"></div>
        </div>

        <!-- Empty State -->
        <div v-else-if="roleEnforcements.length === 0" class="text-center py-12 bg-[var(--surface-secondary)] rounded-xl border border-[var(--border-muted)]">
            <ShieldOff class="w-12 h-12 text-[var(--text-muted)] mx-auto mb-4" />
            <h3 class="text-lg font-medium text-[var(--text-primary)] mb-2">No Role Enforcements</h3>
            <p class="text-sm text-[var(--text-secondary)] mb-4">
                No roles currently require 2FA. Add an enforcement to require 2FA for specific roles.
            </p>
            <Button variant="outline" @click="openEnforceModal">
                <ShieldCheck class="w-4 h-4 mr-2" />
                Add Your First Enforcement
            </Button>
        </div>

        <!-- Role Enforcements List -->
        <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Card
                v-for="enforcement in roleEnforcements"
                :key="enforcement.id"
                padding="md"
                class="relative"
            >
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-lg bg-[var(--interactive-primary)]/10">
                            <Shield class="w-5 h-5 text-[var(--interactive-primary)]" />
                        </div>
                        <div>
                            <h3 class="font-semibold text-[var(--text-primary)]">{{ enforcement.role?.name }}</h3>
                            <Badge :variant="enforcement.is_active ? 'success' : 'secondary'" size="sm">
                                {{ enforcement.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </div>
                    </div>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="text-[var(--text-muted)] hover:text-[var(--color-error)]"
                        @click="removeRoleEnforcement(enforcement.role_id, enforcement.role?.name)"
                        title="Remove Enforcement"
                    >
                        <ShieldOff class="w-4 h-4" />
                    </Button>
                </div>

                <div class="space-y-3">
                    <div>
                        <p class="text-xs text-[var(--text-muted)] mb-2">Allowed Methods:</p>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="method in enforcement.allowed_methods"
                                :key="method"
                                variant="outline"
                                size="sm"
                            >
                                {{ getMethodLabel(method) }}
                            </Badge>
                        </div>
                    </div>

                    <div v-if="enforcement.enforced_by_user" class="text-xs text-[var(--text-muted)]">
                        Enforced by: {{ enforcement.enforced_by_user.name }}
                    </div>
                </div>
            </Card>
        </div>

        <!-- Add Enforcement Modal -->
        <Modal
            :open="showEnforceModal"
            @update:open="showEnforceModal = $event"
            title="Enforce 2FA for Role"
            size="md"
        >
            <div class="space-y-4">
                <Alert variant="warning">
                    <span>All users with the selected role will be required to set up 2FA before accessing the system.</span>
                </Alert>

                <!-- Role Selection -->
                <div>
                    <label class="block text-sm font-medium text-[var(--text-primary)] mb-2">
                        Select Role <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="selectedRoleId"
                        class="w-full px-3 py-2 rounded-lg border border-[var(--border-primary)] bg-[var(--surface-primary)] text-[var(--text-primary)]"
                    >
                        <option :value="null">Select a role...</option>
                        <option
                            v-for="role in availableRoles"
                            :key="role.id"
                            :value="role.id"
                            :disabled="roleEnforcements.some(e => e.role_id === role.id)"
                        >
                            {{ role.name }}
                            {{ roleEnforcements.some(e => e.role_id === role.id) ? '(Already enforced)' : '' }}
                        </option>
                    </select>
                </div>

                <!-- Method Selection -->
                <div>
                    <label class="block text-sm font-medium text-[var(--text-primary)] mb-3">
                        Allowed 2FA Methods <span class="text-red-500">*</span>
                    </label>
                    <div class="space-y-2">
                        <div
                            v-for="method in twoFactorMethods"
                            :key="method.value"
                            class="flex items-start gap-3 p-3 rounded-lg border border-[var(--border-primary)] cursor-pointer hover:bg-[var(--surface-secondary)] transition-colors"
                            :class="{ 'bg-[var(--surface-secondary)] border-[var(--interactive-primary)]': roleMethods.includes(method.value) }"
                            @click="toggleRoleMethod(method.value)"
                        >
                            <Checkbox
                                :model-value="roleMethods.includes(method.value)"
                                @update:model-value="toggleRoleMethod(method.value)"
                            />
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <component :is="method.icon" class="w-4 h-4 text-[var(--text-secondary)]" />
                                    <span class="font-medium text-[var(--text-primary)]">{{ method.label }}</span>
                                </div>
                                <p class="text-xs text-[var(--text-muted)] mt-1">{{ method.description }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 pt-2">
                    <Button variant="outline" class="flex-1" @click="showEnforceModal = false">
                        Cancel
                    </Button>
                    <Button
                        class="flex-1"
                        :loading="isEnforcingRole"
                        :disabled="!selectedRoleId || roleMethods.length === 0"
                        @click="enforceForRole"
                    >
                        <ShieldCheck class="w-4 h-4 mr-2" />
                        Enforce 2FA
                    </Button>
                </div>
            </div>
        </Modal>
    </div>
</template>
