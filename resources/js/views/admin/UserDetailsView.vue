<script setup>
import { ref, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '@/lib/api';
import { Button, Badge, Modal, SelectFilter } from '@/components/ui';
import { usePermissions } from '@/composables/usePermissions.ts';
import PermissionOverridesList from '@/components/permissions/PermissionOverridesList.vue';
import PermissionOverrideModal from '@/components/permissions/PermissionOverrideModal.vue';
import RevokePermissionModal from '@/components/permissions/RevokePermissionModal.vue';
import RenewPermissionModal from '@/components/permissions/RenewPermissionModal.vue';
import UserStatusPanel from '@/components/admin/UserStatusPanel.vue';
import TwoFactorEnforcementPanel from '@/components/admin/TwoFactorEnforcementPanel.vue';
import {
    User,
    Mail,
    MapPin,
    Calendar,
    Shield,
    Key,
    LogOut,
    History,
    ArrowLeft,
    CheckCircle2,
    XCircle,
    Smartphone,
    LayoutGrid,
    Lock,
    Monitor,
    Trash2,
    Eye,
    EyeOff,
    Github,
    Chrome,
    Facebook,
    Globe,
    LogIn,
    UserPlus,
    Edit,
    Download,
    Settings,
    AlertTriangle,
    CheckCircle,
    FileText,
    Loader2,
    ExternalLink,
    Plus
} from 'lucide-vue-next';
import { toast } from 'vue-sonner';

import { usePresence, getStatusColor, getStatusLabel } from '@/composables/usePresence';

const { presenceUsers, fetchUsersPresence } = usePresence({ manageLifecycle: false });

const route = useRoute();
const router = useRouter();
const userId = route.params.public_id;

const user = ref(null);
const auditLogs = ref([]);
const activeSessions = ref([]);
const selectedSessions = ref([]);
const isLoading = ref(true);
const isLogsLoading = ref(true);

// Computed Presence
const presenceStatus = computed(() => {
    if (!user.value) return 'offline';
    // Check real-time map first
    const realTimeUser = presenceUsers.value.get(user.value.public_id || userId);
    if (realTimeUser) return realTimeUser.status;
    
    return user.value.presence || 'offline';
});

// Audit logs pagination
const logsPerPage = ref(10);
const logsPagination = ref({
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 10,
    from: 0,
    to: 0
});
const isSessionsLoading = ref(true);
const isResettingPassword = ref(false);
const isResendingVerification = ref(false);
const isRevokingSessions = ref(false);

// Permission overrides state
const {
    overrides: userOverrides,
    loading: overridesLoading,
    permissions: availablePermissions,
    fetchUserOverrides,
    fetchPermissions,
    createOverride,
    revokeOverride,
    renewOverride
} = usePermissions();

const showOverrideModal = ref(false);
const showRevokeModal = ref(false);
const showRenewModal = ref(false);
const selectedOverride = ref(null);
const overrideActionLoading = ref(false);

// User's teams for scope selection
const userTeams = computed(() => user.value?.teams || []);
const maskEmail = ref(true);

const activeTab = ref('profile');

const tabs = [
    { id: 'profile', label: 'Profile', icon: User },
    { id: 'security', label: 'Security', icon: Lock },
    { id: 'access', label: 'Access & Roles', icon: Shield },
    { id: 'activity', label: 'Activity Logs', icon: History },
];

// Edit mode
const isEditing = ref(false);
const isSaving = ref(false);
const editForm = ref({
    name: '',
    username: '',
    phone: '',
    role: '',
    status: '',
    title: '',
    bio: '',
    location: '',
    website: '',
    skills: [],
});

// Init form when user data loads


// Get current user permissions from auth store
import { useAuthStore } from '@/stores/auth';
const authStore = useAuthStore();

const canEdit = () => authStore.hasPermission('users.update');
const canManageRoles = () => authStore.hasPermission('users.manage_roles');
const canManageStatus = () => authStore.hasPermission('users.manage_status');
const canDelete = () => authStore.hasPermission('users.delete');

const startEditing = () => {
    editForm.value = {
        name: user.value.name,
        username: user.value.username || '',
        email: user.value.email,
        phone: user.value.phone || '',
        role: user.value.roles?.[0]?.name || '',
        status: user.value.status,
        bio: user.value.bio || '',
        location: user.value.location || '',
        website: user.value.website || '',
        skills: user.value.skills || [],
    };
    isEditing.value = true;
};

const cancelEditing = () => {
    isEditing.value = false;
    editForm.value = { name: '', username: '', email: '', phone: '', role: '', status: '', bio: '', location: '', website: '' };
};

const saveUser = async () => {
    isSaving.value = true;
    try {
        const payload = {
            name: editForm.value.name,
            username: editForm.value.username,
            phone: editForm.value.phone,
            title: editForm.value.title,
            bio: editForm.value.bio,
            location: editForm.value.location,
            website: editForm.value.website,
            skills: editForm.value.skills,
        };
        
        // Only include role if user has permission and it changed
        if (canManageRoles() && editForm.value.role !== user.value.roles?.[0]?.name) {
            payload.role = editForm.value.role;
        }
        
        // Only include status if user has permission and it changed
        if (canManageStatus() && editForm.value.status !== user.value.status) {
            payload.status = editForm.value.status;
        }
        
        await api.put(`/api/users/${userId}`, payload);
        toast.success('User updated successfully');
        isEditing.value = false;
        await fetchUser();
    } catch (error) {
        console.error('Failed to update user:', error);
        toast.error(error.response?.data?.message || 'Failed to update user');
    } finally {
        isSaving.value = false;
    }
};


const fetchUser = async () => {
    isLoading.value = true;
    try {
        const response = await api.get(`/api/users/${userId}`);
        user.value = response.data;
        
        // Fetch real-time presence once we have the user
        if (user.value?.public_id) {
            fetchUsersPresence([user.value.public_id]);
        }
    } catch (error) {
        console.error('Failed to fetch user:', error);
        toast.error('Failed to load user details');
        router.push('/admin/users');
    } finally {
        isLoading.value = false;
    }
};

const fetchAuditLogs = async (page = 1) => {
    isLogsLoading.value = true;
    try {
        const response = await api.get(`/api/users/${userId}/audit-logs`, {
            params: {
                page,
                per_page: logsPerPage.value
            }
        });
        auditLogs.value = response.data.data || response.data;

        // Update pagination if meta is available
        if (response.data.meta) {
            logsPagination.value = {
                current_page: response.data.meta.current_page,
                last_page: response.data.meta.last_page,
                total: response.data.meta.total,
                per_page: response.data.meta.per_page,
                from: response.data.meta.from || 0,
                to: response.data.meta.to || 0
            };
        }
    } catch (error) {
        console.error('Failed to fetch audit logs:', error);
    } finally {
        isLogsLoading.value = false;
    }
};

const handleLogsPerPageChange = (value) => {
    logsPerPage.value = parseInt(value) || 10;
    fetchAuditLogs(1);
};

const sendPasswordReset = async () => {
    if (!confirm('Are you sure you want to send a password reset link to this user?')) return;
    
    isResettingPassword.value = true;
    try {
        await api.post(`/api/users/${userId}/password-reset`);
        toast.success('Password reset link sent successfully');
    } catch (error) {
        console.error('Failed to send reset link:', error);
        toast.error('Failed to send password reset link');
    } finally {
        isResettingPassword.value = false;
    }
};



const resendVerificationLink = async () => {
    isResendingVerification.value = true;
    try {
        await api.post(`/api/users/${userId}/email-verification`);
        toast.success('Verification link sent successfully');
    } catch (error) {
        console.error('Failed to send verification link:', error);
        toast.error('Failed to send verification link');
    } finally {
        isResendingVerification.value = false;
    }
};

const fetchSessions = async () => {
    isSessionsLoading.value = true;
    try {
        const response = await api.get(`/api/users/${userId}/sessions`);
        activeSessions.value = response.data;
    } catch (error) {
        console.error('Failed to fetch sessions:', error);
    } finally {
        isSessionsLoading.value = false;
    }
};

const revokeSessions = async (sessionIds = []) => {
    const isBulk = sessionIds.length > 0;
    const isAll = sessionIds.length === 0; // If no IDs passed, revoke all
    
    const message = isAll 
        ? 'Are you sure you want to revoke ALL active sessions? The user will be signed out everywhere.'
        : `Are you sure you want to revoke ${sessionIds.length} selected session(s)?`;

    if (!confirm(message)) return;

    isRevokingSessions.value = true;
    try {
        await api.delete(`/api/users/${userId}/sessions`, {
            data: { session_ids: isAll ? undefined : sessionIds }
        });
        toast.success(isAll ? 'All sessions revoked' : 'Selected sessions revoked');
        selectedSessions.value = [];
        await fetchSessions();
    } catch (error) {
        console.error('Failed to revoke sessions:', error);
        toast.error('Failed to revoke sessions');
    } finally {
        isRevokingSessions.value = false;
    }
};

const toggleSessionSelection = (sessionId) => {
    if (selectedSessions.value.includes(sessionId)) {
        selectedSessions.value = selectedSessions.value.filter(id => id !== sessionId);
    } else {
        selectedSessions.value = [...selectedSessions.value, sessionId];
    }
};

const toggleAllSessions = () => {
    if (selectedSessions.value.length === activeSessions.value.length) {
        selectedSessions.value = [];
    } else {
        selectedSessions.value = activeSessions.value.map(s => s.id);
    }
};

const getInitials = (name) => {
    if (!name) return '??';
    return name
        .split(' ')
        .map(word => word[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
};

// Activity Log helpers
const showLogDetailModal = ref(false);
const selectedLog = ref(null);

const actionIconMap = {
    'login': LogIn,
    'logout': LogOut,
    'login_failed': AlertTriangle,
    'password_reset': Key,
    'password_changed': Key,
    'email_verified': CheckCircle,
    'created': UserPlus,
    'updated': Edit,
    'deleted': Trash2,
    'role_assigned': Shield,
    'role_removed': Shield,
    'permission_granted': Key,
    'permission_revoked': Key,
    'data_exported': Download,
    'settings_changed': Settings,
    'mfa_enabled': Shield,
    'mfa_disabled': Shield,
    'session_revoked': LogOut,
    'account_locked': AlertTriangle,
    'account_unlocked': CheckCircle
};

const categoryColors = {
    'authentication': 'bg-blue-500/10 text-blue-600 border-blue-200 dark:border-blue-800 dark:text-blue-400',
    'authorization': 'bg-purple-500/10 text-purple-600 border-purple-200 dark:border-purple-800 dark:text-purple-400',
    'user_management': 'bg-green-500/10 text-green-600 border-green-200 dark:border-green-800 dark:text-green-400',
    'team_management': 'bg-cyan-500/10 text-cyan-600 border-cyan-200 dark:border-cyan-800 dark:text-cyan-400',
    'data_modification': 'bg-orange-500/10 text-orange-600 border-orange-200 dark:border-orange-800 dark:text-orange-400',
    'security': 'bg-red-500/10 text-red-600 border-red-200 dark:border-red-800 dark:text-red-400',
    'system': 'bg-gray-500/10 text-gray-600 border-gray-200 dark:border-gray-700 dark:text-gray-400',
    'api': 'bg-indigo-500/10 text-indigo-600 border-indigo-200 dark:border-indigo-800 dark:text-indigo-400'
};

const severityColors = {
    'debug': 'bg-gray-500/10 text-gray-500 border-gray-200 dark:border-gray-700',
    'info': 'bg-blue-500/10 text-blue-600 border-blue-200 dark:border-blue-800',
    'notice': 'bg-cyan-500/10 text-cyan-600 border-cyan-200 dark:border-cyan-800',
    'warning': 'bg-yellow-500/10 text-yellow-600 border-yellow-200 dark:border-yellow-800',
    'error': 'bg-red-500/10 text-red-600 border-red-200 dark:border-red-800',
    'critical': 'bg-red-600/20 text-red-700 border-red-300 dark:border-red-700 dark:text-red-400'
};

const getActionIcon = (action) => actionIconMap[action] || FileText;

const formatActionLabel = (action) => {
    if (!action) return '';
    return action.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
};

const viewLogDetails = (log) => {
    selectedLog.value = log;
    showLogDetailModal.value = true;
};

const viewAllLogs = () => {
    router.push({ path: '/system/logs', query: { user: userId } });
};

// Permission override handlers
const openOverrideModal = async () => {
    if (availablePermissions.value.length === 0) {
        await fetchPermissions();
    }
    showOverrideModal.value = true;
};

const handleCreateOverride = async (data) => {
    overrideActionLoading.value = true;
    try {
        await createOverride(userId, data);
        showOverrideModal.value = false;
        await fetchUserOverrides(userId);
    } catch (error) {
        // Error handled by composable
    } finally {
        overrideActionLoading.value = false;
    }
};

const openRevokeModal = (override) => {
    selectedOverride.value = override;
    showRevokeModal.value = true;
};

const handleRevokeOverride = async (data) => {
    overrideActionLoading.value = true;
    try {
        await revokeOverride(userId, selectedOverride.value.id, data.reason);
        showRevokeModal.value = false;
        selectedOverride.value = null;
        await fetchUserOverrides(userId);
    } catch (error) {
        // Error handled by composable
    } finally {
        overrideActionLoading.value = false;
    }
};

const openRenewModal = (override) => {
    selectedOverride.value = override;
    showRenewModal.value = true;
};

const handleRenewOverride = async (data) => {
    overrideActionLoading.value = true;
    try {
        await renewOverride(userId, selectedOverride.value.id, data);
        showRenewModal.value = false;
        selectedOverride.value = null;
        await fetchUserOverrides(userId);
    } catch (error) {
        // Error handled by composable
    } finally {
        overrideActionLoading.value = false;
    }
};

onMounted(async () => {
    await fetchUser();
    fetchAuditLogs();
    fetchSessions();
    fetchUserOverrides(userId);
});
</script>

<template>
    <div class="flex flex-col h-full w-full p-6 space-y-6">
        <!-- Back Navigation -->
        <div>
            <Button variant="ghost" size="sm" class="gap-2 text-[var(--text-secondary)] hover:text-[var(--text-primary)] pl-0 hover:bg-transparent" @click="router.push('/admin/users')">
                <ArrowLeft class="w-4 h-4" />
                Back to Users
            </Button>
        </div>

        <div v-if="isLoading" class="space-y-6">
            <div class="h-32 bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] animate-pulse"></div>
            <div class="h-10 w-64 bg-[var(--surface-primary)] rounded-lg animate-pulse"></div>
            <div class="h-64 bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] animate-pulse"></div>
        </div>

        <div v-else class="space-y-6">
            <!-- User Context Card -->
            <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] p-6">
                <div class="flex flex-col md:flex-row items-center md:items-start gap-4 md:gap-6">
                    <!-- Avatar -->
                    <div class="relative w-20 h-20 shrink-0">
                        <div class="w-20 h-20 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center text-3xl font-bold text-[var(--text-secondary)] border border-[var(--border-default)]">
                            {{ getInitials(user.name) }}
                        </div>
                        <div 
                            class="absolute bottom-0 right-0 w-5 h-5 rounded-full border-2 border-[var(--surface-primary)]"
                            :class="getStatusColor(presenceStatus)"
                            :title="getStatusLabel(presenceStatus)"
                        ></div>
                    </div>

                    <!-- Info -->
                    <div class="flex-1 text-center md:text-left space-y-2">
                        <div class="flex flex-col md:flex-row md:items-center gap-2 md:gap-4">
                            <h1 class="text-2xl font-bold text-[var(--text-primary)]">{{ user.name }}</h1>
                            <p class="text-[var(--text-secondary)] text-sm mb-1" v-if="user.title">{{ user.title }}</p>
                            <Badge 
                                variant="outline" 
                                :class="user.status === 'active' ? 'bg-green-100 text-green-900 border-green-200 dark:bg-green-900/30 dark:text-green-400 dark:border-green-800' : 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700'"
                            >
                                {{ user.status }}
                            </Badge>
                        </div>
                        
                        <div class="flex flex-wrap items-center justify-center md:justify-start gap-4 text-sm text-[var(--text-secondary)]">
                            <div class="flex items-center gap-1.5">
                                <span class="font-medium text-[var(--text-primary)]">@{{ user.username || 'username' }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <Mail class="w-4 h-4" />
                                <span>{{ user.email }}</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <Calendar class="w-4 h-4" />
                                <span>Joined {{ new Date(user.created_at).toLocaleDateString() }}</span>
                            </div>
                            <div class="flex items-center gap-1.5" v-if="user.location">
                                <component :is="MapPin" class="w-4 h-4" />
                                <span>{{ user.location }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="flex gap-2">
                        <template v-if="isEditing">
                            <Button variant="outline" @click="cancelEditing" :disabled="isSaving">
                                Cancel
                            </Button>
                            <Button variant="primary" @click="saveUser" :loading="isSaving">
                                Save Changes
                            </Button>
                        </template>
                        <template v-else>
                            <Button v-if="canEdit()" variant="outline" @click="startEditing">
                                Edit Profile
                            </Button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="flex items-center gap-1 border-b border-[var(--border-default)] overflow-x-auto">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    :class="[
                        'flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 transition-all whitespace-nowrap',
                        activeTab === tab.id
                            ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                            : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-muted)]'
                    ]"
                >
                    <component :is="tab.icon" class="w-4 h-4" />
                    {{ tab.label }}
                </button>
            </div>

            <!-- Tab Content -->
            <div class="min-h-[400px]">
                <!-- Profile Tab -->
                <div v-if="activeTab === 'profile'" class="w-full space-y-6">
                    <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden">
                        <div class="p-4 border-b border-[var(--border-muted)]">
                            <h3 class="font-medium text-[var(--text-primary)]">Personal Information</h3>
                        </div>
                        <div class="p-6 divide-y divide-[var(--border-muted)]">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3 first:pt-0">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Full Name</dt>
                                <dd class="text-sm text-[var(--text-primary)] md:col-span-2">
                                    <input v-if="isEditing" v-model="editForm.name" type="text" class="input w-full max-w-sm" placeholder="Full Name" />
                                    <span v-else>{{ user.name }}</span>
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Username</dt>
                                <dd class="text-sm text-[var(--text-primary)] md:col-span-2">
                                    <div v-if="isEditing" class="flex items-center gap-1">
                                        <span class="text-[var(--text-muted)]">@</span>
                                        <input v-model="editForm.username" type="text" class="input w-full max-w-sm" placeholder="username" />
                                    </div>
                                    <span v-else>@{{ user.username || '-' }}</span>
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Email Address</dt>
                                <dd class="text-sm text-[var(--text-primary)] md:col-span-2 flex items-center gap-2">
                                    <template v-if="isEditing">
                                        <div class="flex items-center gap-2 px-3 py-2 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)] max-w-sm">
                                            <Lock class="w-4 h-4 text-[var(--text-muted)]" />
                                            <span class="text-[var(--text-muted)]">{{ user.email }}</span>
                                        </div>
                                        <span class="text-xs text-[var(--text-muted)]" title="Email can only be changed by the user">Self-service only</span>
                                    </template>
                                    <template v-else>
                                        <span>{{ maskEmail ? user.email.replace(/(.{2})(.*)(@.*)/, '$1***$3') : user.email }}</span>
                                        <button @click="maskEmail = !maskEmail" class="text-[var(--text-muted)] hover:text-[var(--text-primary)] focus:outline-none">
                                            <component :is="maskEmail ? Eye : EyeOff" class="w-4 h-4" />
                                        </button>
                                    </template>
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Phone Number</dt>
                                <dd class="text-sm text-[var(--text-primary)] md:col-span-2">
                                    <input v-if="isEditing" v-model="editForm.phone" type="tel" class="input w-full max-w-sm" placeholder="Phone number" />
                                    <span v-else>{{ user.phone || '-' }}</span>
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Job Title</dt>
                                <dd class="text-sm text-[var(--text-primary)] md:col-span-2">
                                    <input v-if="isEditing" v-model="editForm.title" type="text" class="input w-full max-w-sm" placeholder="Job Title" />
                                    <span v-else>{{ user.title || '-' }}</span>
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Bio</dt>
                                <dd class="text-sm text-[var(--text-primary)] md:col-span-2">
                                    <textarea v-if="isEditing" v-model="editForm.bio" class="input w-full h-24 resize-none" placeholder="Short biography..."></textarea>
                                    <p v-else-if="user.bio" class="whitespace-pre-line">{{ user.bio }}</p>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Location</dt>
                                <dd class="text-sm text-[var(--text-primary)] md:col-span-2">
                                    <input v-if="isEditing" v-model="editForm.location" type="text" class="input w-full max-w-sm" placeholder="City, Country" />
                                    <span v-else>{{ user.location || '-' }}</span>
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Website</dt>
                                <dd class="text-sm text-[var(--text-primary)] md:col-span-2">
                                    <input v-if="isEditing" v-model="editForm.website" type="url" class="input w-full max-w-sm" placeholder="https://example.com" />
                                    <a v-else-if="user.website" :href="user.website" target="_blank" rel="noopener noreferrer" class="text-[var(--interactive-primary)] hover:underline">{{ user.website }}</a>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Skills</dt>
                                <dd class="text-sm text-[var(--text-primary)] md:col-span-2">
                                    <div v-if="isEditing" class="space-y-2">
                                        <div class="flex flex-wrap gap-2 mb-2" v-if="editForm.skills.length">
                                            <Badge v-for="(skill, index) in editForm.skills" :key="index" variant="neutral" class="pr-1 gap-1">
                                                {{ skill }}
                                                <button @click="editForm.skills.splice(index, 1)" class="hover:text-red-500"><XCircle class="w-3 h-3" /></button>
                                            </Badge>
                                        </div>
                                        <div class="flex gap-2 max-w-sm">
                                            <input 
                                                type="text" 
                                                class="input" 
                                                placeholder="Add skill (Enter)" 
                                                @keydown.enter.prevent="$event.target.value.trim() && (editForm.skills.push($event.target.value.trim()), $event.target.value = '')"
                                            />
                                        </div>
                                        <p class="text-xs text-[var(--text-muted)]">Press Enter to add tags</p>
                                    </div>
                                    <div v-else-if="user.skills && user.skills.length" class="flex flex-wrap gap-2">
                                        <Badge v-for="skill in user.skills" :key="skill" variant="neutral">{{ skill }}</Badge>
                                    </div>
                                    <span v-else>-</span>
                                </dd>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Email Verification</dt>
                                <dd class="text-sm md:col-span-2 flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <CheckCircle2 v-if="user.email_verified_at" class="w-4 h-4 text-green-500" />
                                        <XCircle v-else class="w-4 h-4 text-[var(--text-muted)]" />
                                        <span :class="user.email_verified_at ? 'text-green-600 dark:text-green-400' : 'text-[var(--text-muted)]'">
                                            {{ user.email_verified_at ? `Verified on ${new Date(user.email_verified_at).toLocaleDateString()}` : 'Not Verified' }}
                                        </span>
                                    </div>
                                    <Button 
                                        v-if="!user.email_verified_at" 
                                        variant="outline" 
                                        size="sm" 
                                        class="h-7 text-xs"
                                        @click="resendVerificationLink"
                                        :loading="isResendingVerification"
                                    >
                                        Resend Link
                                    </Button>
                                </dd>
                            </div>
                             <div class="grid grid-cols-1 md:grid-cols-3 gap-4 py-3 border-none pb-0">
                                <dt class="text-sm font-medium text-[var(--text-secondary)]">Account Created</dt>
                                <dd class="text-sm text-[var(--text-primary)] md:col-span-2">{{ new Date(user.created_at).toLocaleString() }}</dd>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Security Tab -->
                <div v-if="activeTab === 'security'" class="w-full space-y-6">
                    <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden">
                        <div class="p-4 border-b border-[var(--border-muted)] flex items-center gap-2">
                            <Shield class="w-4 h-4 text-[var(--text-primary)]" />
                            <h3 class="font-medium text-[var(--text-primary)]">Security Controls</h3>
                        </div>
                         <div class="p-6 divide-y divide-[var(--border-muted)]">
                            <!-- Password Info -->
                            <div class="flex items-start justify-between py-4 first:pt-0">
                                <div class="space-y-1">
                                    <h4 class="text-sm font-medium text-[var(--text-primary)] flex items-center gap-2">
                                        <Key class="w-4 h-4 text-[var(--text-secondary)]" /> Password
                                    </h4>
                                    <div class="flex flex-col gap-1">
                                        <div class="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                                            Status: 
                                            <Badge variant="outline" :class="user.is_password_set ? 'bg-green-500/10 text-green-600 border-green-200' : 'bg-yellow-500/10 text-yellow-600 border-yellow-200'">
                                                {{ user.is_password_set ? 'Set' : 'Not Set' }}
                                            </Badge>
                                        </div>
                                        <p v-if="user.password_last_updated_at" class="text-xs text-[var(--text-muted)]">
                                            Last changed: {{ new Date(user.password_last_updated_at).toLocaleDateString() }}
                                        </p>
                                    </div>
                                </div>
                                <Button variant="outline" size="sm" @click="sendPasswordReset" :loading="isResettingPassword">
                                    Send Reset Link
                                </Button>
                            </div>

                            <!-- Social Accounts -->
                            <div class="flex items-start justify-between py-4">
                                <div class="space-y-1">
                                    <h4 class="text-sm font-medium text-[var(--text-primary)] flex items-center gap-2">
                                        <Globe class="w-4 h-4 text-[var(--text-secondary)]" /> Connected Accounts
                                    </h4>
                                    <div class="flex flex-col gap-2 mt-2">
                                        <div v-if="user.social_accounts?.length" class="flex flex-col gap-2">
                                            <div v-for="account in user.social_accounts" :key="account.id" class="flex items-center gap-3 p-2 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]/50">
                                                <component 
                                                    :is="account.provider === 'github' ? Github : (account.provider === 'google' ? Chrome : Globe)" 
                                                    class="w-4 h-4 text-[var(--text-primary)]" 
                                                />
                                                <div class="flex flex-col">
                                                    <span class="text-sm font-medium text-[var(--text-primary)] capitalize">{{ account.provider }}</span>
                                                    <span class="text-xs text-[var(--text-muted)]">Connected {{ new Date(account.connected_at).toLocaleDateString() }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <p v-else class="text-sm text-[var(--text-muted)] italic">No social accounts connected.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Sessions -->
                             <div class="flex flex-col py-4 gap-4">
                                <div class="flex items-center justify-between">
                                    <div class="space-y-1">
                                        <h4 class="text-sm font-medium text-[var(--text-primary)] flex items-center gap-2">
                                            <LogOut class="w-4 h-4 text-[var(--text-secondary)]" /> Active Sessions
                                        </h4>
                                        <p class="text-sm text-[var(--text-secondary)]">Manage devices where this user is currently logged in.</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Button
                                            v-if="selectedSessions.length > 0"
                                            variant="danger"
                                            size="sm"
                                            @click="revokeSessions(selectedSessions)"
                                            :loading="isRevokingSessions"
                                        >
                                            Revoke Selected ({{ selectedSessions.length }})
                                        </Button>
                                        <Button 
                                            variant="outline" 
                                            size="sm" 
                                            class="text-[var(--color-error)] border-[var(--color-error)] hover:bg-[var(--color-error)]/10"
                                            @click="revokeSessions([])" 
                                            :loading="isRevokingSessions"
                                        >
                                            Revoke All
                                        </Button>
                                    </div>
                                </div>

                                <!-- Sessions List -->
                                <div class="rounded-lg border border-[var(--border-default)] overflow-hidden">
                                    <table class="w-full text-sm text-left">
                                        <thead class="bg-[var(--surface-secondary)] text-[var(--text-secondary)] font-medium border-b border-[var(--border-default)]">
                                            <tr>
                                                <th class="px-4 py-3 w-10">
                                                    <input 
                                                        type="checkbox" 
                                                        :checked="activeSessions.length > 0 && selectedSessions.length === activeSessions.length"
                                                        :indeterminate="selectedSessions.length > 0 && selectedSessions.length < activeSessions.length"
                                                        @change="toggleAllSessions"
                                                        class="rounded border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)]"
                                                    />
                                                </th>
                                                <th class="px-4 py-3">Device / Browser</th>
                                                <th class="px-4 py-3">IP Address</th>
                                                <th class="px-4 py-3 text-right">Last Active</th>
                                                <th class="px-4 py-3 w-20"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-[var(--border-default)]">
                                            <tr v-if="isSessionsLoading">
                                                <td colspan="5" class="px-4 py-8 text-center text-[var(--text-muted)]">Loading sessions...</td>
                                            </tr>
                                            <tr v-else-if="activeSessions.length === 0">
                                                <td colspan="5" class="px-4 py-8 text-center text-[var(--text-muted)]">No active sessions found.</td>
                                            </tr>
                                            <tr v-else v-for="session in activeSessions" :key="session.id" class="group hover:bg-[var(--surface-secondary)]/30 transition-colors">
                                                <td class="px-4 py-3">
                                                    <input 
                                                        type="checkbox" 
                                                        :checked="selectedSessions.includes(session.id)"
                                                        @change="toggleSessionSelection(session.id)"
                                                        class="rounded border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)]"
                                                    />
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center gap-3">
                                                        <div class="w-8 h-8 rounded-lg bg-[var(--surface-secondary)] flex items-center justify-center text-[var(--text-muted)]">
                                                           <component 
                                                                :is="session.device?.is_phone || session.device?.device === 'Mobile' ? Smartphone : Monitor" 
                                                                class="w-4 h-4" 
                                                            />
                                                        </div>
                                                        <div class="flex flex-col">
                                                            <span class="font-medium text-[var(--text-primary)]" :title="session.user_agent">
                                                                {{ session.device?.browser || 'Unknown' }} on {{ session.device?.platform || 'Unknown OS' }}
                                                            </span>
                                                            <span v-if="session.is_current_device" class="text-xs text-green-600 font-medium">Current Device</span>
                                                            <span v-else class="text-xs text-[var(--text-muted)]">{{ session.device?.device || 'Desktop' }}</span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3 font-mono text-xs text-[var(--text-secondary)]">
                                                    <div>{{ session.ip_address }}</div>
                                                    <div v-if="session.location" class="text-[var(--text-muted)] truncate max-w-[150px]" :title="`${session.location.city}, ${session.location.state}, ${session.location.country}`">
                                                        {{ session.location.city }}, {{ session.location.iso_code }}
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3 text-right text-[var(--text-secondary)]">
                                                    {{ session.last_activity }}
                                                </td>
                                                <td class="px-4 py-3 text-right">
                                                    <Button 
                                                        variant="ghost" 
                                                        size="icon" 
                                                        class="text-[var(--text-muted)] hover:text-[var(--color-error)]"
                                                        @click="revokeSessions([session.id])"
                                                        title="Revoke Session"
                                                    >
                                                        <Trash2 class="w-4 h-4" />
                                                    </Button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- MFA / 2FA Enforcement -->
                             <div class="flex flex-col py-4 border-none pb-0 gap-4">
                                <div class="space-y-1">
                                    <h4 class="text-sm font-medium text-[var(--text-primary)] flex items-center gap-2">
                                        <Smartphone class="w-4 h-4 text-[var(--text-secondary)]" /> Multi-Factor Authentication
                                    </h4>
                                    <p class="text-sm text-[var(--text-secondary)] text-balance">Manage 2FA enforcement for this user account.</p>
                                </div>
                                <TwoFactorEnforcementPanel v-if="canManageStatus()" :user="user" @updated="fetchUser" />
                                <div v-else class="flex items-center gap-2">
                                    <Badge :variant="user.two_factor_enforced ? 'warning' : (user.two_factor_confirmed_at ? 'success' : 'secondary')">
                                        {{ user.two_factor_enforced ? 'Enforced' : (user.two_factor_confirmed_at ? 'Enabled' : 'Not Configured') }}
                                    </Badge>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Access Tab -->
                <div v-if="activeTab === 'access'" class="space-y-6">
                    <!-- User Status and Role Management Panel -->
                    <div v-if="canManageStatus() || canManageRoles()" class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden">
                        <div class="p-4 border-b border-[var(--border-muted)]">
                            <h3 class="font-medium text-[var(--text-primary)]">Account Status & Role</h3>
                            <p class="text-xs text-[var(--text-muted)] mt-1">Manage user status and role assignments with audit trail</p>
                        </div>
                        <div class="p-6">
                            <UserStatusPanel :user="user" @updated="fetchUser" />
                        </div>
                    </div>

                    <!-- Current Role Display (when not managing) -->
                    <div v-else class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden">
                        <div class="p-4 border-b border-[var(--border-muted)]">
                            <h3 class="font-medium text-[var(--text-primary)]">Assigned Roles</h3>
                        </div>
                        <div class="p-6">
                            <div class="flex flex-wrap gap-3">
                                <div v-for="role in user.roles" :key="role.name" class="flex items-center gap-2 px-3 py-2 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)]">
                                    <div class="w-2 h-2 rounded-full bg-[var(--interactive-primary)]"></div>
                                    <span class="text-sm font-medium text-[var(--text-primary)]">{{ role.label }}</span>
                                </div>
                                <span v-if="!user.roles?.length" class="text-sm text-[var(--text-muted)] italic">No roles assigned</span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden">
                        <div class="p-4 border-b border-[var(--border-muted)]">
                            <h3 class="font-medium text-[var(--text-primary)]">Effective Permissions</h3>
                        </div>
                        <div class="p-6">
                            <div class="flex flex-wrap gap-2">
                                <span v-for="permission in user.permissions" :key="permission.id || permission.name" class="px-2.5 py-1 bg-[var(--surface-secondary)] text-[var(--text-secondary)] rounded-md text-xs border border-[var(--border-default)]">
                                    {{ permission.label || permission.name }}
                                </span>
                                <span v-if="!user.permissions?.length" class="text-sm text-[var(--text-muted)] italic">No permissions found</span>
                            </div>
                        </div>
                    </div>

                    <!-- Permission Overrides -->
                    <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden">
                        <div class="p-4 border-b border-[var(--border-muted)] flex items-center justify-between">
                            <div>
                                <h3 class="font-medium text-[var(--text-primary)]">Permission Overrides</h3>
                                <p class="text-xs text-[var(--text-muted)] mt-0.5">Grant or block specific permissions for this user</p>
                            </div>
                            <Button 
                                v-if="canManageRoles()" 
                                variant="outline" 
                                size="sm"
                                @click="openOverrideModal"
                            >
                                <Plus class="w-4 h-4" />
                                Add Override
                            </Button>
                        </div>
                        <div class="p-6">
                            <PermissionOverridesList
                                :overrides="userOverrides"
                                :loading="overridesLoading"
                                :show-actions="canManageRoles()"
                                empty-message="No permission overrides for this user"
                                @renew="openRenewModal"
                                @revoke="openRevokeModal"
                            />
                        </div>
                    </div>
                </div>

                <!-- Activity Tab -->
                <div v-if="activeTab === 'activity'" class="space-y-6">
                    <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden flex flex-col" style="max-height: calc(100vh - 20rem);">
                        <!-- Header with Per Page Selector -->
                        <div class="p-4 border-b border-[var(--border-muted)] flex items-center justify-between shrink-0">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-[var(--interactive-primary)]/10 flex items-center justify-center">
                                    <History class="w-4 h-4 text-[var(--interactive-primary)]" />
                                </div>
                                <div>
                                    <h3 class="font-medium text-[var(--text-primary)]">Activity History</h3>
                                    <p class="text-xs text-[var(--text-muted)]">Recent actions performed by this user</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-[var(--text-muted)]">Show</span>
                                    <SelectFilter
                                        :modelValue="logsPerPage"
                                        @update:modelValue="handleLogsPerPageChange"
                                        :options="[
                                            { value: 10, label: '10' },
                                            { value: 25, label: '25' },
                                            { value: 50, label: '50' }
                                        ]"
                                        size="sm"
                                        class="w-20"
                                    />
                                </div>
                                <Button variant="ghost" size="sm" @click="viewAllLogs" class="text-xs gap-1">
                                    View All
                                    <ExternalLink class="w-3 h-3" />
                                </Button>
                            </div>
                        </div>

                        <!-- Loading State -->
                        <div v-if="isLogsLoading" class="p-8 flex flex-col items-center justify-center gap-3 flex-1">
                            <Loader2 class="w-6 h-6 animate-spin text-[var(--text-muted)]" />
                            <span class="text-sm text-[var(--text-muted)]">Loading activity...</span>
                        </div>

                        <!-- Empty State -->
                        <div v-else-if="auditLogs.length === 0" class="p-8 flex flex-col items-center justify-center gap-3 flex-1">
                            <div class="w-12 h-12 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center">
                                <FileText class="w-6 h-6 text-[var(--text-muted)]" />
                            </div>
                            <div class="text-center">
                                <p class="text-sm font-medium text-[var(--text-primary)]">No activity yet</p>
                                <p class="text-xs text-[var(--text-muted)]">User actions will appear here once they start using the system.</p>
                            </div>
                        </div>

                        <!-- Activity List (Scrollable) -->
                        <div v-else class="divide-y divide-[var(--border-default)] overflow-y-auto flex-1">
                            <div
                                v-for="log in auditLogs"
                                :key="log.id || log.public_id"
                                class="flex items-center gap-4 p-4 hover:bg-[var(--surface-secondary)]/50 transition-colors cursor-pointer group"
                                @click="viewLogDetails(log)"
                            >
                                <!-- Action Icon -->
                                <div class="w-10 h-10 rounded-xl bg-[var(--surface-secondary)] flex items-center justify-center text-[var(--text-muted)] group-hover:bg-[var(--surface-tertiary)] transition-colors shrink-0">
                                    <component :is="getActionIcon(log.action)" class="w-5 h-5" />
                                </div>

                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium text-[var(--text-primary)]">
                                            {{ log.action_label || formatActionLabel(log.action) }}
                                        </span>
                                        <span
                                            :class="[
                                                categoryColors[log.category] || categoryColors.system,
                                                'inline-flex px-2 py-0.5 text-xs font-medium rounded-full border capitalize'
                                            ]"
                                        >
                                            {{ (log.category_label || log.category || '').replace(/_/g, ' ') }}
                                        </span>
                                        <span
                                            v-if="log.severity && log.severity !== 'info'"
                                            :class="[
                                                severityColors[log.severity] || severityColors.info,
                                                'inline-flex px-1.5 py-0.5 text-xs font-medium rounded border capitalize'
                                            ]"
                                        >
                                            {{ log.severity }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-3 text-xs text-[var(--text-muted)]">
                                        <span v-if="log.ip_address || log.context?.ip_address" class="font-mono">
                                            {{ log.ip_address || log.context?.ip_address }}
                                        </span>
                                        <span v-if="log.description" class="truncate max-w-md">
                                            {{ log.description }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Time -->
                                <div class="text-right shrink-0">
                                    <div class="text-sm text-[var(--text-secondary)]">
                                        {{ log.time_ago || new Date(log.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
                                    </div>
                                    <div class="text-xs text-[var(--text-muted)]">
                                        {{ new Date(log.created_at).toLocaleDateString() }}
                                    </div>
                                </div>

                                <!-- View Button -->
                                <button class="p-2 rounded-lg text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] transition-colors opacity-0 group-hover:opacity-100">
                                    <Eye class="w-4 h-4" />
                                </button>
                            </div>
                        </div>

                        <!-- Pagination Footer -->
                        <div v-if="auditLogs.length > 0" class="px-4 py-3 border-t border-[var(--border-default)] bg-[var(--surface-secondary)]/30 flex items-center justify-between shrink-0">
                            <span class="text-xs text-[var(--text-muted)]">
                                Showing <span class="font-medium text-[var(--text-primary)]">{{ logsPagination.from || 1 }}</span>
                                to <span class="font-medium text-[var(--text-primary)]">{{ logsPagination.to || auditLogs.length }}</span>
                                of <span class="font-medium text-[var(--text-primary)]">{{ logsPagination.total || auditLogs.length }}</span> activities
                            </span>
                            <div class="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="fetchAuditLogs(logsPagination.current_page - 1)"
                                    :disabled="logsPagination.current_page <= 1"
                                    class="text-xs"
                                >
                                    Previous
                                </Button>
                                <span class="text-xs text-[var(--text-secondary)] px-2">
                                    Page {{ logsPagination.current_page }} of {{ logsPagination.last_page || 1 }}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="fetchAuditLogs(logsPagination.current_page + 1)"
                                    :disabled="logsPagination.current_page >= logsPagination.last_page"
                                    class="text-xs"
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Log Detail Modal -->
                <Modal v-model:open="showLogDetailModal" title="Activity Details" size="lg">
                    <div v-if="selectedLog" class="space-y-6">
                        <!-- Header -->
                        <div class="flex items-start gap-4 p-4 bg-[var(--surface-secondary)] rounded-lg">
                            <div class="w-12 h-12 rounded-xl bg-[var(--surface-tertiary)] flex items-center justify-center">
                                <component :is="getActionIcon(selectedLog.action)" class="w-6 h-6 text-[var(--text-muted)]" />
                            </div>
                            <div class="flex-1">
                                <h3 class="text-lg font-semibold text-[var(--text-primary)]">
                                    {{ selectedLog.action_label || formatActionLabel(selectedLog.action) }}
                                </h3>
                                <p class="text-sm text-[var(--text-secondary)]">
                                    {{ new Date(selectedLog.created_at).toLocaleString() }}
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <span :class="[categoryColors[selectedLog.category] || categoryColors.system, 'inline-flex px-2.5 py-1 text-xs font-medium rounded-full border capitalize']">
                                    {{ (selectedLog.category_label || selectedLog.category || '').replace(/_/g, ' ') }}
                                </span>
                                <span v-if="selectedLog.severity" :class="[severityColors[selectedLog.severity] || severityColors.info, 'inline-flex px-2.5 py-1 text-xs font-medium rounded border capitalize']">
                                    {{ selectedLog.severity }}
                                </span>
                            </div>
                        </div>

                        <!-- Details Grid -->
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="text-xs font-medium text-[var(--text-muted)]">IP Address</label>
                                <p class="text-sm text-[var(--text-primary)] font-mono">
                                    {{ selectedLog.ip_address || selectedLog.context?.ip_address || '-' }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <label class="text-xs font-medium text-[var(--text-muted)]">User Agent</label>
                                <p class="text-sm text-[var(--text-secondary)] truncate" :title="selectedLog.user_agent || selectedLog.context?.user_agent">
                                    {{ selectedLog.user_agent || selectedLog.context?.user_agent || '-' }}
                                </p>
                            </div>
                            <div v-if="selectedLog.url || selectedLog.context?.url" class="space-y-1 col-span-2">
                                <label class="text-xs font-medium text-[var(--text-muted)]">URL</label>
                                <p class="text-sm text-[var(--text-secondary)] font-mono break-all">
                                    <span v-if="selectedLog.method || selectedLog.context?.method" class="text-[var(--text-muted)]">
                                        {{ selectedLog.method || selectedLog.context?.method }}
                                    </span>
                                    {{ selectedLog.url || selectedLog.context?.url }}
                                </p>
                            </div>
                        </div>

                        <!-- Changes -->
                        <div v-if="selectedLog.old_values || selectedLog.new_values || selectedLog.changes" class="space-y-3">
                            <h4 class="text-sm font-medium text-[var(--text-primary)]">Changes</h4>
                            <div class="grid grid-cols-2 gap-4">
                                <div v-if="selectedLog.old_values" class="space-y-2">
                                    <label class="text-xs font-medium text-red-600">Previous Values</label>
                                    <pre class="text-xs bg-red-50 dark:bg-red-900/20 p-3 rounded-lg overflow-auto max-h-48 text-red-800 dark:text-red-200">{{ JSON.stringify(selectedLog.old_values, null, 2) }}</pre>
                                </div>
                                <div v-if="selectedLog.new_values" class="space-y-2">
                                    <label class="text-xs font-medium text-green-600">New Values</label>
                                    <pre class="text-xs bg-green-50 dark:bg-green-900/20 p-3 rounded-lg overflow-auto max-h-48 text-green-800 dark:text-green-200">{{ JSON.stringify(selectedLog.new_values, null, 2) }}</pre>
                                </div>
                            </div>
                        </div>

                        <!-- Metadata -->
                        <div v-if="selectedLog.metadata || selectedLog.context" class="space-y-3">
                            <h4 class="text-sm font-medium text-[var(--text-primary)]">Additional Context</h4>
                            <pre class="text-xs bg-[var(--surface-secondary)] p-3 rounded-lg overflow-auto max-h-48 text-[var(--text-secondary)]">{{ JSON.stringify(selectedLog.metadata || selectedLog.context, null, 2) }}</pre>
                        </div>
                    </div>

                    <template #footer>
                        <div class="flex justify-end">
                            <Button variant="outline" @click="showLogDetailModal = false">Close</Button>
                        </div>
                    </template>
                </Modal>
            </div>
        </div>

        <!-- Permission Override Modals -->
        <PermissionOverrideModal
            v-if="user"
            v-model:open="showOverrideModal"
            :user="user"
            :teams="userTeams"
            :permissions="availablePermissions"
            :loading="overrideActionLoading"
            @submit="handleCreateOverride"
        />

        <RevokePermissionModal
            v-model:open="showRevokeModal"
            :override="selectedOverride"
            :loading="overrideActionLoading"
            @submit="handleRevokeOverride"
        />

        <RenewPermissionModal
            v-model:open="showRenewModal"
            :override="selectedOverride"
            :loading="overrideActionLoading"
            @submit="handleRenewOverride"
        />
    </div>
</template>
