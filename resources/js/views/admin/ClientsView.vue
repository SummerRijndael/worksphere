<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import debounce from 'lodash/debounce';
import api from '@/lib/api';
import { Button, Input, Checkbox } from '@/components/ui';
import { 
    Search, 
    Filter, 
    Plus, 
    LayoutGrid, 
    List, 
    MoreVertical, 
    Briefcase,
    User,
    Mail,
    Phone,
    Calendar,
    CheckCircle2,
    XCircle,
    Building2,
    Trash2,
    Edit2,
    ChevronLeft,
    ChevronRight
} from 'lucide-vue-next';

const router = useRouter();
const route = useRoute();

// State
const clients = ref([]);
const isLoading = ref(true);
const viewMode = ref(localStorage.getItem('clients_view_mode') || 'grid');
const searchQuery = ref('');
const statusFilter = ref('');
const dateFilter = ref(null);
const selectedClients = ref(new Set());
const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const clientToDelete = ref(null);
const clientToEdit = ref(null);
const pagination = ref({
    currentPage: 1,
    lastPage: 1,
    total: 0,
    perPage: 15
});

// Form Data
const formData = ref({
    name: '',
    email: '',
    contact_person: '',
    phone: '',
    address: '',
    status: 'active',
});

const errors = ref({});
const isSubmitting = ref(false);

// Methods
const fetchClients = async (page = 1) => {
    isLoading.value = true;
    try {
        const params = {
            page,
            search: searchQuery.value,
            status: statusFilter.value,
            per_page: pagination.value.perPage
        };

        const response = await api.get('/api/clients', { params });
        clients.value = response.data.data;
        pagination.value = {
            currentPage: response.data.current_page,
            lastPage: response.data.last_page,
            total: response.data.total,
            perPage: response.data.per_page
        };
    } catch (error) {
        console.error('Failed to fetch clients:', error);
    } finally {
        isLoading.value = false;
    }
};

const debouncedSearch = debounce(() => {
    fetchClients(1);
}, 300);

const toggleViewMode = (mode) => {
    viewMode.value = mode;
    localStorage.setItem('clients_view_mode', mode);
};

const openCreateModal = () => {
    formData.value = {
        name: '',
        email: '',
        contact_person: '',
        phone: '',
        address: '',
        status: 'active',
    };
    errors.value = {};
    showCreateModal.value = true;
};

const openEditModal = (client) => {
    clientToEdit.value = client;
    formData.value = {
        name: client.name,
        email: client.email || '',
        contact_person: client.contact_person || '',
        phone: client.phone || '',
        address: client.address || '',
        status: client.status,
    };
    errors.value = {};
    showEditModal.value = true;
};

const confirmDelete = (client) => {
    clientToDelete.value = client;
    showDeleteModal.value = true;
};

const createClient = async () => {
    isSubmitting.value = true;
    errors.value = {};
    try {
        await api.post('/api/clients', formData.value);
        showCreateModal.value = false;
        fetchClients(1);
    } catch (error) {
        if (error.response?.data?.errors) {
            errors.value = error.response.data.errors;
        }
    } finally {
        isSubmitting.value = false;
    }
};

const updateClient = async () => {
    isSubmitting.value = true;
    errors.value = {};
    try {
        await api.put(`/api/clients/${clientToEdit.value.public_id}`, formData.value);
        showEditModal.value = false;
        fetchClients(pagination.value.currentPage);
    } catch (error) {
        if (error.response?.data?.errors) {
            errors.value = error.response.data.errors;
        }
    } finally {
        isSubmitting.value = false;
    }
};

const deleteClient = async () => {
    isSubmitting.value = true;
    try {
        await api.delete(`/api/clients/${clientToDelete.value.public_id}`);
        showDeleteModal.value = false;
        fetchClients(pagination.value.currentPage);
        selectedClients.value.delete(clientToDelete.value.public_id);
    } catch (error) {
        console.error('Failed to delete client:', error);
    } finally {
        isSubmitting.value = false;
    }
};

const toggleSelection = (clientId) => {
    if (selectedClients.value.has(clientId)) {
        selectedClients.value.delete(clientId);
    } else {
        selectedClients.value.add(clientId);
    }
};

const toggleAll = (event) => {
    if (event.target.checked) {
        clients.value.forEach(client => selectedClients.value.add(client.public_id));
    } else {
        selectedClients.value.clear();
    }
};

// Watchers
watch(searchQuery, debouncedSearch);
watch(statusFilter, () => fetchClients(1));

// Lifecycle
onMounted(() => {
    fetchClients();
});
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">Clients</h1>
                <p class="text-[var(--text-secondary)] mt-1">Manage your client organizations</p>
            </div>
            <Button @click="openCreateModal" class="gap-2">
                <Plus class="w-4 h-4" />
                Add Client
            </Button>
        </div>

        <!-- Filters -->
        <div class="flex flex-col sm:flex-row gap-4 bg-[var(--surface-primary)] p-4 rounded-xl border border-[var(--border-muted)] shadow-sm">
            <div class="flex-1 flex flex-col sm:flex-row gap-3">
                <div class="relative flex-1">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text-muted)]" />
                    <input v-model="searchQuery" type="text" placeholder="Search clients..." class="input pl-14 h-10 text-sm">
                </div>
                <div class="flex gap-3">
                    <div class="relative min-w-[140px]">
                        <select v-model="statusFilter" class="input h-10 text-sm">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 border-l border-[var(--border-muted)] pl-4 ml-2">
                <div class="flex bg-[var(--surface-secondary)] rounded-lg p-1">
                    <button 
                        @click="toggleViewMode('grid')"
                        class="p-1.5 rounded-md transition-all"
                        :class="viewMode === 'grid' ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                    >
                        <LayoutGrid class="w-4 h-4" />
                    </button>
                    <button 
                        @click="toggleViewMode('list')"
                        class="p-1.5 rounded-md transition-all"
                        :class="viewMode === 'list' ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                    >
                        <List class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="relative min-h-[400px]">
            <Transition name="fade" mode="out-in">
                <!-- Loading State -->
                <div v-if="isLoading" key="loading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div v-for="i in 6" :key="i" class="animate-pulse bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] p-6 h-[200px]"></div>
                </div>

                <!-- Empty State -->
                <div v-else-if="clients.length === 0" key="empty" class="text-center py-20">
                    <div class="bg-[var(--surface-secondary)] w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <Building2 class="w-8 h-8 text-[var(--text-muted)]" />
                    </div>
                    <h3 class="text-lg font-medium text-[var(--text-primary)]">No clients found</h3>
                    <p class="text-[var(--text-secondary)] mt-1">Try adjusting your filters or create a new client</p>
                    <Button variant="outline" class="mt-4" @click="openCreateModal">
                        Add Client
                    </Button>
                </div>

                <!-- Data State -->
                <div v-else key="data">
                    <Transition name="fade" mode="out-in">
                        <!-- Grid View -->
                        <div v-if="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <TransitionGroup name="list" appear>
                                <div v-for="client in clients" :key="client.public_id" 
                                    class="group bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] hover:border-[var(--border-default)] transition-all hover:shadow-md p-6 relative"
                                >
                                    <div class="absolute top-4 right-4 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <div class="flex items-center gap-1 bg-[var(--surface-elevated)] rounded-lg shadow-sm border border-[var(--border-muted)] p-1">
                                            <button @click="openEditModal(client)" class="p-1.5 text-[var(--text-muted)] hover:text-[var(--interactive-primary)] rounded-md transition-colors">
                                                <Edit2 class="w-4 h-4" />
                                            </button>
                                            <button @click="confirmDelete(client)" class="p-1.5 text-[var(--text-muted)] hover:text-[var(--color-error)] rounded-md transition-colors">
                                                <Trash2 class="w-4 h-4" />
                                            </button>
                                        </div>
                                    </div>

                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex items-center gap-3">
                                            <div class="h-12 w-12 rounded-lg bg-[var(--surface-secondary)] flex items-center justify-center text-lg font-bold text-[var(--text-secondary)] uppercase">
                                                {{ client.initials }}
                                            </div>
                                            <div>
                                                <h3 class="font-medium text-[var(--text-primary)] line-clamp-1">{{ client.name }}</h3>
                                                <span 
                                                    class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium mt-1"
                                                    :class="client.status === 'active' ? 'bg-green-100 text-green-900 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400'"
                                                >
                                                    {{ client.status }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-3 text-sm text-[var(--text-secondary)]">
                                        <div class="flex items-center gap-2" v-if="client.contact_person">
                                            <User class="w-4 h-4 text-[var(--text-muted)]" />
                                            <span class="truncate">{{ client.contact_person }}</span>
                                        </div>
                                        <div class="flex items-center gap-2" v-if="client.email">
                                            <Mail class="w-4 h-4 text-[var(--text-muted)]" />
                                            <span class="truncate">{{ client.email }}</span>
                                        </div>
                                        <div class="flex items-center gap-2" v-if="client.phone">
                                            <Phone class="w-4 h-4 text-[var(--text-muted)]" />
                                            <span class="truncate">{{ client.phone }}</span>
                                        </div>
                                    </div>
                                </div>
                            </TransitionGroup>
                        </div>

                        <!-- List View -->
                        <div v-else class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-[var(--surface-secondary)] border-b border-[var(--border-default)]">
                                        <tr>
                                            <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] w-[50px] border border-[var(--border-default)]">
                                                <Checkbox :checked="selectedClients.size === clients.length" @change="toggleAll" />
                                            </th>
                                            <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Company</th>
                                            <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Contact</th>
                                            <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Status</th>
                                            <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Details</th>
                                            <th class="px-6 py-3 text-left font-medium text-[var(--text-secondary)] border border-[var(--border-default)]">Created</th>
                                            <th class="px-6 py-3 text-right font-medium text-[var(--text-secondary)] w-[100px] border border-[var(--border-default)]">Actions</th>
                                        </tr>
                                    </thead>
                                    <TransitionGroup tag="tbody" name="list" appear>
                                        <tr v-for="client in clients" :key="client.public_id" class="group border-b border-[var(--border-default)] last:border-0 hover:bg-[var(--surface-secondary)]/50 transition-colors">
                                            <td class="px-6 py-3 border border-[var(--border-default)]">
                                                <Checkbox :checked="selectedClients.has(client.public_id)" @change="toggleSelection(client.public_id)" />
                                            </td>
                                            <td class="px-6 py-3 border border-[var(--border-default)]">
                                                <div class="flex items-center gap-3">
                                                    <div class="h-8 w-8 rounded bg-[var(--surface-secondary)] flex items-center justify-center text-xs font-bold text-[var(--text-secondary)] uppercase">
                                                        {{ client.initials }}
                                                    </div>
                                                    <div>
                                                        <div class="font-medium text-[var(--text-primary)]">{{ client.name }}</div>
                                                        <div class="text-xs text-[var(--text-muted)]">{{ client.slug }}</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-3 border border-[var(--border-default)]">
                                                <div v-if="client.contact_person" class="font-medium text-[var(--text-primary)]">{{ client.contact_person }}</div>
                                                <div v-else class="text-[var(--text-muted)]">-</div>
                                            </td>
                                            <td class="px-6 py-3 border border-[var(--border-default)]">
                                                <span 
                                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                                                    :class="client.status === 'active' ? 'bg-green-100 text-green-900 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400'"
                                                >
                                                    {{ client.status }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-3 border border-[var(--border-default)]">
                                                <div class="space-y-1 text-xs">
                                                     <div v-if="client.email" class="flex items-center gap-1.5 text-[var(--text-secondary)]">
                                                        <Mail class="w-3.5 h-3.5" />
                                                        {{ client.email }}
                                                    </div>
                                                     <div v-if="client.phone" class="flex items-center gap-1.5 text-[var(--text-secondary)]">
                                                        <Phone class="w-3.5 h-3.5" />
                                                        {{ client.phone }}
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-3 text-[var(--text-secondary)] border border-[var(--border-default)]">
                                                {{ new Date(client.created_at).toLocaleDateString() }}
                                            </td>
                                            <td class="px-6 py-3 text-right border border-[var(--border-default)]">
                                                <div class="flex items-center justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                                    <button @click="openEditModal(client)" class="p-1.5 text-[var(--text-muted)] hover:text-[var(--interactive-primary)] rounded transition-colors">
                                                        <Edit2 class="w-4 h-4" />
                                                    </button>
                                                    <button @click="confirmDelete(client)" class="p-1.5 text-[var(--text-muted)] hover:text-[var(--color-error)] rounded transition-colors">
                                                        <Trash2 class="w-4 h-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </TransitionGroup>
                                </table>
                            </div>
                        </div>
                    </Transition>
                     <!-- Pagination -->
                    <div class="flex items-center justify-between border-t border-[var(--border-muted)] pt-4 mt-6">
                        <p class="text-sm text-[var(--text-secondary)]">
                            Showing <span class="font-medium text-[var(--text-primary)]">{{ (pagination.currentPage - 1) * pagination.perPage + 1 }}</span> to <span class="font-medium text-[var(--text-primary)]">{{ Math.min(pagination.currentPage * pagination.perPage, pagination.total) }}</span> of <span class="font-medium text-[var(--text-primary)]">{{ pagination.total }}</span> results
                        </p>
                        <div class="flex gap-2">
                            <Button 
                                variant="outline" 
                                size="sm" 
                                :disabled="pagination.currentPage === 1"
                                @click="fetchClients(pagination.currentPage - 1)"
                            >
                                <ChevronLeft class="w-4 h-4" />
                            </Button>
                            <Button 
                                variant="outline" 
                                size="sm" 
                                :disabled="pagination.currentPage === pagination.lastPage"
                                @click="fetchClients(pagination.currentPage + 1)"
                            >
                                <ChevronRight class="w-4 h-4" />
                            </Button>
                        </div>
                    </div>
                </div>
            </Transition>
        </div>

        <!-- Create Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="showCreateModal = false">
            <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] shadow-xl w-full max-w-lg overflow-hidden">
                <div class="p-6 border-b border-[var(--border-muted)]">
                    <h3 class="text-lg font-semibold text-[var(--text-primary)]">Add New Client</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1 md:col-span-2">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Company Name</label>
                            <input v-model="formData.name" type="text" class="input">
                            <p v-if="errors.name" class="text-xs text-[var(--color-error)]">{{ errors.name[0] }}</p>
                        </div>
                        
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Contact Person</label>
                            <input v-model="formData.contact_person" type="text" class="input">
                            <p v-if="errors.contact_person" class="text-xs text-[var(--color-error)]">{{ errors.contact_person[0] }}</p>
                        </div>

                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Email</label>
                            <input v-model="formData.email" type="email" class="input">
                            <p v-if="errors.email" class="text-xs text-[var(--color-error)]">{{ errors.email[0] }}</p>
                        </div>

                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Phone</label>
                            <input v-model="formData.phone" type="text" class="input">
                            <p v-if="errors.phone" class="text-xs text-[var(--color-error)]">{{ errors.phone[0] }}</p>
                        </div>

                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Status</label>
                            <select v-model="formData.status" class="input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <p v-if="errors.status" class="text-xs text-[var(--color-error)]">{{ errors.status[0] }}</p>
                        </div>

                         <div class="space-y-1 md:col-span-2">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Address</label>
                            <textarea v-model="formData.address" rows="2" class="input"></textarea>
                            <p v-if="errors.address" class="text-xs text-[var(--color-error)]">{{ errors.address[0] }}</p>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3">
                    <button @click="showCreateModal = false" class="btn btn-ghost">Cancel</button>
                    <Button :loading="isSubmitting" @click="createClient">Create Client</Button>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="showEditModal = false">
             <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] shadow-xl w-full max-w-lg overflow-hidden">
                <div class="p-6 border-b border-[var(--border-muted)]">
                    <h3 class="text-lg font-semibold text-[var(--text-primary)]">Edit Client</h3>
                </div>
                <div class="p-6 space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1 md:col-span-2">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Company Name</label>
                            <input v-model="formData.name" type="text" class="input">
                            <p v-if="errors.name" class="text-xs text-[var(--color-error)]">{{ errors.name[0] }}</p>
                        </div>
                        
                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Contact Person</label>
                            <input v-model="formData.contact_person" type="text" class="input">
                            <p v-if="errors.contact_person" class="text-xs text-[var(--color-error)]">{{ errors.contact_person[0] }}</p>
                        </div>

                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Email</label>
                            <input v-model="formData.email" type="email" class="input">
                            <p v-if="errors.email" class="text-xs text-[var(--color-error)]">{{ errors.email[0] }}</p>
                        </div>

                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Phone</label>
                            <input v-model="formData.phone" type="text" class="input">
                            <p v-if="errors.phone" class="text-xs text-[var(--color-error)]">{{ errors.phone[0] }}</p>
                        </div>

                        <div class="space-y-1">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Status</label>
                            <select v-model="formData.status" class="input">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                             <p v-if="errors.status" class="text-xs text-[var(--color-error)]">{{ errors.status[0] }}</p>
                        </div>

                        <div class="space-y-1 md:col-span-2">
                            <label class="text-sm font-medium text-[var(--text-secondary)]">Address</label>
                            <textarea v-model="formData.address" rows="2" class="input"></textarea>
                            <p v-if="errors.address" class="text-xs text-[var(--color-error)]">{{ errors.address[0] }}</p>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3">
                    <button @click="showEditModal = false" class="btn btn-ghost">Cancel</button>
                    <Button :loading="isSubmitting" @click="updateClient">Save Changes</Button>
                </div>
            </div>
        </div>
        
        <!-- Delete Modal -->
         <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @click.self="showDeleteModal = false">
            <div class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] shadow-xl w-full max-w-md overflow-hidden">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-[var(--text-primary)] mb-2">Delete Client</h3>
                    <p class="text-[var(--text-secondary)]">
                        Are you sure you want to delete <strong>{{ clientToDelete?.name }}</strong>? This action cannot be undone.
                    </p>
                </div>
                <div class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3">
                    <button @click="showDeleteModal = false" class="btn btn-ghost">Cancel</button>
                    <Button variant="destructive" :loading="isSubmitting" @click="deleteClient">Delete Client</Button>
                </div>
            </div>
        </div>

    </div>
</template>
