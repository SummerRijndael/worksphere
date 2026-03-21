<script setup>
import { computed } from "vue";
import { Card, Button, Badge } from "@/components/ui";
import { LifeBuoy, Ticket, Inbox, MessageSquare, ArrowRight, Archive, Cog } from "lucide-vue-next";
import { useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import { preferredSupportWorkspaceRoute } from "@/utils/supportWorkspace";

const router = useRouter();
const authStore = useAuthStore();

const canAccessSupportTicketDashboard = computed(() =>
    authStore.hasPermission("tickets.manage"),
);

const supportWorkAreaRoute = computed(() =>
    preferredSupportWorkspaceRoute(authStore.user, authStore.hasPermission),
);

const supportWorkAreaTitle = computed(() =>
    supportWorkAreaRoute.value === "/support/workbench"
        ? "Agent Workbench"
        : "Lead Inbox",
);

const supportWorkAreaDescription = computed(() =>
    supportWorkAreaRoute.value === "/support/workbench"
        ? "Handle multiple active chats in a full-page agent desk."
        : "Monitor queue, routing, and assignment from the lead console.",
);

const goTo = (path) => router.push(path);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-[var(--text-primary)]">Support Center</h1>
            <p class="text-[var(--text-secondary)]">Manage live chat and ticket operations from a single workspace.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
            <Card class="p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <Ticket class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge variant="secondary" size="sm">Tickets</Badge>
                </div>
                <h2 class="font-semibold text-[var(--text-primary)]">Ticket Dashboard</h2>
                <p class="text-sm text-[var(--text-secondary)]">Review and submit support tickets.</p>
                <Button
                    variant="outline"
                    class="w-full"
                    @click="goTo(canAccessSupportTicketDashboard ? '/support/tickets' : '/helpdesk')"
                >
                    Open
                    <ArrowRight class="ml-2 h-4 w-4" />
                </Button>
            </Card>

            <Card class="p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <Inbox class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge variant="secondary" size="sm">Agents</Badge>
                </div>
                <h2 class="font-semibold text-[var(--text-primary)]">{{ supportWorkAreaTitle }}</h2>
                <p class="text-sm text-[var(--text-secondary)]">{{ supportWorkAreaDescription }}</p>
                <Button variant="outline" class="w-full" @click="goTo(supportWorkAreaRoute)">
                    Open
                    <ArrowRight class="ml-2 h-4 w-4" />
                </Button>
            </Card>

            <Card class="p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <MessageSquare class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge variant="secondary" size="sm">Live Chat</Badge>
                </div>
                <h2 class="font-semibold text-[var(--text-primary)]">Chat Dashboard</h2>
                <p class="text-sm text-[var(--text-secondary)]">Track availability and live chat queue health.</p>
                <Button variant="outline" class="w-full" @click="goTo('/support/chats/dashboard')">
                    Open
                    <ArrowRight class="ml-2 h-4 w-4" />
                </Button>
            </Card>

            <Card class="p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <Archive class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge variant="secondary" size="sm">Helpdesk</Badge>
                </div>
                <h2 class="font-semibold text-[var(--text-primary)]">Helpdesk</h2>
                <p class="text-sm text-[var(--text-secondary)]">Open the helpdesk ticket page directly.</p>
                <Button variant="outline" class="w-full" @click="goTo('/helpdesk')">
                    Open
                    <ArrowRight class="ml-2 h-4 w-4" />
                </Button>
            </Card>

            <Card class="p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <Cog class="h-5 w-5 text-[var(--interactive-primary)]" />
                    <Badge variant="secondary" size="sm">Routing</Badge>
                </div>
                <h2 class="font-semibold text-[var(--text-primary)]">Routing & Skills</h2>
                <p class="text-sm text-[var(--text-secondary)]">Manage departments, skill queues, and agent role scopes.</p>
                <Button variant="outline" class="w-full" @click="goTo('/support/skills')">
                    Open
                    <ArrowRight class="ml-2 h-4 w-4" />
                </Button>
            </Card>
        </div>

        <Card class="p-5 flex items-start gap-4">
            <div class="rounded-full bg-[var(--interactive-primary)]/10 p-2">
                <LifeBuoy class="h-5 w-5 text-[var(--interactive-primary)]" />
            </div>
            <div class="space-y-1">
                <h3 class="font-semibold text-[var(--text-primary)]">Live Support Pipeline</h3>
                <p class="text-sm text-[var(--text-secondary)]">
                    New chats start in AI assist mode and escalate to a human agent when complexity is detected.
                </p>
            </div>
        </Card>
    </div>
</template>
