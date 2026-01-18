<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { Card, Button, Badge, PageLoader, Avatar } from "@/components/ui";
import {
    Plus,
    Ticket,
    Folder,
    FileText,
    DollarSign,
    Clock,
    AlertCircle,
    ChevronRight,
} from "lucide-vue-next";
import axios from "axios";
import { useRouter } from "vue-router";

const router = useRouter();

const isLoading = ref(true);
const error = ref<string | null>(null);
const dashboardData = ref<{
    client: { name: string; initials: string };
    stats: {
        projects: { total: number; active: number };
        tickets: { total: number; open: number };
        invoices: { total: number; pending: number; pending_amount: number };
    };
    recent_projects: any[];
    recent_invoices: any[];
} | null>(null);

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: "USD",
    }).format(amount);
};

const formatDate = (dateString: string) => {
    if (!dateString) return "-";
    return new Date(dateString).toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        draft: "secondary",
        sent: "info",
        viewed: "warning",
        paid: "success",
        overdue: "error",
        cancelled: "secondary",
        planning: "secondary",
        in_progress: "info",
        on_hold: "warning",
        completed: "success",
    };
    return colors[status] || "secondary";
};

const fetchDashboardData = async () => {
    try {
        isLoading.value = true;
        error.value = null;

        const response = await axios.get("/api/client-portal/dashboard");
        dashboardData.value = response.data;
    } catch (err: any) {
        console.error("Failed to fetch client dashboard", err);
        if (err.response?.status === 404) {
            error.value =
                "No client profile found for your account. Please contact support.";
        } else {
            error.value = "Failed to load dashboard data. Please try again.";
        }
    } finally {
        isLoading.value = false;
    }
};

const navigateTo = (path: string) => {
    router.push(path);
};

onMounted(() => {
    fetchDashboardData();
});
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div
            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4"
        >
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                    Welcome{{
                        dashboardData?.client?.name
                            ? `, ${dashboardData.client.name}`
                            : ""
                    }}
                </h1>
                <p class="text-[var(--text-secondary)]">
                    Here's an overview of your projects and invoices.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <RouterLink to="/portal/settings">
                    <Button variant="outline">
                        Settings
                    </Button>
                </RouterLink>
                <RouterLink to="/portal/tickets/create">
                    <Button>
                        <Plus class="w-4 h-4 mr-2" />
                        Submit Ticket
                    </Button>
                </RouterLink>
            </div>
        </div>

        <!-- Loading State -->
        <PageLoader v-if="isLoading" />

        <!-- Error State -->
        <Card v-else-if="error" padding="lg" class="text-center">
            <AlertCircle
                class="w-12 h-12 mx-auto text-[var(--color-error)] mb-4"
            />
            <p class="text-[var(--text-primary)]">{{ error }}</p>
        </Card>

        <!-- Dashboard Content -->
        <template v-else-if="dashboardData">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <!-- Active Projects -->
                <Card
                    padding="lg"
                    class="cursor-pointer hover:shadow-lg transition-shadow"
                    @click="navigateTo('/portal/projects')"
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="p-3 rounded-lg bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400"
                        >
                            <Folder class="w-6 h-6" />
                        </div>
                        <div class="flex-1">
                            <p
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                Active Projects
                            </p>
                            <p
                                class="text-2xl font-bold text-[var(--text-primary)]"
                            >
                                {{ dashboardData.stats.projects.active }}
                            </p>
                        </div>
                        <ChevronRight
                            class="w-5 h-5 text-[var(--text-muted)]"
                        />
                    </div>
                </Card>

                <!-- Open Tickets -->
                <Card
                    padding="lg"
                    class="cursor-pointer hover:shadow-lg transition-shadow"
                    @click="navigateTo('/portal/tickets')"
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="p-3 rounded-lg bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"
                        >
                            <Ticket class="w-6 h-6" />
                        </div>
                        <div class="flex-1">
                            <p
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                Open Tickets
                            </p>
                            <p
                                class="text-2xl font-bold text-[var(--text-primary)]"
                            >
                                {{ dashboardData.stats.tickets.open }}
                            </p>
                        </div>
                        <ChevronRight
                            class="w-5 h-5 text-[var(--text-muted)]"
                        />
                    </div>
                </Card>

                <!-- Pending Invoices -->
                <Card
                    padding="lg"
                    class="cursor-pointer hover:shadow-lg transition-shadow"
                    @click="
                        navigateTo(
                            '/portal/invoices?status=sent,viewed,overdue'
                        )
                    "
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="p-3 rounded-lg bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400"
                        >
                            <FileText class="w-6 h-6" />
                        </div>
                        <div class="flex-1">
                            <p
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                Pending Invoices
                            </p>
                            <p
                                class="text-2xl font-bold text-[var(--text-primary)]"
                            >
                                {{ dashboardData.stats.invoices.pending }}
                            </p>
                        </div>
                        <ChevronRight
                            class="w-5 h-5 text-[var(--text-muted)]"
                        />
                    </div>
                </Card>

                <!-- Outstanding Amount -->
                <Card
                    padding="lg"
                    class="cursor-pointer hover:shadow-lg transition-shadow"
                    @click="navigateTo('/portal/invoices')"
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="p-3 rounded-lg bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400"
                        >
                            <DollarSign class="w-6 h-6" />
                        </div>
                        <div class="flex-1">
                            <p
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                Outstanding
                            </p>
                            <p
                                class="text-xl font-bold text-[var(--text-primary)]"
                            >
                                {{
                                    formatCurrency(
                                        dashboardData.stats.invoices
                                            .pending_amount
                                    )
                                }}
                            </p>
                        </div>
                        <ChevronRight
                            class="w-5 h-5 text-[var(--text-muted)]"
                        />
                    </div>
                </Card>
            </div>

            <!-- Recent Activity Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Projects -->
                <Card padding="none" class="overflow-hidden">
                    <div
                        class="p-4 border-b border-[var(--border-default)] flex items-center justify-between"
                    >
                        <h2 class="font-semibold text-[var(--text-primary)]">
                            Recent Projects
                        </h2>
                        <RouterLink
                            to="/portal/projects"
                            class="text-sm text-[var(--interactive-primary)] hover:underline"
                        >
                            View all
                        </RouterLink>
                    </div>

                    <div
                        v-if="dashboardData.recent_projects.length === 0"
                        class="p-8 text-center text-[var(--text-muted)]"
                    >
                        No projects found.
                    </div>

                    <div v-else class="divide-y divide-[var(--border-default)]">
                        <RouterLink
                            v-for="project in dashboardData.recent_projects"
                            :key="project.public_id"
                            :to="`/portal/projects/${project.public_id}`"
                            class="block p-4 hover:bg-[var(--surface-secondary)] transition-colors"
                        >
                            <div
                                class="flex items-center justify-between gap-4"
                            >
                                <div class="min-w-0 flex-1">
                                    <h3
                                        class="font-medium text-[var(--text-primary)] truncate"
                                    >
                                        {{ project.name }}
                                    </h3>
                                    <div class="flex items-center gap-2 mt-1">
                                        <Badge
                                            :variant="
                                                getStatusColor(project.status)
                                            "
                                            size="sm"
                                        >
                                            {{ project.status_label }}
                                        </Badge>
                                        <span
                                            v-if="
                                                project.progress !== undefined
                                            "
                                            class="text-xs text-[var(--text-muted)]"
                                        >
                                            {{ project.progress }}% complete
                                        </span>
                                    </div>
                                </div>
                                <ChevronRight
                                    class="w-5 h-5 text-[var(--text-muted)] flex-shrink-0"
                                />
                            </div>
                        </RouterLink>
                    </div>
                </Card>

                <!-- Recent Invoices -->
                <Card padding="none" class="overflow-hidden">
                    <div
                        class="p-4 border-b border-[var(--border-default)] flex items-center justify-between"
                    >
                        <h2 class="font-semibold text-[var(--text-primary)]">
                            Recent Invoices
                        </h2>
                        <RouterLink
                            to="/portal/invoices"
                            class="text-sm text-[var(--interactive-primary)] hover:underline"
                        >
                            View all
                        </RouterLink>
                    </div>

                    <div
                        v-if="dashboardData.recent_invoices.length === 0"
                        class="p-8 text-center text-[var(--text-muted)]"
                    >
                        No invoices found.
                    </div>

                    <div v-else class="divide-y divide-[var(--border-default)]">
                        <RouterLink
                            v-for="invoice in dashboardData.recent_invoices"
                            :key="invoice.public_id"
                            :to="`/portal/invoices/${invoice.public_id}`"
                            class="block p-4 hover:bg-[var(--surface-secondary)] transition-colors"
                        >
                            <div
                                class="flex items-center justify-between gap-4"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="font-medium text-[var(--text-primary)]"
                                            >{{ invoice.invoice_number }}</span
                                        >
                                        <Badge
                                            :variant="
                                                getStatusColor(invoice.status)
                                            "
                                            size="sm"
                                        >
                                            {{ invoice.status_label }}
                                        </Badge>
                                    </div>
                                    <div
                                        class="flex items-center gap-4 mt-1 text-sm text-[var(--text-muted)]"
                                    >
                                        <span>{{
                                            formatCurrency(invoice.total)
                                        }}</span>
                                        <span
                                            v-if="invoice.due_date"
                                            class="flex items-center gap-1"
                                        >
                                            <Clock class="w-3 h-3" />
                                            Due
                                            {{ formatDate(invoice.due_date) }}
                                        </span>
                                    </div>
                                </div>
                                <ChevronRight
                                    class="w-5 h-5 text-[var(--text-muted)] flex-shrink-0"
                                />
                            </div>
                        </RouterLink>
                    </div>
                </Card>
            </div>
        </template>
    </div>
</template>
