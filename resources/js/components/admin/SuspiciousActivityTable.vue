<script setup>
import { ref, onMounted } from "vue";
import {
    ExclamationTriangleIcon,
    ShieldCheckIcon,
    NoSymbolIcon,
    MagnifyingGlassIcon,
    ArrowPathIcon,
    DocumentMagnifyingGlassIcon,
} from "@heroicons/vue/24/outline";
import api from "@/lib/api";
import { useDate } from "@/composables/useDate";

const { formatRelativeTime } = useDate();

const emit = defineEmits(["investigate"]);

const activities = ref([]);
const loading = ref(true);
const searchTerm = ref("");

const fetchActivities = async () => {
    loading.value = true;
    try {
        const response = await api.get("/api/admin/security/map-data"); // Reuse map data or create new endpoint
        // For table we might want more data, but let's assume we use map-data for now
        activities.value = response.data.sort((a, b) => b.count - a.count);
    } catch (error) {
        console.error("Failed to fetch suspicious activities", error);
    } finally {
        loading.value = false;
    }
};

const blockIp = async (ip) => {
    try {
        await api.post("/api/admin/security/blocked-ips", {
            ip_address: ip,
            reason: "Suspicious activity detected",
        });
        // Success notification?
        fetchActivities();
    } catch (error) {
        console.error("Failed to block IP", error);
    }
};

onMounted(() => {
    fetchActivities();
});
</script>

<template>
    <div class="space-y-6">
        <div
            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4"
        >
            <div class="relative w-full sm:w-96">
                <MagnifyingGlassIcon
                    class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-(--text-tertiary)"
                />
                <input
                    v-model="searchTerm"
                    type="text"
                    placeholder="Search by IP or location..."
                    class="input pl-10 w-full"
                />
            </div>
            <button
                @click="fetchActivities"
                class="btn btn-secondary flex items-center gap-2"
            >
                <ArrowPathIcon
                    class="w-4 h-4"
                    :class="{ 'animate-spin': loading }"
                />
                Refresh
            </button>
        </div>

        <div
            class="overflow-x-auto rounded-xl border border-(--border-default)"
        >
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr
                        class="bg-(--surface-secondary) border-b border-(--border-default)"
                    >
                        <th
                            class="px-6 py-4 text-sm font-bold text-(--text-secondary) uppercase tracking-wider"
                        >
                            Origin / IP
                        </th>
                        <th
                            class="px-6 py-4 text-sm font-bold text-(--text-secondary) uppercase tracking-wider"
                        >
                            Type
                        </th>
                        <th
                            class="px-6 py-4 text-sm font-bold text-(--text-secondary) uppercase tracking-wider"
                        >
                            Intensity
                        </th>
                        <th
                            class="px-6 py-4 text-sm font-bold text-(--text-secondary) uppercase tracking-wider"
                        >
                            Hits
                        </th>
                        <th
                            class="px-6 py-4 text-sm font-bold text-(--text-secondary) uppercase tracking-wider text-right"
                        >
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-(--border-default) bg-(--surface-card)">
                    <tr
                        v-for="activity in activities"
                        :key="activity.ip"
                        class="hover:bg-(--surface-subtle) transition-colors duration-150"
                    >
                        <td class="px-6 py-4">
                            <div class="flex flex-col">
                                <span
                                    class="font-mono font-medium text-(--text-primary)"
                                    >{{ activity.ip }}</span
                                >
                                <span class="text-xs text-(--text-tertiary)">{{
                                    activity.location
                                }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span
                                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                :class="
                                    activity.count > 10
                                        ? 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400'
                                        : 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400'
                                "
                            >
                                {{ activity.type }}
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div
                                class="w-24 bg-(--surface-tertiary) h-2 rounded-full overflow-hidden"
                            >
                                <div
                                    class="h-full transition-all duration-1000"
                                    :class="
                                        activity.count > 10
                                            ? 'bg-red-500'
                                            : 'bg-blue-500'
                                    "
                                    :style="{
                                        width:
                                            Math.min(
                                                100,
                                                (activity.count / 20) * 100,
                                            ) + '%',
                                    }"
                                ></div>
                            </div>
                        </td>
                        <td
                            class="px-6 py-4 text-sm font-bold text-(--text-primary)"
                        >
                            {{ activity.count }}
                        </td>
                        <td class="px-6 py-4 text-right">
                            <button
                                @click="emit('investigate', activity.ip)"
                                class="btn btn-ghost btn-sm text-blue-500 hover:bg-blue-500/10 mr-1"
                                title="Investigate Activity"
                            >
                                <DocumentMagnifyingGlassIcon class="w-5 h-5" />
                            </button>
                            <button
                                @click="blockIp(activity.ip)"
                                class="btn btn-ghost btn-sm text-red-500 hover:bg-red-500/10"
                                title="Block IP"
                            >
                                <NoSymbolIcon class="w-5 h-5" />
                            </button>
                        </td>
                    </tr>
                    <tr v-if="activities.length === 0 && !loading">
                        <td
                            colspan="5"
                            class="px-6 py-12 text-center text-(--text-tertiary)"
                        >
                            <ShieldCheckIcon
                                class="w-12 h-12 mx-auto mb-4 opacity-20"
                            />
                            No suspicious activities detected recently.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
