<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
    Card,
    Button,
    Badge,
    PageLoader,
    Dropdown,
    Avatar,
} from "@/components/ui";
import {
    ArrowLeft,
    Download,
    DollarSign,
    Printer,
    Send,
    Pencil,
    Trash2,
    MoreHorizontal,
    XCircle,
} from "lucide-vue-next";
import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import { toast } from "vue-sonner";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const isLoading = ref(true);
const isProcessing = ref(false);
const invoice = ref<any>(null);

const invoiceId = computed(() => route.params.id as string);
const currentTeamId = computed(() => authStore.currentTeam?.public_id);

const formatCurrency = (amount: number, currency: string = "USD") => {
    return new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: currency,
        minimumFractionDigits: 2,
    }).format(amount);
};

// formatDate removed

const formatShortDate = (dateString: string) => {
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

const fetchInvoice = async () => {
    if (!currentTeamId.value) return;

    try {
        isLoading.value = true;
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/invoices/${invoiceId.value}`
        );
        invoice.value = response.data.data;
    } catch (err: any) {
        console.error("Failed to fetch invoice", err);
        toast.error("Failed to load invoice");
    } finally {
        isLoading.value = false;
    }
};

const deleteInvoice = async () => {
    if (!currentTeamId.value || !invoice.value) return;
    if (
        !confirm(
            "Are you sure you want to delete this invoice? This action cannot be undone."
        )
    )
        return;

    try {
        isProcessing.value = true;
        await axios.delete(
            `/api/teams/${currentTeamId.value}/invoices/${invoice.value.public_id}`
        );
        toast.success("Invoice deleted");
        router.push("/admin/invoices");
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to delete invoice");
    } finally {
        isProcessing.value = false;
    }
};

const sendInvoice = async () => {
    if (!currentTeamId.value || !invoice.value) return;

    try {
        isProcessing.value = true;
        await axios.post(
            `/api/teams/${currentTeamId.value}/invoices/${invoice.value.public_id}/send`
        );
        toast.success("Invoice sent successfully");
        fetchInvoice();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to send invoice");
    } finally {
        isProcessing.value = false;
    }
};

const recordPayment = async () => {
    if (!currentTeamId.value || !invoice.value) return;

    try {
        isProcessing.value = true;
        await axios.post(
            `/api/teams/${currentTeamId.value}/invoices/${invoice.value.public_id}/record-payment`
        );
        toast.success("Payment recorded successfully");
        fetchInvoice();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to record payment");
    } finally {
        isProcessing.value = false;
    }
};

const cancelInvoice = async () => {
    if (!currentTeamId.value || !invoice.value) return;
    if (!confirm("Are you sure you want to cancel this invoice?")) return;

    try {
        isProcessing.value = true;
        await axios.post(
            `/api/teams/${currentTeamId.value}/invoices/${invoice.value.public_id}/cancel`
        );
        toast.success("Invoice cancelled");
        fetchInvoice();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to cancel invoice");
    } finally {
        isProcessing.value = false;
    }
};

const printInvoice = () => {
    window.print();
};

const downloadPdf = async () => {
    if (!currentTeamId.value || !invoice.value) return;

    try {
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/invoices/${invoice.value.public_id}/download-pdf`,
            { responseType: "blob" }
        );

        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement("a");
        link.href = url;
        link.setAttribute(
            "download",
            `Invoice-${invoice.value.invoice_number}.pdf`
        );
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
    } catch (err) {
        toast.error("Failed to download PDF");
    }
};

const editInvoice = () => {
    router.push(`/admin/invoices/${invoiceId.value}/edit`);
};

const getActions = () => {
    if (!invoice.value) return [];

    const actions = [];

    if (invoice.value.can_edit) {
        actions.push({
            label: "Edit Invoice",
            icon: Pencil,
            action: editInvoice,
        });
    }

    if (invoice.value.can_send) {
        actions.push({
            label: "Send to Client",
            icon: Send,
            action: sendInvoice,
        });
    }

    if (invoice.value.can_record_payment) {
        actions.push({
            label: "Record Payment",
            icon: DollarSign,
            action: recordPayment,
        });
    }

    if (
        invoice.value.status !== "paid" &&
        invoice.value.status !== "cancelled"
    ) {
        actions.push({
            label: "Cancel Invoice",
            icon: XCircle,
            action: cancelInvoice,
            variant: "danger",
        });
    }

    actions.push({
        label: "Download PDF",
        icon: Download,
        action: downloadPdf,
    });

    if (invoice.value.status === "draft") {
        actions.push({
            label: "Delete Invoice",
            icon: Trash2,
            action: deleteInvoice,
            variant: "danger",
        });
    }

    return actions;
};

const goBack = () => {
    router.push("/admin/invoices");
};

onMounted(() => {
    if (currentTeamId.value) {
        fetchInvoice();
    }
});
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div
            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4"
        >
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="sm" @click="goBack">
                    <ArrowLeft class="w-4 h-4" />
                </Button>
                <div>
                    <div class="flex items-center gap-3">
                        <h1
                            class="text-2xl font-bold text-[var(--text-primary)]"
                        >
                            {{ invoice?.invoice_number || "Loading..." }}
                        </h1>
                        <Badge
                            v-if="invoice"
                            :variant="getStatusVariant(invoice.status)"
                            size="md"
                        >
                            {{ invoice.status_label }}
                        </Badge>
                    </div>
                    <p class="text-[var(--text-secondary)]">
                        {{ invoice?.project?.name || "Invoice Details" }}
                    </p>
                </div>
            </div>

            <div v-if="invoice" class="flex items-center gap-2">
                <!-- Primary Actions -->
                <Button
                    v-if="invoice.can_send"
                    @click="sendInvoice"
                    :loading="isProcessing"
                >
                    <Send class="w-4 h-4 mr-2" />
                    Send
                </Button>
                <Button
                    v-else-if="invoice.can_record_payment"
                    @click="recordPayment"
                    :loading="isProcessing"
                >
                    <DollarSign class="w-4 h-4 mr-2" />
                    Record Payment
                </Button>

                <!-- More Actions -->
                <Button
                    variant="outline"
                    @click="downloadPdf"
                    :loading="isProcessing"
                    title="Download PDF"
                >
                    <Download class="w-4 h-4" />
                </Button>

                <Button
                    variant="outline"
                    @click="printInvoice"
                    title="Print Invoice"
                >
                    <Printer class="w-4 h-4" />
                </Button>

                <Dropdown :items="getActions()" align="end">
                    <template #trigger>
                        <Button variant="outline" :loading="isProcessing">
                            <MoreHorizontal class="w-4 h-4" />
                        </Button>
                    </template>
                </Dropdown>
            </div>
        </div>

        <!-- Loading State -->
        <PageLoader v-if="isLoading" />

        <!-- Detail Content -->
        <template v-else-if="invoice">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Main Invoice -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Invoice Header Card -->
                    <Card padding="lg">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <!-- From -->
                            <div>
                                <p
                                    class="text-xs font-semibold uppercase text-[var(--text-muted)] mb-2"
                                >
                                    From
                                </p>
                                <div class="flex items-center gap-3">
                                    <Avatar
                                        :name="invoice.team?.name"
                                        :src="invoice.team?.logo_url"
                                        size="md"
                                    />
                                    <div>
                                        <p
                                            class="font-semibold text-[var(--text-primary)]"
                                        >
                                            {{ invoice.team?.name }}
                                        </p>
                                        <p
                                            class="text-sm text-[var(--text-secondary)]"
                                        >
                                            {{ invoice.owner?.name }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <!-- To -->
                            <div>
                                <p
                                    class="text-xs font-semibold uppercase text-[var(--text-muted)] mb-2"
                                >
                                    Bill To
                                </p>
                                <div class="flex items-center gap-3">
                                    <Avatar
                                        :name="invoice.client?.name"
                                        :src="invoice.client?.avatar_url"
                                        size="md"
                                    />
                                    <div>
                                        <p
                                            class="font-semibold text-[var(--text-primary)]"
                                        >
                                            {{ invoice.client?.name }}
                                        </p>
                                        <p
                                            v-if="invoice.client?.email"
                                            class="text-sm text-[var(--text-secondary)]"
                                        >
                                            {{ invoice.client.email }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <!-- Line Items -->
                    <Card padding="none" class="overflow-hidden">
                        <div
                            class="p-4 border-b border-[var(--border-default)] flex justify-between items-center"
                        >
                            <h2
                                class="font-semibold text-[var(--text-primary)]"
                            >
                                Line Items
                            </h2>
                            <Button
                                v-if="invoice.can_edit"
                                variant="ghost"
                                size="sm"
                                @click="editInvoice"
                            >
                                <Pencil class="w-4 h-4 mr-2" />
                                Edit Items
                            </Button>
                        </div>

                        <!-- Table Header -->
                        <div
                            class="hidden sm:grid grid-cols-12 gap-4 p-4 bg-[var(--surface-secondary)] text-xs font-semibold uppercase text-[var(--text-muted)]"
                        >
                            <div class="col-span-6">Description</div>
                            <div class="col-span-2 text-right">Quantity</div>
                            <div class="col-span-2 text-right">Unit Price</div>
                            <div class="col-span-2 text-right">Total</div>
                        </div>

                        <!-- Items -->
                        <div class="divide-y divide-[var(--border-default)]">
                            <div
                                v-for="item in invoice.items"
                                :key="item.id"
                                class="p-4"
                            >
                                <div
                                    class="sm:grid grid-cols-12 gap-4 space-y-2 sm:space-y-0"
                                >
                                    <div class="col-span-6">
                                        <p
                                            class="font-medium text-[var(--text-primary)]"
                                        >
                                            {{ item.description }}
                                        </p>
                                    </div>
                                    <div
                                        class="col-span-2 text-right text-[var(--text-secondary)]"
                                    >
                                        <span
                                            class="sm:hidden text-[var(--text-muted)]"
                                            >Qty:
                                        </span>
                                        {{ item.quantity }}
                                    </div>
                                    <div
                                        class="col-span-2 text-right text-[var(--text-secondary)]"
                                    >
                                        <span
                                            class="sm:hidden text-[var(--text-muted)]"
                                            >Unit:
                                        </span>
                                        {{
                                            formatCurrency(
                                                item.unit_price,
                                                invoice.currency
                                            )
                                        }}
                                    </div>
                                    <div
                                        class="col-span-2 text-right font-medium text-[var(--text-primary)]"
                                    >
                                        {{
                                            formatCurrency(
                                                item.total,
                                                invoice.currency
                                            )
                                        }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Totals -->
                        <div
                            class="border-t border-[var(--border-default)] p-4 bg-[var(--surface-secondary)]"
                        >
                            <div class="max-w-xs ml-auto space-y-2">
                                <div
                                    class="flex justify-between text-[var(--text-secondary)]"
                                >
                                    <span>Subtotal</span>
                                    <span>{{
                                        formatCurrency(
                                            invoice.subtotal,
                                            invoice.currency
                                        )
                                    }}</span>
                                </div>
                                <div
                                    v-if="invoice.tax_rate > 0"
                                    class="flex justify-between text-[var(--text-secondary)]"
                                >
                                    <span>Tax ({{ invoice.tax_rate }}%)</span>
                                    <span>{{
                                        formatCurrency(
                                            invoice.tax_amount,
                                            invoice.currency
                                        )
                                    }}</span>
                                </div>
                                <div
                                    v-if="invoice.discount_amount > 0"
                                    class="flex justify-between text-[var(--text-secondary)]"
                                >
                                    <span>Discount</span>
                                    <span
                                        >-{{
                                            formatCurrency(
                                                invoice.discount_amount,
                                                invoice.currency
                                            )
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="flex justify-between pt-2 border-t border-[var(--border-default)] font-bold text-lg text-[var(--text-primary)]"
                                >
                                    <span>Total</span>
                                    <span>{{
                                        formatCurrency(
                                            invoice.total,
                                            invoice.currency
                                        )
                                    }}</span>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <!-- Notes & Terms -->
                    <div
                        v-if="invoice.notes || invoice.terms"
                        class="grid grid-cols-1 md:grid-cols-2 gap-6"
                    >
                        <Card v-if="invoice.notes" padding="lg">
                            <h3
                                class="font-semibold text-[var(--text-primary)] mb-2"
                            >
                                Notes
                            </h3>
                            <p
                                class="text-sm text-[var(--text-secondary)] whitespace-pre-wrap"
                            >
                                {{ invoice.notes }}
                            </p>
                        </Card>
                        <Card v-if="invoice.terms" padding="lg">
                            <h3
                                class="font-semibold text-[var(--text-primary)] mb-2"
                            >
                                Terms & Conditions
                            </h3>
                            <p
                                class="text-sm text-[var(--text-secondary)] whitespace-pre-wrap"
                            >
                                {{ invoice.terms }}
                            </p>
                        </Card>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Invoice Info -->
                    <Card padding="lg">
                        <h3
                            class="font-semibold text-[var(--text-primary)] mb-4"
                        >
                            Invoice Details
                        </h3>
                        <dl class="space-y-4">
                            <div>
                                <dt
                                    class="text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Invoice Number
                                </dt>
                                <dd class="text-[var(--text-primary)]">
                                    {{ invoice.invoice_number }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Issue Date
                                </dt>
                                <dd class="text-[var(--text-primary)]">
                                    {{ formatShortDate(invoice.issue_date) }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Due Date
                                </dt>
                                <dd
                                    class="text-[var(--text-primary)]"
                                    :class="{
                                        'text-[var(--color-error)]':
                                            invoice.is_overdue,
                                    }"
                                >
                                    {{ formatShortDate(invoice.due_date) }}
                                    <span
                                        v-if="invoice.is_overdue"
                                        class="text-xs ml-1"
                                        >(Overdue)</span
                                    >
                                </dd>
                            </div>
                            <div v-if="invoice.paid_at">
                                <dt
                                    class="text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Paid Date
                                </dt>
                                <dd class="text-[var(--color-success)]">
                                    {{ formatShortDate(invoice.paid_at) }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Currency
                                </dt>
                                <dd class="text-[var(--text-primary)]">
                                    {{ invoice.currency }}
                                </dd>
                            </div>
                            <div v-if="invoice.public_view_url">
                                <dt
                                    class="text-xs font-semibold uppercase text-[var(--text-muted)]"
                                >
                                    Client Link
                                </dt>
                                <dd class="text-sm truncate">
                                    <a
                                        :href="invoice.public_view_url"
                                        target="_blank"
                                        class="text-[var(--interactive-primary)] hover:underline"
                                    >
                                        View as Client
                                    </a>
                                </dd>
                            </div>
                        </dl>
                    </Card>

                    <!-- Amount Due Card -->
                    <Card
                        padding="lg"
                        :class="
                            invoice.status === 'paid'
                                ? 'bg-green-50 dark:bg-green-900/10 border-green-200 dark:border-green-800'
                                : 'bg-[var(--surface-secondary)]'
                        "
                    >
                        <div class="text-center">
                            <p
                                class="text-sm font-semibold uppercase text-[var(--text-muted)] mb-2"
                            >
                                {{
                                    invoice.status === "paid"
                                        ? "Amount Paid"
                                        : "Amount Due"
                                }}
                            </p>
                            <p
                                class="text-3xl font-bold"
                                :class="
                                    invoice.status === 'paid'
                                        ? 'text-[var(--color-success)]'
                                        : 'text-[var(--text-primary)]'
                                "
                            >
                                {{
                                    formatCurrency(
                                        invoice.total,
                                        invoice.currency
                                    )
                                }}
                            </p>
                        </div>
                    </Card>
                </div>
            </div>
        </template>
    </div>
</template>
