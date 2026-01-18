<script setup>
import { computed } from "vue";
import {
    Shield,
    ShieldOff,
    Clock,
    MoreVertical,
    RefreshCw,
    Trash2,
    Calendar,
} from "lucide-vue-next";
import Badge from "../ui/Badge.vue";
import StatusBadge from "../ui/StatusBadge.vue";
import PermissionTypeBadge from "../ui/PermissionTypeBadge.vue";
import Button from "../ui/Button.vue";
import Dropdown from "../ui/Dropdown.vue";
import DropdownItem from "../ui/DropdownItem.vue";
import DropdownSeparator from "../ui/DropdownSeparator.vue";

const props = defineProps({
    overrides: {
        type: Array,
        default: () => [],
    },
    loading: Boolean,
    showActions: {
        type: Boolean,
        default: true,
    },
    emptyMessage: {
        type: String,
        default: "No permission overrides found",
    },
});

const emit = defineEmits(["renew", "revoke", "view"]);

function formatDate(dateString) {
    if (!dateString) return "-";
    return new Date(dateString).toLocaleDateString("en-US", {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
}

function formatRelativeTime(dateString) {
    if (!dateString) return "";
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = date - now;
    const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));

    if (diffDays < 0) return "Expired";
    if (diffDays === 0) return "Today";
    if (diffDays === 1) return "Tomorrow";
    return `In ${diffDays} days`;
}

const sortedOverrides = computed(() => {
    return [...props.overrides].sort((a, b) => {
        // Active first, then by created_at desc
        if (a.is_active !== b.is_active) return a.is_active ? -1 : 1;
        return new Date(b.created_at) - new Date(a.created_at);
    });
});
</script>

<template>
    <div class="space-y-3">
        <!-- Empty State -->
        <div
            v-if="!loading && overrides.length === 0"
            class="flex flex-col items-center justify-center py-12 text-center"
        >
            <Shield class="h-12 w-12 text-[var(--text-muted)] mb-4" />
            <p class="text-[var(--text-secondary)]">{{ emptyMessage }}</p>
        </div>

        <!-- Loading State -->
        <div v-else-if="loading" class="flex items-center justify-center py-12">
            <div
                class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--color-primary-500)]"
            ></div>
        </div>

        <!-- Override List -->
        <div
            v-else
            v-for="override in sortedOverrides"
            :key="override.id"
            class="group flex items-center justify-between p-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] hover:bg-[var(--surface-secondary)] transition-colors"
            :class="{ 'opacity-60': !override.is_active }"
        >
            <div class="flex items-start gap-4 flex-1 min-w-0">
                <!-- Icon -->
                <div
                    :class="[
                        'p-2 rounded-lg',
                        override.type === 'grant'
                            ? 'bg-green-100 dark:bg-green-900/30'
                            : 'bg-red-100 dark:bg-red-900/30',
                    ]"
                >
                    <Shield
                        v-if="override.type === 'grant'"
                        class="h-5 w-5 text-green-600 dark:text-green-400"
                    />
                    <ShieldOff
                        v-else
                        class="h-5 w-5 text-red-600 dark:text-red-400"
                    />
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span
                            class="font-mono text-sm font-medium text-[var(--text-primary)]"
                        >
                            {{ override.permission }}
                        </span>
                        <PermissionTypeBadge
                            :type="override.type"
                            :scope="override.scope"
                            size="sm"
                            :show-icon="false"
                        />
                        <StatusBadge
                            :status="override.status"
                            size="sm"
                            :show-icon="false"
                            show-dot
                        />
                    </div>

                    <p
                        class="text-sm text-[var(--text-secondary)] mt-1 line-clamp-1"
                    >
                        {{ override.reason }}
                    </p>

                    <div
                        class="flex items-center gap-4 mt-2 text-xs text-[var(--text-muted)]"
                    >
                        <span v-if="override.granted_by">
                            By {{ override.granted_by.name }}
                        </span>
                        <span v-if="override.team">
                            Team: {{ override.team.name }}
                        </span>
                        <span
                            v-if="override.is_temporary"
                            class="flex items-center gap-1"
                        >
                            <Clock class="h-3 w-3" />
                            <span
                                :class="{
                                    'text-amber-600 dark:text-amber-400':
                                        override.days_until_expiry <= 7,
                                }"
                            >
                                {{
                                    override.days_until_expiry !== null
                                        ? formatRelativeTime(
                                              override.expires_at
                                          )
                                        : "Permanent"
                                }}
                            </span>
                        </span>
                        <span>
                            Created {{ formatDate(override.created_at) }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div
                v-if="showActions && override.is_active"
                class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity"
            >
                <Button
                    v-if="override.is_temporary"
                    variant="ghost"
                    size="sm"
                    @click="$emit('renew', override)"
                    title="Renew"
                >
                    <RefreshCw class="h-4 w-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20"
                    @click="$emit('revoke', override)"
                    title="Revoke"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </div>

            <!-- Revoked info -->
            <div
                v-if="override.revoked_at"
                class="text-xs text-[var(--text-muted)]"
            >
                Revoked {{ formatDate(override.revoked_at) }}
            </div>
        </div>
    </div>
</template>
