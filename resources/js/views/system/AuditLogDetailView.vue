<script setup>
import { ref, onMounted, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "@/lib/api";
import { Button, Badge } from "@/components/ui";
import {
    ArrowLeft,
    FileText,
    Clock,
    User,
    Monitor,
    Shield,
    Globe,
    AlertTriangle,
    CheckCircle,
    Key,
    LogIn,
    LogOut,
    UserPlus,
    Edit,
    Trash2,
    RefreshCw,
    Settings,
    Download,
} from "lucide-vue-next";
import { toast } from "vue-sonner";

const route = useRoute();
const router = useRouter();

const log = ref(null);
const isLoading = ref(true);

// Action Icons Mapping (Same as SystemLogsView)
const actionIconMap = {
    login: LogIn,
    logout: LogOut,
    login_failed: AlertTriangle,
    password_reset: Key,
    password_changed: Key,
    email_verified: CheckCircle,
    created: UserPlus,
    updated: Edit,
    deleted: Trash2,
    role_assigned: Shield,
    role_removed: Shield,
    permission_granted: Key,
    permission_revoked: Key,
    data_exported: Download,
    settings_changed: Settings,
    mfa_enabled: Shield,
    mfa_disabled: Shield,
    session_revoked: LogOut,
    account_locked: AlertTriangle,
    account_unlocked: CheckCircle,
};

// Colors (Same as SystemLogsView)
const categoryColors = {
    authentication:
        "bg-blue-500/10 text-blue-600 border-blue-200 dark:border-blue-800 dark:text-blue-400",
    authorization:
        "bg-purple-500/10 text-purple-600 border-purple-200 dark:border-purple-800 dark:text-purple-400",
    user_management:
        "bg-green-500/10 text-green-600 border-green-200 dark:border-green-800 dark:text-green-400",
    team_management:
        "bg-cyan-500/10 text-cyan-600 border-cyan-200 dark:border-cyan-800 dark:text-cyan-400",
    data_modification:
        "bg-orange-500/10 text-orange-600 border-orange-200 dark:border-orange-800 dark:text-orange-400",
    security:
        "bg-red-500/10 text-red-600 border-red-200 dark:border-red-800 dark:text-red-400",
    system: "bg-gray-500/10 text-gray-600 border-gray-200 dark:border-gray-700 dark:text-gray-400",
    api: "bg-indigo-500/10 text-indigo-600 border-indigo-200 dark:border-indigo-800 dark:text-indigo-400",
};

const severityColors = {
    debug: "bg-gray-500/10 text-gray-500 border-gray-200 dark:border-gray-700",
    info: "bg-blue-500/10 text-blue-600 border-blue-200 dark:border-blue-800",
    notice: "bg-cyan-500/10 text-cyan-600 border-cyan-200 dark:border-cyan-800",
    warning:
        "bg-yellow-500/10 text-yellow-600 border-yellow-200 dark:border-yellow-800",
    error: "bg-red-500/10 text-red-600 border-red-200 dark:border-red-800",
    critical:
        "bg-red-600/20 text-red-700 border-red-300 dark:border-red-700 dark:text-red-400",
    alert: "bg-orange-600/20 text-orange-700 border-orange-300 dark:border-orange-700 dark:text-orange-400",
    emergency:
        "bg-red-700/30 text-red-800 border-red-400 dark:border-red-600 dark:text-red-300",
};

const fetchLogDetails = async () => {
    isLoading.value = true;
    try {
        const response = await api.get(
            `/api/audit-logs/${route.params.public_id}`
        );
        log.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch log details:", error);
        toast.error("Failed to load audit log details.");
        router.push({ name: "system-logs" }); // Redirect back on error
    } finally {
        isLoading.value = false;
    }
};

const getActionIcon = (action) => {
    return actionIconMap[action] || FileText;
};

const formatActionLabel = (action) => {
    return action
        ? action.replace(/_/g, " ").replace(/\b\w/g, (l) => l.toUpperCase())
        : "Unknown Action";
};

const goBack = () => {
    router.back();
};

onMounted(() => {
    fetchLogDetails();
});
</script>

<template>
    <div class="p-6 w-full space-y-6">
        <!-- Header -->
        <div class="flex items-center gap-4 mb-6">
            <Button
                variant="ghost"
                size="icon"
                @click="goBack"
                class="rounded-full"
            >
                <ArrowLeft class="w-5 h-5" />
            </Button>
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                    Audit Log Details
                </h1>
                <p class="text-[var(--text-secondary)] text-sm">
                    ID:
                    <span class="font-mono text-[var(--text-muted)]">{{
                        route.params.public_id
                    }}</span>
                </p>
            </div>
        </div>

        <div
            v-if="isLoading"
            class="flex flex-col items-center justify-center py-20 text-[var(--text-muted)]"
        >
            <RefreshCw class="w-8 h-8 animate-spin mb-4" />
            <p>Loading details...</p>
        </div>

        <div v-else-if="log" class="space-y-6">
            <!-- Summary Card -->
            <div
                class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-6"
            >
                <div class="flex flex-col md:flex-row md:items-start gap-6">
                    <div
                        class="w-16 h-16 rounded-xl bg-[var(--surface-tertiary)] flex items-center justify-center flex-shrink-0"
                    >
                        <component
                            :is="getActionIcon(log.action)"
                            class="w-8 h-8 text-[var(--text-primary)]"
                        />
                    </div>

                    <div class="flex-1 space-y-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <h2
                                class="text-xl font-semibold text-[var(--text-primary)]"
                            >
                                {{ formatActionLabel(log.action) }}
                            </h2>
                            <div class="flex gap-2">
                                <span
                                    :class="[
                                        categoryColors[log.category] ||
                                            categoryColors.system,
                                        'inline-flex px-2.5 py-0.5 text-xs font-medium rounded-full border capitalize',
                                    ]"
                                >
                                    {{ log.category?.replace(/_/g, " ") }}
                                </span>
                                <span
                                    :class="[
                                        severityColors[log.severity] ||
                                            severityColors.info,
                                        'inline-flex px-2.5 py-0.5 text-xs font-medium rounded border capitalize',
                                    ]"
                                >
                                    {{ log.severity }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6"
                        >
                            <!-- User -->
                            <div class="flex items-start gap-3">
                                <User
                                    class="w-5 h-5 text-[var(--text-muted)] mt-0.5"
                                />
                                <div>
                                    <p
                                        class="text-sm font-medium text-[var(--text-secondary)]"
                                    >
                                        User
                                    </p>
                                    <p
                                        class="text-[var(--text-primary)] font-medium"
                                    >
                                        {{ log.user_name || "System" }}
                                    </p>
                                    <p
                                        v-if="log.user_email"
                                        class="text-xs text-[var(--text-muted)]"
                                    >
                                        {{ log.user_email }}
                                    </p>
                                </div>
                            </div>

                            <!-- Date -->
                            <div class="flex items-start gap-3">
                                <Clock
                                    class="w-5 h-5 text-[var(--text-muted)] mt-0.5"
                                />
                                <div>
                                    <p
                                        class="text-sm font-medium text-[var(--text-secondary)]"
                                    >
                                        Timestamp
                                    </p>
                                    <p class="text-[var(--text-primary)]">
                                        {{
                                            new Date(
                                                log.created_at
                                            ).toLocaleString()
                                        }}
                                    </p>
                                    <p class="text-xs text-[var(--text-muted)]">
                                        {{ log.time_ago }}
                                    </p>
                                </div>
                            </div>

                            <!-- IP Address -->
                            <div class="flex items-start gap-3">
                                <Globe
                                    class="w-5 h-5 text-[var(--text-muted)] mt-0.5"
                                />
                                <div>
                                    <p
                                        class="text-sm font-medium text-[var(--text-secondary)]"
                                    >
                                        IP Address
                                    </p>
                                    <p
                                        class="text-[var(--text-primary)] font-mono"
                                    >
                                        {{
                                            log.context?.ip_address ||
                                            log.ip_address ||
                                            "-"
                                        }}
                                    </p>
                                    <p
                                        v-if="log.context?.location"
                                        class="text-xs text-[var(--text-muted)]"
                                    >
                                        {{ log.context.location.city }},
                                        {{ log.context.location.country }}
                                    </p>
                                </div>
                            </div>

                            <!-- Device -->
                            <div class="flex items-start gap-3">
                                <Monitor
                                    class="w-5 h-5 text-[var(--text-muted)] mt-0.5"
                                />
                                <div>
                                    <p
                                        class="text-sm font-medium text-[var(--text-secondary)]"
                                    >
                                        Device
                                    </p>
                                    <div v-if="log.context?.device">
                                        <p class="text-[var(--text-primary)]">
                                            {{ log.context.device.browser }} on
                                            {{ log.context.device.platform }}
                                        </p>
                                    </div>
                                    <p
                                        v-else
                                        class="text-[var(--text-primary)]"
                                    >
                                        -
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Changes Column -->
                <div class="lg:col-span-2 space-y-6">
                    <div
                        class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-6"
                    >
                        <h3
                            class="text-lg font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2"
                        >
                            <RefreshCw
                                class="w-5 h-5 text-[var(--text-muted)]"
                            />
                            Changes
                        </h3>

                        <div v-if="log.changes" class="space-y-6">
                            <div
                                v-if="
                                    log.changes.old &&
                                    Object.keys(log.changes.old).length > 0
                                "
                                class="space-y-2"
                            >
                                <div class="flex items-center justify-between">
                                    <label
                                        class="text-sm font-medium text-red-600 dark:text-red-400"
                                        >Old Values</label
                                    >
                                </div>
                                <div class="relative group">
                                    <pre
                                        class="text-sm font-mono bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/30 p-4 rounded-lg overflow-auto max-h-[400px] text-red-900 dark:text-red-100"
                                        >{{
                                            JSON.stringify(
                                                log.changes.old,
                                                null,
                                                2
                                            )
                                        }}</pre
                                    >
                                </div>
                            </div>

                            <div
                                v-if="
                                    log.changes.new &&
                                    Object.keys(log.changes.new).length > 0
                                "
                                class="space-y-2"
                            >
                                <div class="flex items-center justify-between">
                                    <label
                                        class="text-sm font-medium text-green-600 dark:text-green-400"
                                        >New Values</label
                                    >
                                </div>
                                <div class="relative group">
                                    <pre
                                        class="text-sm font-mono bg-green-50 dark:bg-green-900/10 border border-green-100 dark:border-green-900/30 p-4 rounded-lg overflow-auto max-h-[400px] text-green-900 dark:text-green-100"
                                        >{{
                                            JSON.stringify(
                                                log.changes.new,
                                                null,
                                                2
                                            )
                                        }}</pre
                                    >
                                </div>
                            </div>
                        </div>
                        <div
                            v-else
                            class="text-center py-12 text-[var(--text-muted)] bg-[var(--surface-secondary)]/50 rounded-lg border border-dashed border-[var(--border-default)]"
                        >
                            <p>No specific changes recorded for this action.</p>
                        </div>
                    </div>
                </div>

                <!-- Technical Details Column -->
                <div class="space-y-6">
                    <div
                        class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-6"
                    >
                        <h3
                            class="text-lg font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2"
                        >
                            <FileText
                                class="w-5 h-5 text-[var(--text-muted)]"
                            />
                            Technical Context
                        </h3>

                        <div class="space-y-4">
                            <div v-if="log.url">
                                <label
                                    class="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider"
                                    >Request URL</label
                                >
                                <div
                                    class="mt-1 p-2 bg-[var(--surface-secondary)] rounded border border-[var(--border-default)] font-mono text-xs break-all text-[var(--text-secondary)]"
                                >
                                    <span
                                        class="font-bold text-[var(--text-primary)] mr-1"
                                        >{{ log.method }}</span
                                    >
                                    {{ log.url }}
                                </div>
                            </div>

                            <div v-if="log.context?.user_agent">
                                <label
                                    class="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider"
                                    >User Agent</label
                                >
                                <div
                                    class="mt-1 p-2 bg-[var(--surface-secondary)] rounded border border-[var(--border-default)] font-mono text-xs break-all text-[var(--text-secondary)]"
                                >
                                    {{ log.context.user_agent }}
                                </div>
                            </div>

                            <div
                                v-if="
                                    log.metadata &&
                                    Object.keys(log.metadata).length > 0
                                "
                            >
                                <label
                                    class="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider"
                                    >Metadata</label
                                >
                                <pre
                                    class="mt-1 p-2 bg-[var(--surface-secondary)] rounded border border-[var(--border-default)] font-mono text-xs overflow-auto max-h-48 text-[var(--text-secondary)]"
                                    >{{
                                        JSON.stringify(log.metadata, null, 2)
                                    }}</pre
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
