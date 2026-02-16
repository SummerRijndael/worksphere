<script setup>
import { ref, onMounted } from "vue";
import api from "@/lib/api";
import { useDate } from "@/composables/useDate";
const { formatRelativeTime } = useDate();

// Alias api to axios
const axios = api;
import {
    ShieldExclamationIcon,
    UserIcon,
    GlobeAltIcon,
    ExclamationCircleIcon,
    ArrowRightOnRectangleIcon,
    ArrowLeftOnRectangleIcon,
    NoSymbolIcon,
    KeyIcon,
} from "@heroicons/vue/24/outline";

const activities = ref([]);
const loading = ref(true);

const fetchActivity = async () => {
    loading.value = true;
    try {
        const response = await axios.get("/api/admin/security/activity", {
            params: { limit: 5 },
        });
        activities.value = response.data.data || [];
    } catch (error) {
        console.error("Failed to fetch security activity", error);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchActivity();
});

const getActionIcon = (action) => {
    const a = action.toLowerCase();
    if (a.includes('login')) return ArrowRightOnRectangleIcon;
    if (a.includes('logout')) return ArrowLeftOnRectangleIcon;
    if (a.includes('blocked') || a.includes('denied')) return NoSymbolIcon;
    if (a.includes('rate_limit')) return ShieldExclamationIcon;
    if (a.includes('password')) return KeyIcon;
    return ExclamationCircleIcon;
};

const getActionColor = (level, action) => {
    const a = action.toLowerCase();
    const l = (level || '').toLowerCase();
    
    if (a.includes('fail') || l === 'high' || l === 'critical') return "text-red-500 bg-red-500/10 border-red-500/20";
    if (a.includes('warn') || l === 'medium' || l === 'warning') return "text-orange-500 bg-orange-500/10 border-orange-500/20";
    if (a.includes('login') || a.includes('logout') || l === 'info') return "text-blue-500 bg-blue-500/10 border-blue-500/20";
    return "text-indigo-500 bg-indigo-500/10 border-indigo-500/20";
};

const formatAction = (action) => {
    // Firewall logs use 'middleware' column usually, but sometimes action is implied
    // If action is not present, use middleware name or level
    return (action || 'Security Event').replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
};
</script>

<template>
    <div class="space-y-0">
        <div v-if="loading" class="p-4 text-center text-[var(--text-tertiary)]">
            Loading activity...
        </div>

        <div
            v-else-if="activities.length === 0"
            class="p-8 text-center text-[var(--text-tertiary)]"
        >
            <ShieldExclamationIcon class="w-12 h-12 mx-auto mb-3 opacity-50" />
            <p>No recent security incidents found.</p>
        </div>

        <div
            v-else
            v-for="log in activities"
            :key="log.id"
            class="group relative flex items-start gap-4 p-4 border-b border-[var(--border-default)] last:border-0 hover:bg-[var(--surface-subtle)] transition-all duration-200"
        >
            <!-- Left accent border on hover -->
            <div class="absolute left-0 top-2 bottom-2 w-1 rounded-r-full opacity-0 group-hover:opacity-100 transition-opacity duration-200" 
                 :class="(log.level === 'high' || log.level === 'critical') ? 'bg-red-500' : 'bg-[var(--interactive-primary)]'"></div>

            <div
                class="flex items-center justify-center p-2.5 rounded-xl shrink-0 border"
                :class="getActionColor(log.level, log.middleware || log.action || 'unknown')"
            >
                <component :is="getActionIcon(log.middleware || log.action || 'unknown')" class="w-5 h-5" />
            </div>

            <div class="flex-1 min-w-0 pt-0.5">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <h4 class="text-sm font-bold text-[var(--text-primary)] truncate">
                        {{ formatAction(log.middleware || log.action) }}
                    </h4>
                    <span class="text-[11px] font-medium text-[var(--text-tertiary)] uppercase tracking-wider whitespace-nowrap">
                        {{ formatRelativeTime(log.created_at) }}
                    </span>
                </div>

                <div class="flex flex-col gap-1.5">
                    <div class="flex items-center gap-2 text-xs font-medium">
                        <span class="text-[var(--text-secondary)] flex items-center gap-1">
                            <UserIcon class="w-3.5 h-3.5 opacity-70" />
                            {{ log.user_name || log.metadata?.email || "System/Guest" }}
                        </span>
                        <span class="w-1 h-1 rounded-full bg-[var(--border-default)]"></span>
                        <span class="text-[var(--text-tertiary)] font-mono flex items-center gap-1">
                            <GlobeAltIcon class="w-3.5 h-3.5 opacity-70" />
                            {{ log.ip || "N/A" }}
                        </span>
                    </div>
                    
                    <div v-if="log.metadata?.reason || log.metadata?.url" class="text-xs text-[var(--text-tertiary)] leading-relaxed italic bg-[var(--surface-card)] p-2 rounded-lg border border-[var(--border-default)] mt-1">
                        <span v-if="log.metadata?.reason">Reason: {{ log.metadata.reason }}</span>
                        <span v-if="log.metadata?.url" :class="{'block mt-0.5': log.metadata?.reason}">
                            URL: {{ log.metadata.url }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
