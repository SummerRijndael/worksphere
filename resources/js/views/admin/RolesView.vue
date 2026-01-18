<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { Shield, Users, Clock, CheckCircle, XCircle, AlertTriangle, ChevronRight, MoreVertical, Plus, Search, Filter, Copy, Smartphone } from 'lucide-vue-next';
import { useRoles, useRoleChangeRequests, usePermissions } from '@/composables/usePermissions.ts';
import { useToast } from '@/composables/useToast.ts';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Card from '@/components/ui/Card.vue';
import Modal from '@/components/ui/Modal.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import ConfirmPasswordModal from '@/components/ui/ConfirmPasswordModal.vue';
import DuplicateRoleModal from '@/components/permissions/DuplicateRoleModal.vue';
import Role2FAEnforcementTab from '@/components/admin/Role2FAEnforcementTab.vue';

const route = useRoute();
const router = useRouter();
const toast = useToast();

// Composables
const { roles, loading: rolesLoading, fetchRoles, fetchStatistics, statistics } = useRoles();
const { requests, loading: requestsLoading, fetchPendingRequests, approveRequest, rejectRequest, fetchConfig, config } = useRoleChangeRequests();
const { permissions, fetchPermissions } = usePermissions();

// Tab management
const activeTab = ref(route.query.tab || 'roles');
const tabs = [
    { id: 'roles', label: 'Roles', icon: Shield },
    { id: 'permissions', label: 'Permissions', icon: Users },
    { id: 'approvals', label: 'Pending Approvals', icon: Clock },
    { id: '2fa', label: '2FA Enforcement', icon: Smartphone },
];

// Search
const searchQuery = ref('');

// Modals
const showApproveModal = ref(false);
const showRejectModal = ref(false);
const showDuplicateModal = ref(false);
const selectedRequest = ref(null);
const selectedRole = ref(null);
const rejectReason = ref('');
const approvalComment = ref('');
const actionLoading = ref(false);

// Filtered data
const filteredRoles = computed(() => {
    if (!searchQuery.value) return roles.value;
    const query = searchQuery.value.toLowerCase();
    return roles.value.filter(role =>
        role.name.toLowerCase().includes(query) ||
        role.label?.toLowerCase().includes(query)
    );
});

const filteredPermissions = computed(() => {
    if (!searchQuery.value) return permissions.value;
    const query = searchQuery.value.toLowerCase();
    return permissions.value.map(group => ({
        ...group,
        permissions: group.permissions.filter(p =>
            p.name.toLowerCase().includes(query) ||
            p.label?.toLowerCase().includes(query)
        ),
    })).filter(group => group.permissions.length > 0);
});

const pendingCount = computed(() => requests.value.length);

// Tab change handler
function setActiveTab(tabId) {
    activeTab.value = tabId;
    router.replace({ query: { ...route.query, tab: tabId } });
}

// Approval actions
function openApproveModal(request) {
    selectedRequest.value = request;
    approvalComment.value = '';
    showApproveModal.value = true;
}

function openRejectModal(request) {
    selectedRequest.value = request;
    rejectReason.value = '';
    showRejectModal.value = true;
}

async function handleApprove(password) {
    if (!selectedRequest.value) return;
    actionLoading.value = true;
    try {
        await approveRequest(selectedRequest.value.id, password, approvalComment.value);
        showApproveModal.value = false;
        await fetchPendingRequests();
    } catch (error) {
        // Error handled in composable
    } finally {
        actionLoading.value = false;
    }
}

async function handleReject(password) {
    if (!selectedRequest.value || rejectReason.value.length < 10) return;
    actionLoading.value = true;
    try {
        await rejectRequest(selectedRequest.value.id, password, rejectReason.value);
        showRejectModal.value = false;
        await fetchPendingRequests();
    } catch (error) {
        // Error handled in composable
    } finally {
        actionLoading.value = false;
    }
}

// Role Details actions
function openRoleDetails(role) {
    router.push(`/admin/roles/${role.id}`);
}

function openDuplicateModal(role) {
    selectedRole.value = role;
    showDuplicateModal.value = true;
}

function handleRoleSaved() {
    fetchRoles();
    fetchStatistics();
}

// Format helpers
function formatDate(dateString) {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function getRequestTypeColor(type) {
    const colors = {
        role_title_change: 'warning',
        role_permission_change: 'primary',
        role_create: 'success',
        role_delete: 'error',
    };
    return colors[type] || 'default';
}

// Watch for tab in URL
watch(() => route.query.tab, (newTab) => {
    if (newTab && tabs.some(t => t.id === newTab)) {
        activeTab.value = newTab;
    }
});

// Load initial data
onMounted(async () => {
    await Promise.all([
        fetchRoles(),
        fetchStatistics(),
        fetchPermissions(),
        fetchPendingRequests(),
        fetchConfig(),
    ]);
});
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">Roles & Permissions</h1>
                <p class="text-[var(--text-secondary)]">Manage system roles, permissions, and approval workflows.</p>
            </div>
            <div v-if="statistics" class="flex items-center gap-4 text-sm">
                <div class="flex items-center gap-2">
                    <Shield class="h-4 w-4 text-[var(--text-muted)]" />
                    <span class="text-[var(--text-secondary)]">{{ statistics.total_roles }} roles</span>
                </div>
                <div class="flex items-center gap-2">
                    <Users class="h-4 w-4 text-[var(--text-muted)]" />
                    <span class="text-[var(--text-secondary)]">{{ statistics.total_permissions }} permissions</span>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex items-center gap-1 p-1 bg-[var(--surface-secondary)] rounded-lg w-fit">
            <button
                v-for="tab in tabs"
                :key="tab.id"
                @click="setActiveTab(tab.id)"
                :class="[
                    'flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-all',
                    activeTab === tab.id
                        ? 'bg-[var(--surface-primary)] text-[var(--text-primary)] shadow-sm'
                        : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                ]"
            >
                <component :is="tab.icon" class="h-4 w-4" />
                {{ tab.label }}
                <Badge
                    v-if="tab.id === 'approvals' && pendingCount > 0"
                    variant="warning"
                    size="sm"
                >
                    {{ pendingCount }}
                </Badge>
            </button>
        </div>

        <!-- Search -->
        <div v-if="activeTab !== 'approvals'" class="max-w-md">
            <div class="relative">
                <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-muted)]" />
                <Input
                    v-model="searchQuery"
                    placeholder="Search..."
                    class="pl-10"
                />
            </div>
        </div>

        <!-- Roles Tab -->
        <div v-if="activeTab === 'roles'" class="space-y-4">
            <div v-if="rolesLoading" class="flex items-center justify-center py-12">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--color-primary-500)]"></div>
            </div>

            <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="role in filteredRoles"
                    :key="role.id"
                    class="p-5 rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] hover:border-[var(--border-hover)] transition-colors"
                >
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <div class="p-2 rounded-lg bg-[var(--color-primary-100)] dark:bg-[var(--color-primary-900)]/30">
                                <Shield class="h-5 w-5 text-[var(--color-primary-600)]" />
                            </div>
                            <div>
                                <h3 class="font-semibold text-[var(--text-primary)]">{{ role.label || role.name }}</h3>
                                <p class="text-xs text-[var(--text-muted)] font-mono">{{ role.name }}</p>
                            </div>
                        </div>
                        <Badge variant="outline" size="sm">
                            {{ role.users_count || 0 }} users
                        </Badge>
                    </div>

                    <p v-if="role.description" class="text-sm text-[var(--text-secondary)] mb-3 line-clamp-2">
                        {{ role.description }}
                    </p>

                    <div class="flex items-center justify-between pt-3 border-t border-[var(--border-default)]">
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ role.permissions_count || role.permissions?.length || 0 }} permissions
                        </span>
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ role.permissions_count || role.permissions?.length || 0 }} permissions
                        </span>
                        <div class="flex items-center gap-2">
                             <Button variant="ghost" size="sm" class="h-8 w-8 p-0" title="Duplicate Role" @click="openDuplicateModal(role)">
                                <Copy class="h-4 w-4" />
                            </Button>
                            <Button variant="ghost" size="sm" @click="openRoleDetails(role)">
                                View Details
                                <ChevronRight class="h-4 w-4 ml-1" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="!rolesLoading && filteredRoles.length === 0" class="text-center py-12 text-[var(--text-secondary)]">
                No roles found matching your search.
            </div>
        </div>

        <!-- Permissions Tab -->
        <div v-if="activeTab === 'permissions'" class="space-y-6">
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="group in filteredPermissions" :key="group.category" class="space-y-3">
                    <h3 class="text-sm font-semibold text-[var(--text-primary)] uppercase tracking-wider flex items-center gap-2">
                        <div class="w-1.5 h-1.5 rounded-full bg-[var(--color-primary-500)]"></div>
                        {{ group.label }}
                    </h3>
                    <div class="bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg overflow-hidden">
                        <div v-for="permission in group.permissions" :key="permission.name" class="p-3 border-b border-[var(--border-default)] last:border-0 hover:bg-[var(--surface-secondary)] transition-colors">
                            <div class="text-sm font-medium text-[var(--text-primary)]">{{ permission.label }}</div>
                            <div class="text-xs text-[var(--text-muted)] font-mono">{{ permission.name }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="filteredPermissions.length === 0" class="text-center py-12 text-[var(--text-secondary)]">
                No permissions found matching your search.
            </div>
        </div>

        <!-- Pending Approvals Tab -->
        <div v-if="activeTab === 'approvals'" class="space-y-4">
            <!-- Config Info -->
            <div class="flex items-center gap-4 p-4 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-default)]">
                <AlertTriangle class="h-5 w-5 text-[var(--color-warning-fg)]" />
                <p class="text-sm text-[var(--text-secondary)]">
                    Role changes require <strong class="text-[var(--text-primary)]">{{ config.required_approvals }}</strong> approvals
                    and expire after <strong class="text-[var(--text-primary)]">{{ config.request_expiry_days }}</strong> days.
                </p>
            </div>

            <div v-if="requestsLoading" class="flex items-center justify-center py-12">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--color-primary-500)]"></div>
            </div>

            <div v-else-if="requests.length === 0" class="text-center py-12">
                <CheckCircle class="h-12 w-12 text-[var(--color-success-fg)] mx-auto mb-4" />
                <p class="text-[var(--text-secondary)]">No pending approval requests.</p>
            </div>

            <div v-else class="space-y-4">
                <div
                    v-for="request in requests"
                    :key="request.id"
                    class="p-5 rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)]"
                >
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <Badge :variant="getRequestTypeColor(request.type)" size="sm">
                                {{ request.type_label }}
                            </Badge>
                            <StatusBadge :status="request.status" size="sm" />
                        </div>
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ formatDate(request.created_at) }}
                        </span>
                    </div>

                    <div class="space-y-2 mb-4">
                        <p class="text-sm text-[var(--text-primary)]">
                            <span class="text-[var(--text-secondary)]">Requested by:</span>
                            {{ request.requested_by?.name }}
                        </p>
                        <p v-if="request.target_role" class="text-sm text-[var(--text-primary)]">
                            <span class="text-[var(--text-secondary)]">Target Role:</span>
                            {{ request.target_role.name }}
                        </p>
                        <p class="text-sm text-[var(--text-secondary)]">{{ request.reason }}</p>
                    </div>

                    <!-- Approval Progress -->
                    <div class="flex items-center gap-2 mb-4">
                        <div class="flex-1 h-2 bg-[var(--surface-tertiary)] rounded-full overflow-hidden">
                            <div
                                class="h-full bg-[var(--color-primary-500)] transition-all"
                                :style="{ width: `${(request.current_approvals / request.required_approvals) * 100}%` }"
                            ></div>
                        </div>
                        <span class="text-xs text-[var(--text-muted)] whitespace-nowrap">
                            {{ request.current_approvals }} / {{ request.required_approvals }} approvals
                        </span>
                    </div>

                    <!-- Existing Approvals -->
                    <div v-if="request.approvals?.length > 0" class="mb-4 space-y-2">
                        <p class="text-xs font-medium text-[var(--text-secondary)] uppercase">Approvals:</p>
                        <div class="flex flex-wrap gap-2">
                            <div
                                v-for="approval in request.approvals"
                                :key="approval.id"
                                :class="[
                                    'flex items-center gap-2 px-2 py-1 rounded-full text-xs border',
                                    approval.action === 'approve'
                                        ? 'bg-[var(--color-success-bg)] text-[var(--color-success-fg)] border-[var(--color-success-bg)]'
                                        : 'bg-[var(--color-error-bg)] text-[var(--color-error-fg)] border-[var(--color-error-bg)]'
                                ]"
                            >
                                <component :is="approval.action === 'approve' ? CheckCircle : XCircle" class="h-3 w-3" />
                                {{ approval.admin?.name }}
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-3 pt-4 border-t border-[var(--border-default)]">
                        <Button
                            variant="primary"
                            size="sm"
                            @click="openApproveModal(request)"
                        >
                            <CheckCircle class="h-4 w-4 mr-1" />
                            Approve
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="text-[var(--color-error-fg)] border-[var(--color-error-fg)]/30 hover:bg-[var(--color-error-bg)]"
                            @click="openRejectModal(request)"
                        >
                            <XCircle class="h-4 w-4 mr-1" />
                            Reject
                        </Button>
                        <span class="text-xs text-[var(--text-muted)] ml-auto">
                            Expires {{ formatDate(request.expires_at) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2FA Enforcement Tab -->
        <div v-if="activeTab === '2fa'">
            <Role2FAEnforcementTab />
        </div>
    <!-- Approve Modal -->
    <ConfirmPasswordModal
        v-model:open="showApproveModal"
        title="Approve Request"
        description="Enter your password to approve this role change request."
        submit-text="Approve"
        submit-variant="primary"
        :loading="actionLoading"
        @confirm="handleApprove"
    >
        <div class="space-y-2">
            <label class="block text-sm font-medium text-[var(--text-primary)]">
                Comment (optional)
            </label>
            <textarea
                v-model="approvalComment"
                rows="2"
                placeholder="Add a comment..."
                class="w-full px-3 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] resize-none"
            />
        </div>
    </ConfirmPasswordModal>

    <!-- Reject Modal -->
    <Modal
        v-model:open="showRejectModal"
        title="Reject Request"
        description="Provide a reason for rejecting this request."
        size="md"
    >
        <div class="space-y-4">
            <div class="space-y-2">
                <label class="block text-sm font-medium text-[var(--text-primary)]">
                    Reason for Rejection <span class="text-[var(--color-error-fg)]">*</span>
                </label>
                <textarea
                    v-model="rejectReason"
                    rows="3"
                    placeholder="Explain why this request is being rejected (min 10 characters)..."
                    class="w-full px-3 py-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-primary)] placeholder-[var(--text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] resize-none"
                />
                <p class="text-xs text-[var(--text-muted)]">
                    {{ rejectReason.length }} / 10 characters minimum
                </p>
            </div>
        </div>

        <template #footer>
            <Button variant="ghost" @click="showRejectModal = false">
                Cancel
            </Button>
            <Button
                variant="danger"
                :disabled="rejectReason.length < 10"
                @click="() => {
                    showRejectModal = false;
                    // Open password modal for rejection
                    showApproveModal = true;
                }"
            >
                Continue
            </Button>
        </template>
    </Modal>

    <!-- Duplicate Modal -->
    <DuplicateRoleModal
        v-model:open="showDuplicateModal"
        :source-role="selectedRole"
        @saved="handleRoleSaved"
    />

    </div>
</template>
