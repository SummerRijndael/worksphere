<script setup>
import { computed } from 'vue';
import { 
    Users, 
    UserCheck, 
    UserX, 
    ShieldAlert, 
    Shield, 
    Briefcase,
    Headset,
    ShieldCheck
} from 'lucide-vue-next';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
        default: () => ({
            total_users: 0,
            status_counts: {},
            role_counts: {}
        })
    },
    loading: {
        type: Boolean,
        default: false
    }
});

const activeUsers = computed(() => props.stats.status_counts['active'] || 0);
const inactiveUsers = computed(() => props.stats.status_counts['inactive'] || 0);
const suspendedUsers = computed(() => props.stats.status_counts['suspended'] || 0);
const blockedUsers = computed(() => props.stats.status_counts['blocked'] || 0);
const disabledUsers = computed(() => props.stats.status_counts['disabled'] || 0);

// Group negative statuses
const problemUsers = computed(() => suspendedUsers.value + blockedUsers.value + disabledUsers.value);

const administrators = computed(() => props.stats.role_counts['administrator'] || 0);
const projectManagers = computed(() => props.stats.role_counts['project_manager'] || props.stats.role_counts['pm'] || 0); // Handle 'pm' or 'project_manager'
const operators = computed(() => props.stats.role_counts['operator'] || 0);
const members = computed(() => props.stats.role_counts['member'] || props.stats.role_counts['user'] || 0); // Handle default role name
</script>

<template>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <!-- Card 1: Total Users -->
        <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 flex flex-col justify-between h-32 relative overflow-hidden group">
            <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                <Users class="w-16 h-16 text-[var(--interactive-primary)]" />
            </div>
            <div>
                <p class="text-sm font-medium text-[var(--text-secondary)]">Total Users</p>
                <div v-if="loading" class="h-8 w-24 bg-[var(--surface-tertiary)] animate-pulse rounded mt-1"></div>
                <h3 v-else class="text-3xl font-bold text-[var(--text-primary)] mt-1">{{ stats.total_users }}</h3>
            </div>
            <div class="flex items-center gap-1 text-xs text-[var(--text-tertiary)]">
                <span>System wide</span>
            </div>
        </div>

        <!-- Card 2: Active Users -->
        <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 flex flex-col justify-between h-32 relative overflow-hidden group">
            <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                <UserCheck class="w-16 h-16 text-green-500" />
            </div>
            <div>
                <p class="text-sm font-medium text-[var(--text-secondary)]">Active Users</p>
                <div v-if="loading" class="h-8 w-24 bg-[var(--surface-tertiary)] animate-pulse rounded mt-1"></div>
                <h3 v-else class="text-3xl font-bold text-green-600 dark:text-green-500 mt-1">{{ activeUsers }}</h3>
            </div>
            <div class="flex items-center gap-1 text-xs text-[var(--text-tertiary)]">
                <span class="text-green-600 dark:text-green-500 flex items-center gap-0.5">
                    {{ Math.round((activeUsers / (stats.total_users || 1)) * 100) }}%
                </span>
                <span>of total users</span>
            </div>
        </div>

        <!-- Card 3: Restricted Access (Suspended/Blocked/Disabled) -->
        <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 flex flex-col justify-between h-32 relative overflow-hidden group">
            <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                <ShieldAlert class="w-16 h-16 text-red-500" />
            </div>
            <div>
                <p class="text-sm font-medium text-[var(--text-secondary)]">Restricted</p>
                <div v-if="loading" class="h-8 w-24 bg-[var(--surface-tertiary)] animate-pulse rounded mt-1"></div>
                <h3 v-else class="text-3xl font-bold text-red-600 dark:text-red-500 mt-1">{{ problemUsers }}</h3>
            </div>
            <div class="flex flex-wrap gap-2 text-xs mt-auto">
                <span v-if="suspendedUsers" class="px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400 border border-red-200 dark:border-red-800">
                    {{ suspendedUsers }} Suspended
                </span>
                <span v-if="blockedUsers" class="px-1.5 py-0.5 rounded bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 border border-orange-200 dark:border-orange-800">
                    {{ blockedUsers }} Blocked
                </span>
                <span v-if="disabledUsers" class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700">
                    {{ disabledUsers }} Disabled
                </span>
                <span v-if="!problemUsers" class="text-[var(--text-tertiary)]">No restricted accounts</span>
            </div>
        </div>

        <!-- Card 4: Role Distribution -->
        <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] p-4 flex flex-col justify-between h-32 relative overflow-hidden">
             <div class="absolute right-0 top-0 p-4 opacity-5 pointer-events-none">
                <Shield class="w-16 h-16 text-[var(--text-primary)]" />
            </div>
            <div>
                <p class="text-sm font-medium text-[var(--text-secondary)]">Role Distribution</p>
                <div v-if="loading" class="space-y-2 mt-2">
                    <div class="h-4 w-full bg-[var(--surface-tertiary)] animate-pulse rounded"></div>
                    <div class="h-4 w-2/3 bg-[var(--surface-tertiary)] animate-pulse rounded"></div>
                </div>
                <div v-else class="grid grid-cols-2 gap-2 mt-2 text-xs">
                    <div class="flex items-center justify-between p-1.5 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-default)]">
                        <div class="flex items-center gap-1.5">
                            <ShieldCheck class="w-3.5 h-3.5 text-purple-600 dark:text-purple-400" />
                            <span class="text-[var(--text-secondary)]">Admin</span>
                        </div>
                        <span class="font-bold text-[var(--text-primary)]">{{ administrators }}</span>
                    </div>
                    <div class="flex items-center justify-between p-1.5 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-default)]">
                        <div class="flex items-center gap-1.5">
                            <Briefcase class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" />
                            <span class="text-[var(--text-secondary)]">PM</span>
                        </div>
                        <span class="font-bold text-[var(--text-primary)]">{{ projectManagers }}</span>
                    </div>
                    <div class="flex items-center justify-between p-1.5 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-default)]">
                        <div class="flex items-center gap-1.5">
                            <Headset class="w-3.5 h-3.5 text-orange-600 dark:text-orange-400" />
                            <span class="text-[var(--text-secondary)]">Ops</span>
                        </div>
                        <span class="font-bold text-[var(--text-primary)]">{{ operators }}</span>
                    </div>
                     <div class="flex items-center justify-between p-1.5 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-default)]">
                        <div class="flex items-center gap-1.5">
                            <Users class="w-3.5 h-3.5 text-gray-600 dark:text-gray-400" />
                            <span class="text-[var(--text-secondary)]">Users</span>
                        </div>
                        <span class="font-bold text-[var(--text-primary)]">{{ members }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
