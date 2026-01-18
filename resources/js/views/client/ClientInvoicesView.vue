<script setup lang="ts">
import { ref, onMounted, watch, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
    Card,
    Button,
    Badge,
    PageLoader,
    Input,
    SelectFilter,
} from "@/components/ui";
import {
    FileText,
    Search,
    Download,
    ChevronLeft,
    ChevronRight,
    Clock,
    DollarSign,
    AlertCircle,
} from "lucide-vue-next";
import axios from "axios";

const route = useRoute();
const router = useRouter();

const isLoading = ref(true);
const error = ref<string | null>(null);
const invoices = ref<any[]>([]);
const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});

const filters = ref({
    status: (route.query.status as string) || "",
    search: "",
});

const statusOptions = [
    { value: "", label: "All Invoices" },
    { value: "draft", label: "Draft" },
    { value: "sent", label: "Sent" },
    { value: "viewed", label: "Viewed" },
    { value: "paid", label: "Paid" },
    { value: "overdue", label: "Overdue" },
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

const isOverdue = (invoice: any) => {
    return (
        invoice.status === "overdue" ||
        (invoice.is_overdue && !["paid", "cancelled"].includes(invoice.status))
    );
};

const fetchInvoices = async (page = 1) => {
    try {
        isLoading.value = true;
        error.value = null;

        const params: Record<string, any> = {
            page,
            per_page: pagination.value.per_page,
        };

        if (filters.value.status) {
            params.status = filters.value.status;
        }
        if (filters.value.search) {
            params.search = filters.value.search;
        }

        const response = await axios.get("/api/client-portal/invoices", {
            params,
        });
        invoices.value = response.data.data;
        pagination.value = {
            current_page: response.data.meta.current_page,
            last_page: response.data.meta.last_page,
            per_page: response.data.meta.per_page,
            total: response.data.meta.total,
        };
    } catch (err: any) {
        console.error("Failed to fetch invoices", err);
        if (err.response?.status === 404) {
            error.value = "No client profile found for your account.";
        } else {
            error.value = "Failed to load invoices. Please try again.";
        }
    } finally {
        isLoading.value = false;
    }
};

const changePage = (page: number) => {
    if (page >= 1 && page <= pagination.value.last_page) {
        fetchInvoices(page);
    }
};

const viewInvoice = (invoice: any) => {
    router.push(`/portal/invoices/${invoice.public_id}`);
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
    () => {
        fetchInvoices(1);
    }
);

onMounted(() => {
    fetchInvoices();
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
                    View and manage your invoices
                </p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex-1 max-w-xs">
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
                class="w-48"
            />
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

        <!-- Invoices List -->
        <template v-else>
            <Card v-if="invoices.length === 0" padding="lg" class="text-center">
                <FileText
                    class="w-12 h-12 mx-auto text-[var(--text-muted)] mb-4"
                />
                <p class="text-[var(--text-primary)]">No invoices found</p>
                <p class="text-sm text-[var(--text-muted)] mt-1">
                    {{
                        filters.status || filters.search
                            ? "Try adjusting your filters"
                            : "You don't have any invoices yet"
                    }}
                </p>
            </Card>

            <div v-else class="space-y-4">
                <!-- Invoice Cards -->
                <Card
                    v-for="invoice in invoices"
                    :key="invoice.public_id"
                    padding="none"
                    class="overflow-hidden cursor-pointer hover:shadow-lg transition-shadow"
                    @click="viewInvoice(invoice)"
                >
                    <div class="p-4 sm:p-6">
                        <div
                            class="flex flex-col sm:flex-row sm:items-center justify-between gap-4"
                        >
                            <!-- Invoice Info -->
                            <div class="flex items-start gap-4">
                                <div
                                    class="p-3 rounded-lg bg-[var(--surface-secondary)]"
                                >
                                    <FileText
                                        class="w-6 h-6 text-[var(--text-secondary)]"
                                    />
                                </div>
                                <div>
                                    <div
                                        class="flex items-center gap-2 flex-wrap"
                                    >
                                        <span
                                            class="font-semibold text-[var(--text-primary)]"
                                            >{{ invoice.invoice_number }}</span
                                        >
                                        <Badge
                                            :variant="
                                                getStatusVariant(invoice.status)
                                            "
                                            size="sm"
                                        >
                                            {{ invoice.status_label }}
                                        </Badge>
                                    </div>
                                    <p
                                        v-if="invoice.project"
                                        class="text-sm text-[var(--text-secondary)] mt-1"
                                    >
                                        {{ invoice.project.name }}
                                    </p>
                                    <div
                                        class="flex items-center gap-4 mt-2 text-sm text-[var(--text-muted)]"
                                    >
                                        <span
                                            >Issued:
                                            {{
                                                formatDate(invoice.issue_date)
                                            }}</span
                                        >
                                        <span
                                            :class="{
                                                'text-[var(--color-error)]':
                                                    isOverdue(invoice),
                                            }"
                                        >
                                            <Clock
                                                class="w-3 h-3 inline mr-1"
                                            />
                                            Due:
                                            {{ formatDate(invoice.due_date) }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Amount -->
                            <div
                                class="text-right sm:text-left sm:min-w-[120px]"
                            >
                                <p
                                    class="text-xl font-bold text-[var(--text-primary)]"
                                >
                                    {{
                                        formatCurrency(
                                            invoice.total,
                                            invoice.currency
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="invoice.items_count"
                                    class="text-sm text-[var(--text-muted)]"
                                >
                                    {{ invoice.items_count }} item{{
                                        invoice.items_count !== 1 ? "s" : ""
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Pagination -->
                <div
                    v-if="pagination.last_page > 1"
                    class="flex items-center justify-between"
                >
                    <p class="text-sm text-[var(--text-muted)]">
                        Showing
                        {{
                            (pagination.current_page - 1) *
                                pagination.per_page +
                            1
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
            </div>
        </template>
    </div>
</template>
