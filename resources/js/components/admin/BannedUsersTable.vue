<script setup>
import { ref, onMounted } from "vue";
import api from "@/lib/api";
import { useDate } from "@/composables/useDate";
const { formatDate } = useDate();

// Alias api to axios
const axios = api;
import { UserMinusIcon, MagnifyingGlassIcon } from "@heroicons/vue/24/outline";

const users = ref([]);
const loading = ref(true);

const fetchBannedUsers = async () => {
    loading.value = true;
    try {
        const response = await axios.get("/api/admin/security/banned-users");
        users.value = response.data.data || [];
    } catch (error) {
        console.error("Failed to fetch banned users", error);
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchBannedUsers();
});

const getStatusBadgeClass = (status) => {
    if (status === 'banned') return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300';
    if (status === 'suspended') return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';
    return 'bg-gray-100 text-gray-800';
};
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-between items-center">
             <h3 class="text-lg font-medium text-[var(--text-primary)]">Banned & Suspended Users</h3>
              <!-- Future: Add search here -->
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[var(--surface-subtle)] border-b border-[var(--border-default)]">
                        <tr>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">User</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">Email</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">Status</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">Joined</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)] text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-default)]">
                         <tr v-if="loading">
                             <td colspan="5" class="p-4 text-center text-[var(--text-tertiary)]">Loading...</td>
                        </tr>
                        <tr v-else-if="users.length === 0">
                             <td colspan="5" class="p-8 text-center text-[var(--text-tertiary)]">
                                <UserMinusIcon class="w-12 h-12 mx-auto mb-2 opacity-50" />
                                No banned or suspended users found
                             </td>
                        </tr>
                        <tr v-for="user in users" :key="user.id" class="hover:bg-[var(--surface-hover)] transition-colors duration-150">
                            <td class="px-6 py-4 font-medium text-[var(--text-primary)]">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-sm font-bold shrink-0">
                                        {{ user.name.charAt(0) }}
                                    </div>
                                    <div class="truncate max-w-[200px] text-base" title="user.name">{{ user.name }}</div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-[var(--text-secondary)]">{{ user.email }}</td>
                            <td class="px-6 py-4">
                                <div class="flex flex-col gap-1">
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold capitalize w-fit" :class="getStatusBadgeClass(user.status)">
                                        {{ user.status }}
                                    </span>
                                    <div v-if="user.status_reason" class="text-[10px] text-[var(--text-secondary)] italic max-w-[150px] truncate" :title="user.status_reason">
                                        {{ user.status_reason }}
                                    </div>
                                    <div v-if="user.status === 'suspended' && user.suspended_until" class="text-[10px] text-amber-600 dark:text-amber-400 font-medium whitespace-nowrap">
                                        Lifted: {{ formatDate(user.suspended_until) }}
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-[var(--text-secondary)]">
                                {{ formatDate(user.created_at) }}
                            </td>
                             <td class="px-6 py-4 text-right">
                                <router-link
                                    :to="{ name: 'admin-user-details', params: { public_id: user.public_id || user.id } }"
                                    class="text-[var(--interactive-primary)] hover:underline text-xs"
                                >
                                    Manage
                                </router-link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
