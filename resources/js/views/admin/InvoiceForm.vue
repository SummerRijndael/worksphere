<script setup lang="ts">
import { ref, onMounted, computed, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
    Card,
    Button,
    Input,
    PageLoader,
    SelectFilter,
    // DatePicker, Dropdown removed
} from "@/components/ui";
import {
    ArrowLeft,
    Plus,
    Trash2,
    Save,
    // Calendar, FileText, XCircle, CheckCircle2 removed
} from "lucide-vue-next";
import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import { toast } from "vue-sonner";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
// asDraft removed/checked (if it exists)

const isEditing = computed(() => !!route.params.id);
const invoiceId = computed(() => route.params.id as string);
const currentTeamId = computed(() => authStore.currentTeam?.public_id);

const isLoading = ref(isEditing.value);
const isSaving = ref(false);
const clients = ref<any[]>([]);
const projects = ref<any[]>([]);

const form = ref({
    client_id: "",
    project_id: "",
    issue_date: new Date().toISOString().split("T")[0],
    due_date: new Date(Date.now() + 14 * 24 * 60 * 60 * 1000)
        .toISOString()
        .split("T")[0],
    currency: "USD",
    items: [{ description: "", quantity: 1, unit_price: 0, total: 0 }],
    notes: "",
    terms: "",
    tax_rate: 0,
    discount_amount: 0,
});

const clientOptions = computed(() => {
    return (clients.value || []).map((c) => ({
        value: c.public_id,
        label: c.name,
    }));
});

const projectOptions = computed(() => {
    return (projects.value || []).map((p) => ({
        value: p.public_id,
        label: p.name,
    }));
});

// Templates Logic
import { invoiceTemplateService, type InvoiceTemplate } from '@/services/invoice-template.service';

const templates = ref<InvoiceTemplate[]>([]);
const selectedTemplateId = ref('');

const templateOptions = computed(() => {
    return templates.value.map(t => ({
        value: t.public_id,
        label: t.name
    }));
});

const fetchTemplates = async () => {
    if (!currentTeamId.value) return;
    try {
        const data = await invoiceTemplateService.getAll(currentTeamId.value);
        templates.value = data;
    } catch (error) {
        console.error('Failed to fetch templates', error);
    }
};

watch(() => selectedTemplateId.value, (newVal: string) => {
    const template = templates.value.find(t => t.public_id === newVal);
    
    if (template) {
        form.value.currency = template.currency || 'USD';
        form.value.notes = template.default_notes || '';
        form.value.terms = template.default_terms || '';
        
        if (template.line_items && template.line_items.length > 0) {
            form.value.items = template.line_items.map(item => ({
                description: item.description,
                quantity: Number(item.quantity),
                unit_price: Number(item.unit_price),
                total: Number(item.quantity) * Number(item.unit_price)
            }));
        }
        
        toast.success("Template loaded");
    }
});

const subtotal = computed(() => {
    return form.value.items.reduce(
        (acc, item) => acc + item.quantity * item.unit_price,
        0
    );
});

const taxAmount = computed(() => {
    return (subtotal.value * form.value.tax_rate) / 100;
});

const total = computed(() => {
    return Math.max(
        0,
        subtotal.value + taxAmount.value - form.value.discount_amount
    );
});

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: form.value.currency,
    }).format(amount);
};

const fetchData = async () => {
    if (!currentTeamId.value) return;

    try {
        const [clientsRes, projectsRes] = await Promise.all([
            axios.get(`/api/teams/${currentTeamId.value}/clients`),
            axios.get(`/api/teams/${currentTeamId.value}/projects`),
        ]);

        clients.value = clientsRes.data.data || [];
        projects.value = projectsRes.data.data || []; // Assuming project resource structure
        
        // Fetch templates
        fetchTemplates();

        if (isEditing.value) {
            const invoiceRes = await axios.get(
                `/api/teams/${currentTeamId.value}/invoices/${invoiceId.value}`
            );
            const invoice = invoiceRes.data.data;

            form.value = {
                client_id: invoice.client.public_id,
                project_id: invoice.project?.public_id || "",
                issue_date: invoice.issue_date.split("T")[0],
                due_date: invoice.due_date.split("T")[0],
                currency: invoice.currency,
                items: invoice.items.map((i: any) => ({
                    id: i.id,
                    description: i.description,
                    quantity: i.quantity,
                    unit_price: i.unit_price,
                    total: i.total,
                })),
                notes: invoice.notes || "",
                terms: invoice.terms || "",
                tax_rate: Number(invoice.tax_rate),
                discount_amount: Number(invoice.discount_amount),
            };
        }
    } catch (err) {
        console.error("Failed to load data", err);
        toast.error("Failed to load form data");
    } finally {
        isLoading.value = false;
    }
};

const addItem = () => {
    form.value.items.push({
        description: "",
        quantity: 1,
        unit_price: 0,
        total: 0,
    });
};

const removeItem = (index: number) => {
    form.value.items.splice(index, 1);
};

const calculateItemTotal = (index: number) => {
    const item = form.value.items[index];
    item.total = item.quantity * item.unit_price;
};

const saveInvoice = async () => {
    if (!currentTeamId.value) return;

    // Basic validation
    if (!form.value.client_id) {
        toast.error("Please select a client");
        return;
    }
    if (form.value.items.length === 0) {
        toast.error("Please add at least one line item");
        return;
    }
    if (form.value.items.some((i) => !i.description)) {
        toast.error("All items must have a description");
        return;
    }

    try {
        isSaving.value = true;

        const payload = {
            ...form.value,
            items: form.value.items.map((i) => ({
                id: (i as any).id, // include ID for existing items
                description: i.description,
                quantity: Number(i.quantity),
                unit_price: Number(i.unit_price),
            })),
            tax_rate: Number(form.value.tax_rate),
            discount_amount: Number(form.value.discount_amount),
        };

        if (isEditing.value) {
            await axios.put(
                `/api/teams/${currentTeamId.value}/invoices/${invoiceId.value}`,
                payload
            );
            toast.success("Invoice updated successfully");
        } else {
            const res = await axios.post(
                `/api/teams/${currentTeamId.value}/invoices`,
                payload
            );
            toast.success("Invoice created successfully");
            router.replace(`/admin/invoices/${res.data.data.public_id}/edit`);
            return; // Stay on edit page or redirect to detail
        }

        // After save, redirect to detail view
        if (isEditing.value) {
            router.push(`/admin/invoices/${invoiceId.value}`);
        }
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to save invoice");
    } finally {
        isSaving.value = false;
    }
};

const cancel = () => {
    if (isEditing.value) {
        router.push(`/admin/invoices/${invoiceId.value}`);
    } else {
        router.push("/admin/invoices");
    }
};

onMounted(() => {
    if (currentTeamId.value) {
        fetchData();
    }
});
</script>

<template>
    <div class="p-6 space-y-6 max-w-5xl mx-auto">
        <!-- Header -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="sm" @click="cancel">
                    <ArrowLeft class="w-4 h-4" />
                </Button>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                    {{ isEditing ? "Edit Invoice" : "New Invoice" }}
                </h1>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" @click="cancel">Cancel</Button>
                <Button @click="saveInvoice" :loading="isSaving">
                    <Save class="w-4 h-4 mr-2" />
                    Save Invoice
                </Button>
            </div>
        </div>

        <!-- Loading -->
        <PageLoader v-if="isLoading" />

        <!-- Form -->
        <form v-else @submit.prevent>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Template Selector -->
                    <Card v-if="!isEditing && templates.length > 0" padding="sm" class="bg-[var(--surface-secondary)] border-[var(--border-subtle)]">
                        <div class="flex items-center gap-4">
                            <span class="text-sm font-medium text-[var(--text-secondary)] whitespace-nowrap">Load Template:</span>
                            <SelectFilter
                                v-model="selectedTemplateId"
                                :options="templateOptions"
                                placeholder="Select a template..."
                                class="w-full max-w-md"
                            />
                        </div>
                    </Card>

                    <!-- Basic Info -->
                    <Card padding="lg" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >Client</label
                                >
                                <SelectFilter
                                    v-model="form.client_id"
                                    :options="clientOptions"
                                    placeholder="Select Client"
                                    class="w-full"
                                />
                            </div>
                            <div class="space-y-1">
                                <label
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >Project (Optional)</label
                                >
                                <SelectFilter
                                    v-model="form.project_id"
                                    :options="projectOptions"
                                    placeholder="Select Project"
                                    class="w-full"
                                />
                            </div>
                        </div>
                    </Card>

                    <!-- Items -->
                    <Card padding="none" class="overflow-hidden">
                        <div
                            class="p-4 border-b border-[var(--border-default)] flex justify-between items-center bg-[var(--surface-secondary)]"
                        >
                            <h2
                                class="font-semibold text-[var(--text-primary)]"
                            >
                                Line Items
                            </h2>
                        </div>

                        <div class="p-4 space-y-4">
                            <!-- Items Header -->
                            <div
                                class="hidden sm:grid grid-cols-12 gap-4 text-xs font-semibold uppercase text-[var(--text-muted)]"
                            >
                                <div class="col-span-6">Description</div>
                                <div class="col-span-2">Qty</div>
                                <div class="col-span-2">Price</div>
                                <div class="col-span-2 text-right">Total</div>
                            </div>

                            <!-- Items List -->
                            <div
                                v-for="(item, index) in form.items"
                                :key="index"
                                class="space-y-2 sm:space-y-0 relative group"
                            >
                                <div
                                    class="sm:grid grid-cols-12 gap-4 items-start"
                                >
                                    <div class="col-span-6">
                                        <Input
                                            v-model="item.description"
                                            placeholder="Item description"
                                        />
                                    </div>
                                    <div class="col-span-2">
                                        <Input
                                            type="number"
                                            v-model="item.quantity"
                                            min="0.1"
                                            step="0.01"
                                            @input="calculateItemTotal(index)"
                                        />
                                    </div>
                                    <div class="col-span-2">
                                        <Input
                                            type="number"
                                            v-model="item.unit_price"
                                            min="0"
                                            step="0.01"
                                            @input="calculateItemTotal(index)"
                                        />
                                    </div>
                                    <div
                                        class="col-span-2 text-right flex items-center justify-end gap-2"
                                    >
                                        <span
                                            class="font-medium text-[var(--text-primary)] py-2"
                                        >
                                            {{
                                                formatCurrency(
                                                    item.quantity *
                                                        item.unit_price
                                                )
                                            }}
                                        </span>
                                        <button
                                            @click="removeItem(index)"
                                            class="p-1 hover:bg-red-50 text-red-500 rounded sm:opacity-0 sm:group-hover:opacity-100 transition-opacity"
                                            tabindex="-1"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <Button
                                variant="outline"
                                size="sm"
                                @click="addItem"
                                class="w-full sm:w-auto"
                            >
                                <Plus class="w-4 h-4 mr-2" />
                                Add Item
                            </Button>
                        </div>
                    </Card>

                    <!-- Notes -->
                    <Card padding="lg" class="space-y-4">
                        <div class="space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-primary)]"
                                >Notes</label
                            >
                            <textarea
                                v-model="form.notes"
                                class="w-full rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)] focus:border-transparent min-h-[100px]"
                                placeholder="Additional notes for the client..."
                            ></textarea>
                        </div>
                        <div class="space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-primary)]"
                                >Terms</label
                            >
                            <textarea
                                v-model="form.terms"
                                class="w-full rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)] focus:border-transparent min-h-[100px]"
                                placeholder="Payment terms, warranty info, etc..."
                            ></textarea>
                        </div>
                    </Card>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Dates & Currency -->
                    <Card padding="lg" class="space-y-4">
                        <div class="space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-primary)]"
                                >Issue Date</label
                            >
                            <Input type="date" v-model="form.issue_date" />
                        </div>
                        <div class="space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-primary)]"
                                >Due Date</label
                            >
                            <Input type="date" v-model="form.due_date" />
                        </div>
                        <div class="space-y-1">
                            <label
                                class="text-sm font-medium text-[var(--text-primary)]"
                                >Currency</label
                            >
                            <Input
                                v-model="form.currency"
                                maxlength="3"
                                class="uppercase"
                            />
                        </div>
                    </Card>

                    <!-- Totals -->
                    <Card padding="lg" class="bg-[var(--surface-secondary)]">
                        <div class="space-y-3">
                            <div class="flex justify-between text-sm">
                                <span class="text-[var(--text-secondary)]"
                                    >Subtotal</span
                                >
                                <span class="text-[var(--text-primary)]">{{
                                    formatCurrency(subtotal)
                                }}</span>
                            </div>

                            <!-- Tax Input -->
                            <div
                                class="flex items-center justify-between text-sm"
                            >
                                <span class="text-[var(--text-secondary)]"
                                    >Tax Rate (%)</span
                                >
                                <input
                                    type="number"
                                    v-model="form.tax_rate"
                                    min="0"
                                    max="100"
                                    step="0.1"
                                    class="w-20 text-right rounded border border-[var(--border-default)] px-2 py-1 text-sm"
                                />
                            </div>
                            <div
                                class="flex justify-between text-sm text-[var(--text-secondary)]"
                            >
                                <span>Tax Amount</span>
                                <span>{{ formatCurrency(taxAmount) }}</span>
                            </div>

                            <!-- Discount Input -->
                            <div
                                class="flex items-center justify-between text-sm"
                            >
                                <span class="text-[var(--text-secondary)]"
                                    >Discount</span
                                >
                                <input
                                    type="number"
                                    v-model="form.discount_amount"
                                    min="0"
                                    step="0.01"
                                    class="w-24 text-right rounded border border-[var(--border-default)] px-2 py-1 text-sm"
                                />
                            </div>

                            <div
                                class="pt-3 border-t border-[var(--border-default)] flex justify-between font-bold text-lg"
                            >
                                <span class="text-[var(--text-primary)]"
                                    >Total</span
                                >
                                <span class="text-[var(--text-primary)]">{{
                                    formatCurrency(total)
                                }}</span>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>
        </form>
    </div>
</template>
