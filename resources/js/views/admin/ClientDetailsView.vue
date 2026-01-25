<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '@/lib/api';
import { Button } from '@/components/ui';
import { useAuthStore } from '@/stores/auth';
import { toast } from 'vue-sonner';
import ClientFormModal from '@/components/clients/ClientFormModal.vue';
import {
    Briefcase,
    FileText,
    Users,
    Mail,
    Phone,
    MapPin,
    ArrowLeft,
    Pencil,
    Building2,
    Calendar,
    Plus,
    Trash2,
    Copy
} from 'lucide-vue-next';
import ClientContactModal from '@/components/clients/ClientContactModal.vue';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const client = ref(null);
const isLoading = ref(true);
const activeTab = ref('overview');
const showEditModal = ref(false);
const showContactModal = ref(false);
const contactToEdit = ref(null);

const openContactModal = (contact = null) => {
    contactToEdit.value = contact;
    showContactModal.value = true;
};

const deleteContact = async (contactId) => {
    if (!confirm('Are you sure you want to delete this contact?')) return;
    try {
        await api.delete(`/api/clients/${route.params.public_id}/contacts/${contactId}`);
        fetchClient();
    } catch (e) {
        console.error('Failed to delete contact', e);
    }
};

const breadcrumbs = computed(() => {
    return [
        { label: 'Clients', to: { name: 'admin-clients' } },
        { label: client.value?.name || 'Loading...', active: true }
    ];
});

// Permissions
const canEdit = computed(() => {
    return authStore.user?.roles?.some(r => r.name === 'administrator') || 
           authStore.user?.permissions?.some(p => p.name === 'clients.update');
});

const fetchClient = async () => {
    isLoading.value = true;
    try {
        const response = await api.get(`/api/clients/${route.params.public_id}`);
        client.value = response.data;
    } catch (e) {
        console.error('Failed to fetch client', e);
        // Handle 404 or permission error
        if (e.response?.status === 404) {
            router.push({ name: 'admin-clients' });
        }
    } finally {
        isLoading.value = false;
    }
};

const handleSaved = () => {
    fetchClient();
};

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
};

const formatCurrency = (amount, currency = 'USD') => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency || 'USD',
    }).format(amount);
};

const getStatusColor = (status) => {
    switch(status) {
        case 'active': return 'bg-green-100 text-green-700 border-green-200';
        case 'inactive': return 'bg-gray-100 text-gray-700 border-gray-200';
        default: return 'bg-gray-100 text-gray-700 border-gray-200';
    }
};

const getInvoiceStatusColor = (status) => {
    switch(String(status).toLowerCase()) {
        case 'paid': return 'bg-emerald-100 text-emerald-700 border-emerald-200';
        case 'sent': return 'bg-blue-100 text-blue-700 border-blue-200';
        case 'viewed': return 'bg-blue-50 text-blue-600 border-blue-200';
        case 'overdue': return 'bg-red-100 text-red-700 border-red-200';
        case 'draft': return 'bg-gray-100 text-gray-700 border-gray-200';
        case 'cancelled': return 'bg-gray-50 text-gray-500 border-gray-200';
        default: return 'bg-gray-100 text-gray-700 border-gray-200';
    }
};

onMounted(() => {
    fetchClient();
});

const copyToClipboard = (text) => {
    if (!text) return;
    navigator.clipboard.writeText(text);
    toast.success('Email copied to clipboard');
};

watch(() => route.params.public_id, () => {
    fetchClient();
});
</script>

<template>
    <div v-if="isLoading" class="p-6 flex items-center justify-center min-h-[400px]">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--color-primary-500)]"></div>
    </div>

    <div v-else-if="client" class="flex flex-col h-full bg-[var(--surface-subtle)]">
        <!-- Header -->
        <div class="bg-[var(--surface-primary)]  border-[var(--border-muted)] px-6 py-4">
            <div class="mx-auto w-full">
                <div class="mb-4">
                    <button @click="router.push({ name: 'admin-clients' })" class="flex items-center text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors">
                        <ArrowLeft class="w-4 h-4 mr-1" />
                        Back to Clients
                    </button>
                </div>
                
                <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <div class="h-16 w-16 rounded-xl bg-[var(--surface-brand-subtle)] flex items-center justify-center shrink-0 border border-[var(--border-subtle)]">
                            <Building2 class="h-8 w-8 text-[var(--text-brand)]" />
                        </div>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-2xl font-bold text-[var(--text-primary)]">{{ client.name }}</h1>
                                <span 
                                    class="px-2.5 py-0.5 rounded-full text-xs font-medium border"
                                    :class="getStatusColor(client.status)"
                                >
                                    {{ client.status }}
                                </span>
                            </div>
                            <div class="flex flex-wrap items-center gap-4 mt-2 text-sm text-[var(--text-secondary)]">
                                <div class="flex items-center gap-1.5">
                                    <Users class="w-4 h-4" />
                                    <span>{{ client.contact_person || 'No contact person' }}</span>
                                </div>
                                <div v-if="client.email" class="flex items-center gap-1.5">
                                    <Mail class="w-4 h-4" />
                                    <a :href="'mailto:' + client.email" class="hover:underline">{{ client.email }}</a>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <Calendar class="w-4 h-4" />
                                    <span>Joined {{ formatDate(client.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <Button v-if="canEdit" variant="outline" @click="showEditModal = true" class="gap-2">
                            <Pencil class="w-4 h-4" />
                            Edit Client
                        </Button>
                    </div>
                </div>

                <!-- Tabs Navigation -->
                <div class="flex items-center gap-6 mt-8 border-b border-[var(--border-muted)]">
                    <button 
                        @click="activeTab = 'overview'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'overview' ? 'border-[var(--color-primary-500)] text-[var(--color-primary-500)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-default)]'"
                    >
                        Overview
                    </button>
                    <button 
                        @click="activeTab = 'projects'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'projects' ? 'border-[var(--color-primary-500)] text-[var(--color-primary-500)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-default)]'"
                    >
                        Projects
                        <span class="ml-1.5 px-1.5 py-0.5 rounded-md bg-[var(--surface-tertiary)] text-xs text-[var(--text-secondary)]">
                            {{ client.projects_count || 0 }}
                        </span>
                    </button>
                    <button 
                        @click="activeTab = 'invoices'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'invoices' ? 'border-[var(--color-primary-500)] text-[var(--color-primary-500)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-default)]'"
                    >
                        Invoices
                        <span class="ml-1.5 px-1.5 py-0.5 rounded-md bg-[var(--surface-tertiary)] text-xs text-[var(--text-secondary)]">
                            {{ client.invoices_count || 0 }}
                        </span>
                    </button>
                    <button 
                        @click="activeTab = 'contacts'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'contacts' ? 'border-[var(--color-primary-500)] text-[var(--color-primary-500)]' : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-default)]'"
                    >
                        Contacts
                        <span class="ml-1.5 px-1.5 py-0.5 rounded-md bg-[var(--surface-tertiary)] text-xs text-[var(--text-secondary)]">
                            {{ client.contacts_count || 0 }}
                        </span>
                    </button>
                    <!-- Portal Users placeholder removed -->
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto bg-[var(--surface-subtle)] p-6">
            <div class="mx-auto w-full">
                
                <!-- Overview Tab -->
                <div v-show="activeTab === 'overview'" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Contact Info -->
                    <div class="md:col-span-2 space-y-6">
                        <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] p-6 shadow-sm">
                            <h3 class="text-base font-semibold text-[var(--text-primary)] mb-4">Contact Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-y-6 gap-x-8">
                                <div>
                                    <label class="text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider block mb-1">Company Name</label>
                                    <p class="text-[var(--text-primary)]">{{ client.name }}</p>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider block mb-1">Status</label>
                                    <span class="text-[var(--text-primary)] capitalize">{{ client.status }}</span>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider block mb-1">Contact Person</label>
                                    <div class="flex items-center gap-2">
                                        <Users class="w-4 h-4 text-[var(--text-muted)]" />
                                        <p class="text-[var(--text-primary)]">{{ client.contact_person || 'N/A' }}</p>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider block mb-1">Email Address</label>
                                    <div class="flex items-center gap-2">
                                        <Mail class="w-4 h-4 text-[var(--text-muted)]" />
                                        <a v-if="client.email" :href="'mailto:' + client.email" class="text-[var(--color-primary-600)] hover:underline">{{ client.email }}</a>
                                        <button v-if="client.email" @click="copyToClipboard(client.email)" class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors p-1" title="Copy email">
                                            <Copy class="w-3.5 h-3.5" />
                                        </button>
                                        <span v-else class="text-[var(--text-muted)]">N/A</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider block mb-1">Phone Number</label>
                                    <div class="flex items-center gap-2">
                                        <Phone class="w-4 h-4 text-[var(--text-muted)]" />
                                        <p class="text-[var(--text-primary)]">{{ client.phone || 'N/A' }}</p>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider block mb-1">Address</label>
                                    <div class="flex items-start gap-2">
                                        <MapPin class="w-4 h-4 text-[var(--text-muted)] mt-0.5" />
                                        <p class="text-[var(--text-primary)] whitespace-pre-line">{{ client.address || 'N/A' }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Stats / Sidebar -->
                    <div class="space-y-6">
                        <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] p-6 shadow-sm">
                            <h3 class="text-base font-semibold text-[var(--text-primary)] mb-4">Quick Stats</h3>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-3 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-subtle)]">
                                    <div class="flex items-center gap-3">
                                        <div class="p-2 rounded-md bg-blue-100 text-blue-600">
                                            <Briefcase class="w-5 h-5" />
                                        </div>
                                        <span class="text-sm font-medium text-[var(--text-secondary)]">Total Projects</span>
                                    </div>
                                    <span class="text-lg font-bold text-[var(--text-primary)]">{{ client.projects_count || 0 }}</span>
                                </div>
                                <div class="flex items-center justify-between p-3 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-subtle)]">
                                    <div class="flex items-center gap-3">
                                        <div class="p-2 rounded-md bg-emerald-100 text-emerald-600">
                                            <FileText class="w-5 h-5" />
                                        </div>
                                        <span class="text-sm font-medium text-[var(--text-secondary)]">Total Invoices</span>
                                    </div>
                                    <span class="text-lg font-bold text-[var(--text-primary)]">{{ client.invoices_count || 0 }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Projects Tab -->
                <div v-show="activeTab === 'projects'" class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden shadow-sm">
                    <div v-if="client.projects && client.projects.length > 0">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-[var(--border-muted)] bg-[var(--surface-secondary)]">
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Project Name</th>
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Last Updated</th>
                                    <th class="px-6 py-3 text-right"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--border-muted)]">
                                <tr v-for="project in client.projects" :key="project.id" class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-[var(--text-primary)]">{{ project.name }}</div>
                                        <div class="text-xs text-[var(--text-muted)]">{{ project.team?.name }}</div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-700 capitalize">
                                            {{ project.status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-[var(--text-secondary)]">
                                        {{ formatDate(project.updated_at) }}
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <Button variant="ghost" size="sm" @click="router.push({ name: 'admin-project-detail', params: { id: project.public_id } })">
                                            View
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <!-- If we have limited projects in show, maybe link to view all -->
                        <div class="p-4 border-t border-[var(--border-muted)] text-center">
                            <Button variant="ghost" @click="router.push({ name: 'admin-projects', query: { client: client.id } })">
                                View All Projects
                            </Button>
                        </div>
                    </div>
                    <div v-else class="p-12 text-center">
                        <div class="mx-auto h-12 w-12 text-[var(--text-muted)] bg-[var(--surface-secondary)] rounded-full flex items-center justify-center mb-3">
                            <Briefcase class="h-6 w-6" />
                        </div>
                        <h3 class="text-sm font-medium text-[var(--text-primary)]">No projects found</h3>
                        <p class="text-sm text-[var(--text-secondary)] mt-1">This client doesn't have any projects yet.</p>
                        <Button variant="outline" class="mt-4">
                            Create Project
                        </Button>
                    </div>
                </div>

                <!-- Invoices Tab -->
                <div v-show="activeTab === 'invoices'" class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden shadow-sm">
                     <div v-if="client.invoices && client.invoices.length > 0">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-[var(--border-muted)] bg-[var(--surface-secondary)]">
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Invoice #</th>
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-right"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--border-muted)]">
                                <tr v-for="invoice in client.invoices" :key="invoice.id" class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                                    <td class="px-6 py-4 font-medium text-[var(--text-primary)]">
                                        {{ invoice.invoice_number }}
                                    </td>
                                    <td class="px-6 py-4 text-[var(--text-primary)]">
                                        {{ formatCurrency(invoice.total, invoice.currency) }}
                                    </td>
                                    <td class="px-6 py-4">
                                        <span 
                                            class="px-2 py-1 text-xs font-medium rounded-full capitalize border"
                                            :class="getInvoiceStatusColor(invoice.status)"
                                        >
                                            {{ invoice.status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-[var(--text-secondary)]">
                                        {{ formatDate(invoice.issue_date) }}
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <Button variant="ghost" size="sm" @click="router.push({ name: 'admin-invoice-detail', params: { id: invoice.id } })">
                                            View
                                        </Button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                          <div class="p-4 border-t border-[var(--border-muted)] text-center">
                            <Button variant="ghost" @click="router.push({ name: 'admin-invoices', query: { client: client.id } })">
                                View All Invoices
                            </Button>
                        </div>
                    </div>
                     <div v-else class="p-12 text-center">
                        <div class="mx-auto h-12 w-12 text-[var(--text-muted)] bg-[var(--surface-secondary)] rounded-full flex items-center justify-center mb-3">
                            <FileText class="h-6 w-6" />
                        </div>
                        <h3 class="text-sm font-medium text-[var(--text-primary)]">No invoices found</h3>
                        <p class="text-sm text-[var(--text-secondary)] mt-1">This client doesn't have any invoices yet.</p>
                         <Button variant="outline" class="mt-4">
                            Create Invoice
                        </Button>
                    </div>
                </div>
                 
                 <!-- Members Tab (Placeholder) -->
                 <div v-show="activeTab === 'members'" class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] p-12 text-center shadow-sm">
                    <div class="mx-auto h-12 w-12 text-[var(--text-muted)] bg-[var(--surface-secondary)] rounded-full flex items-center justify-center mb-3">
                        <Users class="h-6 w-6" />
                    </div>
                    <h3 class="text-sm font-medium text-[var(--text-primary)]">Portal Users</h3>
                    <p class="text-sm text-[var(--text-secondary)] mt-1">Client portal user management coming soon.</p>
                 </div>

            </div>
        </div>

        <!-- Contacts Tab -->
        <div v-show="activeTab === 'contacts'" class="flex-1 overflow-y-auto bg-[var(--surface-subtle)] p-6">
            <div class="mx-auto w-full">
                <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden shadow-sm">
                    <div class="p-4 border-b border-[var(--border-muted)] flex justify-between items-center">
                        <h3 class="text-sm font-medium text-[var(--text-primary)]">Additional Contacts</h3>
                        <Button size="sm" @click="openContactModal()">
                            <Plus class="w-4 h-4 mr-2" />
                            Add Contact
                        </Button>
                    </div>
                    <div v-if="client.contacts && client.contacts.length > 0">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-[var(--surface-secondary)] border-b border-[var(--border-muted)]">
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Role</th>
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-xs font-medium text-[var(--text-tertiary)] uppercase tracking-wider">Phone</th>
                                    <th class="px-6 py-3 text-right"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--border-muted)]">
                                <tr v-for="contact in client.contacts" :key="contact.id">
                                    <td class="px-6 py-4 text-sm font-medium text-[var(--text-primary)]">
                                        {{ contact.name }}
                                        <span v-if="contact.is_primary" class="ml-2 px-1.5 py-0.5 text-[10px] bg-blue-100 text-blue-700 rounded-full">Primary</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-[var(--text-secondary)]">{{ contact.role || '-' }}</td>
                                    <td class="px-6 py-4 text-sm text-[var(--text-secondary)]">
                                        <div class="flex items-center gap-2">
                                            {{ contact.email || '-' }}
                                            <button v-if="contact.email" @click="copyToClipboard(contact.email)" class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors p-1 opacity-0 group-hover:opacity-100" title="Copy email">
                                                <Copy class="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-[var(--text-secondary)]">{{ contact.phone || '-' }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <button @click="openContactModal(contact)" class="p-1 text-[var(--text-muted)] hover:text-[var(--text-primary)]">
                                                <Pencil class="w-4 h-4" />
                                            </button>
                                            <button @click="deleteContact(contact.id)" class="p-1 text-[var(--text-muted)] hover:text-[var(--color-error)]">
                                                <Trash2 class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="p-12 text-center text-[var(--text-secondary)]">
                        <Users class="w-8 h-8 mx-auto mb-2 opacity-50" />
                        <p>No additional contacts added.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <ClientFormModal
            v-if="client"
            :open="showEditModal"
            :client="client"
            @close="showEditModal = false"
            @saved="handleSaved"
        />

        <!-- Contact Modal -->
        <ClientContactModal
            :open="showContactModal"
            :contact="contactToEdit"
            :client-public-id="client?.public_id"
            @close="showContactModal = false"
            @saved="handleSaved"
        />
    </div>
</template>
