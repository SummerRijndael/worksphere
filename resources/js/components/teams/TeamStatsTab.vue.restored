<script setup>
import { ref, onMounted } from "vue";
import axios from "axios";
import { useRoute } from "vue-router";
import Card from "@/components/ui/Card.vue";
import Avatar from "@/components/ui/Avatar.vue";
import StatusBadge from "@/components/ui/StatusBadge.vue";
import { ArrowUp, ArrowDown, Minus } from "lucide-vue-next";

const route = useRoute();
const members = ref([]);
const loading = ref(true);
const sortKey = ref("total_assigned");
const sortOrder = ref("desc");

const fetchMemberStats = async () => {
    loading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${route.params.public_id}/stats/analytics-members`,
        );
        members.value = response.data;
    } catch (error) {
        console.error("Error fetching member stats:", error);
    } finally {
        loading.value = false;
    }
};

const sortedMembers = computed(() => {
    return [...members.value].sort((a, b) => {
        let modifier = sortOrder.value === "desc" ? -1 : 1;
        if (a[sortKey.value] < b[sortKey.value]) return -1 * modifier;
        if (a[sortKey.value] > b[sortKey.value]) return 1 * modifier;
        return 0;
    });
});

const setSort = (key) => {
    if (sortKey.value === key) {
        sortOrder.value = sortOrder.value === "asc" ? "desc" : "asc";
    } else {
        sortKey.value = key;
        sortOrder.value = "desc";
    }
};

import { computed } from "vue";

onMounted(() => {
    fetchMemberStats();
});
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-medium text-[var(--text-primary)]">
                Member Performance
            </h3>
            <button
                @click="fetchMemberStats"
                class="text-sm text-[var(--primary)] hover:underline"
            >
                Refresh
            </button>
        </div>

        <Card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead
                        class="bg-[var(--bg-secondary)] border-b border-[var(--border-color)]"
                    >
                        <tr>
                            <th
                                class="px-6 py-4 font-medium text-[var(--text-secondary)]"
                            >
                                Member
                            </th>
                            <th
                                class="px-6 py-4 font-medium text-[var(--text-secondary)]"
                            >
                                Role
                            </th>
                            <th
                                class="px-6 py-4 font-medium text-[var(--text-secondary)] cursor-pointer hover:text-[var(--text-primary)] transition-colors"
                                @click="setSort('total_assigned')"
                            >
                                <div class="flex items-center gap-2">
                                    Tasks Assigned
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'total_assigned' &&
                                            sortOrder === 'asc'
                                        "
                                        class="w-4 h-4"
                                    />
                                    <ArrowDown
                                        v-if="
                                            sortKey === 'total_assigned' &&
                                            sortOrder === 'desc'
                                        "
                                        class="w-4 h-4"
                                    />
                                </div>
                            </th>
                            <th
                                class="px-6 py-4 font-medium text-[var(--text-secondary)] cursor-pointer hover:text-[var(--text-primary)] transition-colors"
                                @click="setSort('completed')"
                            >
                                <div class="flex items-center gap-2">
                                    Completed
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'completed' &&
                                            sortOrder === 'asc'
                                        "
                                        class="w-4 h-4"
                                    />
                                    <ArrowDown
                                        v-if="
                                            sortKey === 'completed' &&
                                            sortOrder === 'desc'
                                        "
                                        class="w-4 h-4"
                                    />
                                </div>
                            </th>
                            <th
                                class="px-6 py-4 font-medium text-[var(--text-secondary)] cursor-pointer hover:text-[var(--text-primary)] transition-colors"
                                @click="setSort('overdue')"
                            >
                                <div class="flex items-center gap-2">
                                    Overdue
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'overdue' &&
                                            sortOrder === 'asc'
                                        "
                                        class="w-4 h-4"
                                    />
                                    <ArrowDown
                                        v-if="
                                            sortKey === 'overdue' &&
                                            sortOrder === 'desc'
                                        "
                                        class="w-4 h-4"
                                    />
                                </div>
                            </th>
                            <th
                                class="px-6 py-4 font-medium text-[var(--text-secondary)] cursor-pointer hover:text-[var(--text-primary)] transition-colors"
                                @click="setSort('adherence_rate')"
                            >
                                <div class="flex items-center gap-2">
                                    Adherence Rate
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'adherence_rate' &&
                                            sortOrder === 'asc'
                                        "
                                        class="w-4 h-4"
                                    />
                                    <ArrowDown
                                        v-if="
                                            sortKey === 'adherence_rate' &&
                                            sortOrder === 'desc'
                                        "
                                        class="w-4 h-4"
                                    />
                                </div>
                            </th>
                            <th
                                class="px-6 py-4 font-medium text-[var(--text-secondary)] cursor-pointer hover:text-[var(--text-primary)] transition-colors"
                                @click="setSort('rejection_rate')"
                            >
                                <div class="flex items-center gap-2">
                                    Rejection Rate
                                    <ArrowUp
                                        v-if="
                                            sortKey === 'rejection_rate' &&
                                            sortOrder === 'asc'
                                        "
                                        class="w-4 h-4"
                                    />
                                    <ArrowDown
                                        v-if="
                                            sortKey === 'rejection_rate' &&
                                            sortOrder === 'desc'
                                        "
                                        class="w-4 h-4"
                                    />
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-color)]">
                        <tr v-if="loading">
                            <td
                                colspan="6"
                                class="px-6 py-8 text-center text-[var(--text-secondary)]"
                            >
                                Loading stats...
                            </td>
                        </tr>
                        <tr v-else-if="members.length === 0">
                            <td
                                colspan="6"
                                class="px-6 py-8 text-center text-[var(--text-secondary)]"
                            >
                                No member data available
                            </td>
                        </tr>
                        <tr
                            v-for="member in sortedMembers"
                            :key="member.user.id"
                            class="hover:bg-[var(--bg-secondary-hover)] transition-colors"
                        >
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <Avatar
                                        :src="member.user.avatar_url"
                                        :alt="member.user.initials"
                                        size="sm"
                                    />
                                    <span
                                        class="font-medium text-[var(--text-primary)]"
                                        >{{ member.user.name }}</span
                                    >
                                </div>
                            </td>
                            <td
                                class="px-6 py-4 text-[var(--text-secondary)] capitalize"
                            >
                                {{ member.role.replace("_", " ") }}
                            </td>
                            <td
                                class="px-6 py-4 font-medium text-[var(--text-primary)]"
                            >
                                {{ member.total_assigned }}
                            </td>
                            <td class="px-6 py-4 text-[var(--text-secondary)]">
                                {{ member.completed }}
                            </td>
                            <td class="px-6 py-4">
                                <span
                                    :class="
                                        member.overdue > 0
                                            ? 'text-red-500 font-medium'
                                            : 'text-[var(--text-secondary)]'
                                    "
                                >
                                    {{ member.overdue }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="h-2 w-24 bg-[var(--bg-tertiary)] rounded-full overflow-hidden"
                                    >
                                        <div
                                            class="h-full rounded-full transition-all duration-500"
                                            :class="{
                                                'bg-green-500':
                                                    member.adherence_rate >= 90,
                                                'bg-yellow-500':
                                                    member.adherence_rate >=
                                                        70 &&
                                                    member.adherence_rate < 90,
                                                'bg-red-500':
                                                    member.adherence_rate < 70,
                                            }"
                                            :style="{
                                                width: `${member.adherence_rate}%`,
                                            }"
                                        ></div>
                                    </div>
                                    <span class="text-sm font-medium"
                                        >{{ member.adherence_rate }}%</span
                                    >
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="h-2 w-24 bg-[var(--bg-tertiary)] rounded-full overflow-hidden"
                                    >
                                        <div
                                            class="h-full rounded-full transition-all duration-500"
                                            :class="{
                                                'bg-green-500':
                                                    member.rejection_rate <= 5,
                                                'bg-yellow-500':
                                                    member.rejection_rate > 5 &&
                                                    member.rejection_rate <= 15,
                                                'bg-red-500':
                                                    member.rejection_rate > 15,
                                            }"
                                            :style="{
                                                width: `${Math.min(member.rejection_rate, 100)}%`,
                                            }"
                                        ></div>
                                    </div>
                                    <span class="text-sm font-medium"
                                        >{{ member.rejection_rate }}%</span
                                    >
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
