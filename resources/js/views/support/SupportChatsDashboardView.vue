<script setup>
import { onMounted, ref } from "vue";
import { Card, Button, Badge } from "@/components/ui";
import { Loader2, MessageSquare, Users, AlertCircle, Inbox } from "lucide-vue-next";
import api from "@/lib/api";

const loading = ref(true);
const availability = ref({
    available: false,
    available_agents: 0,
    message: "Checking support availability...",
});
const unassignedCount = ref(0);
const mineCount = ref(0);
const loadError = ref("");

async function loadDashboard() {
    loading.value = true;
    loadError.value = "";

    try {
        const [availabilityResponse, unassignedResponse, mineResponse] = await Promise.all([
            api.get("/api/support/chats/availability"),
            api.get("/api/support/chats/inbox", { params: { scope: "unassigned", per_page: 1 } }),
            api.get("/api/support/chats/inbox", { params: { scope: "mine", per_page: 1 } }),
        ]);

        availability.value = availabilityResponse.data?.data ?? availability.value;
        unassignedCount.value = unassignedResponse.data?.meta?.total ?? 0;
        mineCount.value = mineResponse.data?.meta?.total ?? 0;
    } catch (error) {
        console.error("Failed to load support chat dashboard:", error);
        loadError.value = "Unable to load dashboard data. Please refresh.";
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    loadDashboard();
});
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-[var(--text-primary)]">Live Chat Dashboard</h1>
                <p class="text-[var(--text-secondary)]">Queue health and human-agent availability for support chats.</p>
            </div>
            <Button variant="outline" @click="loadDashboard" :disabled="loading">
                <Loader2 v-if="loading" class="mr-2 h-4 w-4 animate-spin" />
                Refresh
            </Button>
        </div>

        <div v-if="loadError" class="rounded-lg border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-600 dark:text-red-300">
            {{ loadError }}
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <Card class="p-5 space-y-2">
                <div class="flex items-center justify-between">
                    <Users class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge :variant="availability.available ? 'success' : 'warning'" size="sm">
                        {{ availability.available ? "Online" : "Offline" }}
                    </Badge>
                </div>
                <p class="text-sm text-[var(--text-secondary)]">Available Agents</p>
                <p class="text-2xl font-semibold text-[var(--text-primary)]">{{ availability.available_agents }}</p>
                <p class="text-xs text-[var(--text-muted)]">{{ availability.message }}</p>
            </Card>

            <Card class="p-5 space-y-2">
                <div class="flex items-center justify-between">
                    <AlertCircle class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge variant="secondary" size="sm">Queue</Badge>
                </div>
                <p class="text-sm text-[var(--text-secondary)]">Unassigned Conversations</p>
                <p class="text-2xl font-semibold text-[var(--text-primary)]">{{ unassignedCount }}</p>
                <p class="text-xs text-[var(--text-muted)]">Chats waiting for first human assignment.</p>
            </Card>

            <Card class="p-5 space-y-2">
                <div class="flex items-center justify-between">
                    <Inbox class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge variant="secondary" size="sm">Mine</Badge>
                </div>
                <p class="text-sm text-[var(--text-secondary)]">Assigned To Me</p>
                <p class="text-2xl font-semibold text-[var(--text-primary)]">{{ mineCount }}</p>
                <p class="text-xs text-[var(--text-muted)]">Current workload in your support inbox.</p>
            </Card>
        </div>

        <Card class="p-5 flex items-center gap-3 text-sm text-[var(--text-secondary)]">
            <MessageSquare class="h-4 w-4 text-[var(--interactive-primary)]" />
            AI-first support is active. Complex issues are automatically routed to human support agents.
        </Card>
    </div>
</template>

