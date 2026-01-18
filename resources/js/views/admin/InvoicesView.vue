<script setup lang="ts">
import { ref, onMounted, watch, computed } from "vue";
import { useRouter, useRoute } from "vue-router";
import {
    Card,
    Button,
    Badge,
    PageLoader,
    Input,
    SelectFilter,
    Avatar,
    Dropdown,
} from "@/components/ui";
import {
    FileText,
    Search,
    Plus,
    ChevronLeft,
    ChevronRight,
    MoreHorizontal,
    Send,
    DollarSign,
    XCircle,
    Download,
    Eye,
    Pencil,
    Trash2,
    Clock,
    LayoutGrid,
    LayoutList,
    RefreshCw,
} from "lucide-vue-next";
import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import { toast } from "vue-sonner";

const router = useRouter();
// route removed
const authStore = useAuthStore();

const isLoading = ref(true);
const isRefreshing = ref(false);
const invoices = ref<any[]>([]);
const stats = ref({
    total: 0,
    draft: 0,
    sent: 0,
    paid: 0,
    overdue: 0,
    total_outstanding: 0,
});

const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 0,
});

const filters = ref({
    status: "",
    client_id: "",
    search: "",
    sort_by: "created_at",
    sort_order: "desc",
});

const viewMode = ref<"list" | "grid">("list");
// selectedInvoices removed

const currentTeamId = computed(() => authStore.currentTeam?.public_id);

const statusOptions = [
    { value: "", label: "All Statuses" },
    { value: "draft", label: "Draft" },
    { value: "sent", label: "Sent" },
    { value: "viewed", label: "Viewed" },
    { value: "paid", label: "Paid" },
    { value: "overdue", label: "Overdue" },
    { value: "cancelled", label: "Cancelled" },
];

const formatCurrency = (amount: number, currency: string = "USD") => {
    return new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: currency,
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

const getStatusVariant = (status: string) => {
    const variants: Record<string, string> = {
        draft: "secondary",
        sent: "info",
        viewed: "warning",
        paid: "success",
        overdue: "error",
        cancelled: "secondary",
    };
    return variants[status] || "secondary";
};

const fetchInvoices = async (page = 1) => {
    if (!currentTeamId.value) return;

    try {
        isLoading.value = true;

        const params: Record<string, any> = {
            page,
            per_page: pagination.value.per_page,
            sort_by: filters.value.sort_by,
            sort_order: filters.value.sort_order,
        };

        if (filters.value.status) params.status = filters.value.status;
        if (filters.value.client_id) params.client_id = filters.value.client_id;
        if (filters.value.search) params.search = filters.value.search;

        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/invoices`,
            { params }
        );
        invoices.value = response.data.data;
        pagination.value = {
            current_page: response.data.meta.current_page,
            last_page: response.data.meta.last_page,
            per_page: response.data.meta.per_page,
            total: response.data.meta.total,
        };
    } catch (err) {
        console.error("Failed to fetch invoices", err);
        toast.error("Failed to load invoices");
    } finally {
        isLoading.value = false;
    }
};

const fetchStats = async () => {
    if (!currentTeamId.value) return;

    try {
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/invoices/stats`
        );
        stats.value = response.data;
    } catch (err) {
        console.error("Failed to fetch stats", err);
    }
};

const refreshData = async () => {
    isRefreshing.value = true;
    await Promise.all([
        fetchInvoices(pagination.value.current_page),
        fetchStats(),
    ]);
    isRefreshing.value = false;
};

const changePage = (page: number) => {
    if (page >= 1 && page <= pagination.value.last_page) {
        fetchInvoices(page);
    }
};

const viewInvoice = (invoice: any) => {
    router.push(`/admin/invoices/${invoice.public_id}`);
};

const createInvoice = () => {
    router.push("/admin/invoices/create");
};

const sendInvoice = async (invoice: any) => {
    if (!currentTeamId.value) return;

    try {
        await axios.post(
            `/api/teams/${currentTeamId.value}/invoices/${invoice.public_id}/send`
        );
        toast.success("Invoice sent successfully");
        refreshData();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to send invoice");
    }
};

const recordPayment = async (invoice: any) => {
    if (!currentTeamId.value) return;

    try {
        await axios.post(
            `/api/teams/${currentTeamId.value}/invoices/${invoice.public_id}/record-payment`
        );
        toast.success("Payment recorded successfully");
        refreshData();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to record payment");
    }
};

const cancelInvoice = async (invoice: any) => {
    if (!currentTeamId.value) return;
    if (!confirm("Are you sure you want to cancel this invoice?")) return;

    try {
        await axios.post(
            `/api/teams/${currentTeamId.value}/invoices/${invoice.public_id}/cancel`
        );
        toast.success("Invoice cancelled");
        refreshData();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to cancel invoice");
    }
};

const deleteInvoice = async (invoice: any) => {
    if (!currentTeamId.value) return;
    if (
        !confirm(
            "Are you sure you want to delete this invoice? This action cannot be undone."
        )
    )
        return;

    try {
        await axios.delete(
            `/api/teams/${currentTeamId.value}/invoices/${invoice.public_id}`
        );
        toast.success("Invoice deleted");
        refreshData();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to delete invoice");
    }
};

const downloadPdf = async (invoice: any) => {
    if (!currentTeamId.value) return;

    try {
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/invoices/${invoice.public_id}/download-pdf`,
            { responseType: "blob" }
        );

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement("a");
        link.href = url;
        link.setAttribute("download", `Invoice-${invoice.invoice_number}.pdf`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (err) {
        toast.error("Failed to download PDF");
    }
};

const getInvoiceActions = (invoice: any) => {
    const actions = [
        {
            label: "View Details",
            icon: Eye,
            action: () => viewInvoice(invoice),
        },
    ];

    if (invoice.can_edit) {
        actions.push({
            label: "Edit",
            icon: Pencil,
            action: () =>
                router.push(`/admin/invoices/${invoice.public_id}/edit`),
        });
    }

    if (invoice.can_send) {
        actions.push({
            label: "Send to Client",
            icon: Send,
            action: () => sendInvoice(invoice),
        });
    }

    if (invoice.can_record_payment) {
        actions.push({
            label: "Record Payment",
            icon: DollarSign,
            action: () => recordPayment(invoice),
        });
    }

    if (invoice.status !== "paid" && invoice.status !== "cancelled") {
        actions.push({
            label: "Cancel",
            icon: XCircle,
            action: () => cancelInvoice(invoice),
            variant: "danger",
        });
    }

    actions.push({
        label: "Download PDF",
        icon: Download,
        action: () => downloadPdf(invoice),
    });

    if (invoice.status === "draft") {
        actions.push({
            label: "Delete",
            icon: Trash2,
            action: () => deleteInvoice(invoice),
            variant: "danger",
        });
    }

    return actions;
};

// Debounced search
let searchTimeout: ReturnType<typeof setTimeout>;
const onSearchChange = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchInvoices(1);
    }, 300);
};

watch(
    () => filters.value.status,
    () => fetchInvoices(1)
);
watch(
    () => currentTeamId.value,
    () => {
        if (currentTeamId.value) {
            fetchInvoices();
            fetchStats();
        }
    },
    { immediate: true }
);

onMounted(() => {
    if (currentTeamId.value) {
        fetchInvoices();
        fetchStats();
    }
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
                    Invoices
                </h1>
                <p class="text-[var(--text-secondary)]">
                    Manage invoices and track payments
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :loading="isRefreshing"
                    @click="refreshData"
                >
                    <RefreshCw class="w-4 h-4" />
                </Button>
                <Button @click="createInvoice">
                    <Plus class="w-4 h-4 mr-2" />
                    New Invoice
                </Button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
            <Card
                padding="md"
                class="cursor-pointer hover:shadow-md transition-shadow"
                @click="filters.status = ''"
            >
                <div class="text-center">
                    <p class="text-2xl font-bold text-[var(--text-primary)]">
                        {{ stats.total }}
                    </p>
                    <p class="text-xs text-[var(--text-muted)]">Total</p>
                </div>
            </Card>
            <Card
                padding="md"
                class="cursor-pointer hover:shadow-md transition-shadow"
                @click="filters.status = 'draft'"
            >
                <div class="text-center">
                    <p class="text-2xl font-bold text-gray-600">
                        {{ stats.draft }}
                    </p>
                    <p class="text-xs text-[var(--text-muted)]">Draft</p>
                </div>
            </Card>
            <Card
                padding="md"
                class="cursor-pointer hover:shadow-md transition-shadow"
                @click="filters.status = 'sent'"
            >
                <div class="text-center">
                    <p class="text-2xl font-bold text-blue-600">
                        {{ stats.sent }}
                    </p>
                    <p class="text-xs text-[var(--text-muted)]">Sent</p>
                </div>
            </Card>
            <Card
                padding="md"
                class="cursor-pointer hover:shadow-md transition-shadow"
                @click="filters.status = 'paid'"
            >
                <div class="text-center">
                    <p class="text-2xl font-bold text-green-600">
                        {{ stats.paid }}
                    </p>
                    <p class="text-xs text-[var(--text-muted)]">Paid</p>
                </div>
            </Card>
            <Card
                padding="md"
                class="cursor-pointer hover:shadow-md transition-shadow"
                @click="filters.status = 'overdue'"
            >
                <div class="text-center">
                    <p class="text-2xl font-bold text-red-600">
                        {{ stats.overdue }}
                    </p>
                    <p class="text-xs text-[var(--text-muted)]">Overdue</p>
                </div>
            </Card>
            <Card padding="md">
                <div class="text-center">
                    <p class="text-xl font-bold text-amber-600">
                        {{ formatCurrency(stats.total_outstanding) }}
                    </p>
                    <p class="text-xs text-[var(--text-muted)]">Outstanding</p>
                </div>
            </Card>
        </div>

        <!-- Filters -->
        <div
            class="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between"
        >
            <div class="flex flex-col sm:flex-row gap-4 flex-1">
                <div class="w-full sm:w-64">
                    <Input
                        v-model="filters.search"
                        placeholder="Search invoices..."
                        @input="onSearchChange"
                    >
                        <template #prefix>
                            <Search class="w-4 h-4 text-[var(--text-muted)]" />
                        </template>
                    </Input>
                </div>
                <SelectFilter
                    v-model="filters.status"
                    :options="statusOptions"
                    placeholder="Filter by status"
                    class="w-full sm:w-44"
                />
            </div>
            <div class="flex items-center gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    :class="{
                        'bg-[var(--surface-secondary)]': viewMode === 'list',
                    }"
                    @click="viewMode = 'list'"
                >
                    <LayoutList class="w-4 h-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    :class="{
                        'bg-[var(--surface-secondary)]': viewMode === 'grid',
                    }"
                    @click="viewMode = 'grid'"
                >
                    <LayoutGrid class="w-4 h-4" />
                </Button>
            </div>
        </div>

        <!-- Loading State -->
        <PageLoader v-if="isLoading" />

        <!-- Empty State -->
        <Card
            v-else-if="invoices.length === 0"
            padding="lg"
            class="text-center"
        >
            <FileText class="w-12 h-12 mx-auto text-[var(--text-muted)] mb-4" />
            <p class="text-[var(--text-primary)] font-medium">
                No invoices found
            </p>
            <p class="text-sm text-[var(--text-muted)] mt-1 mb-4">
                {{
                    filters.status || filters.search
                        ? "Try adjusting your filters"
                        : "Create your first invoice to get started"
                }}
            </p>
            <Button
                v-if="!filters.status && !filters.search"
                @click="createInvoice"
            >
                <Plus class="w-4 h-4 mr-2" />
                Create Invoice
            </Button>
        </Card>

        <!-- Invoice List -->
        <template v-else>
            <!-- List View -->
            <Card
                v-if="viewMode === 'list'"
                padding="none"
                class="overflow-hidden"
            >
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-[var(--surface-secondary)]">
                            <tr>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Invoice
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Client
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Status
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Amount
                                </th>
                                <th
                                    class="px-4 py-3 text-left text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Due Date
                                </th>
                                <th
                                    class="px-4 py-3 text-right text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border-default)]">
                            <tr
                                v-for="invoice in invoices"
                                :key="invoice.public_id"
                                class="hover:bg-[var(--surface-secondary)] transition-colors cursor-pointer"
                                @click="viewInvoice(invoice)"
                            >
                                <td class="px-4 py-4">
                                    <div
                                        class="font-medium text-[var(--text-primary)]"
                                    >
                                        {{ invoice.invoice_number }}
                                    </div>
                                    <div
                                        v-if="invoice.project"
                                        class="text-xs text-[var(--text-muted)]"
                                    >
                                        {{ invoice.project.name }}
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-2">
                                        <Avatar
                                            :name="invoice.client?.name"
                                            :src="invoice.client?.avatar_url"
                                            size="sm"
                                        />
                                        <span
                                            class="text-[var(--text-secondary)]"
                                            >{{ invoice.client?.name }}</span
                                        >
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <Badge
                                        :variant="
                                            getStatusVariant(invoice.status)
                                        "
                                        size="sm"
                                    >
                                        {{ invoice.status_label }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-4 text-right">
                                    <span
                                        class="font-semibold text-[var(--text-primary)]"
                                    >
                                        {{
                                            formatCurrency(
                                                invoice.total,
                                                invoice.currency
                                            )
                                        }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <div
                                        class="flex items-center gap-1 text-sm"
                                        :class="
                                            invoice.is_overdue
                                                ? 'text-[var(--color-error)]'
                                                : 'text-[var(--text-muted)]'
                                        "
                                    >
                                        <Clock class="w-3 h-3" />
                                        {{ formatDate(invoice.due_date) }}
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-right" @click.stop>
                                    <Dropdown
                                        :items="getInvoiceActions(invoice)"
                                        align="end"
                                    >
                                        <template #trigger>
                                            <Button variant="ghost" size="sm">
                                                <MoreHorizontal
                                                    class="w-4 h-4"
                                                />
                                            </Button>
                                        </template>
                                    </Dropdown>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <!-- Grid View -->
            <div
                v-else
                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"
            >
                <Card
                    v-for="invoice in invoices"
                    :key="invoice.public_id"
                    padding="none"
                    class="overflow-hidden cursor-pointer hover:shadow-lg transition-shadow"
                    @click="viewInvoice(invoice)"
                >
                    <div class="p-4">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <div
                                    class="font-semibold text-[var(--text-primary)]"
                                >
                                    {{ invoice.invoice_number }}
                                </div>
                                <div class="text-sm text-[var(--text-muted)]">
                                    {{ invoice.client?.name }}
                                </div>
                            </div>
                            <Badge
                                :variant="getStatusVariant(invoice.status)"
                                size="sm"
                            >
                                {{ invoice.status_label }}
                            </Badge>
                        </div>

                        <div class="flex items-center justify-between mt-4">
                            <span
                                class="text-xl font-bold text-[var(--text-primary)]"
                            >
                                {{
                                    formatCurrency(
                                        invoice.total,
                                        invoice.currency
                                    )
                                }}
                            </span>
                            <div
                                class="flex items-center gap-1 text-sm"
                                :class="
                                    invoice.is_overdue
                                        ? 'text-[var(--color-error)]'
                                        : 'text-[var(--text-muted)]'
                                "
                            >
                                <Clock class="w-3 h-3" />
                                {{ formatDate(invoice.due_date) }}
                            </div>
                        </div>
                    </div>

                    <div
                        class="border-t border-[var(--border-default)] px-4 py-2 bg-[var(--surface-secondary)] flex justify-end"
                        @click.stop
                    >
                        <Dropdown
                            :items="getInvoiceActions(invoice)"
                            align="end"
                        >
                            <template #trigger>
                                <Button variant="ghost" size="sm">
                                    <MoreHorizontal class="w-4 h-4" />
                                </Button>
                            </template>
                        </Dropdown>
                    </div>
                </Card>
            </div>

            <!-- Pagination -->
            <div
                v-if="pagination.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-sm text-[var(--text-muted)]">
                    Showing
                    {{
                        (pagination.current_page - 1) * pagination.per_page + 1
                    }}
                    to
                    {{
                        Math.min(
                            pagination.current_page * pagination.per_page,
                            pagination.total
                        )
                    }}
                    of {{ pagination.total }} invoices
                </p>
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="pagination.current_page === 1"
                        @click="changePage(pagination.current_page - 1)"
                    >
                        <ChevronLeft class="w-4 h-4" />
                    </Button>
                    <span class="text-sm text-[var(--text-secondary)]">
                        Page {{ pagination.current_page }} of
                        {{ pagination.last_page }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                            pagination.current_page === pagination.last_page
                        "
                        @click="changePage(pagination.current_page + 1)"
                    >
                        <ChevronRight class="w-4 h-4" />
                    </Button>
                </div>
            </div>
        </template>
    </div>
</template>
