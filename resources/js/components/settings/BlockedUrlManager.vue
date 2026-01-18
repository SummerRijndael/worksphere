<script setup lang="ts">
import { ref, onMounted, reactive } from "vue";
import { Button, Input, Modal } from "@/components/ui"; // Using existing UI components
import { Plus, Trash2, Search, AlertOctagon } from "lucide-vue-next";
import { toast } from "vue-sonner";
import BlockedUrlService, { type BlockedUrl } from "@/services/BlockedUrlService";

const isLoading = ref(false);
const blockedUrls = ref<BlockedUrl[]>([]);
const searchQuery = ref("");
const pagination = ref({
    currentPage: 1,
    totalPages: 1
});

const showAddModal = ref(false);
const isSubmitting = ref(false);
const form = reactive({
    pattern: "",
    reason: ""
});
const errors = ref<Record<string, string>>({});

const fetchBlockedUrls = async (page = 1) => {
    isLoading.value = true;
    try {
        const response = await BlockedUrlService.getBlockedUrls(page, searchQuery.value);
        // Controller returns { data: Paginator }, Paginator is { data: [], meta... }
        // So structure is response.data (Paginator) .data (Records)
        const paginator = response.data;
        blockedUrls.value = paginator.data;
        
        if (paginator) {
            pagination.value = {
                currentPage: paginator.current_page,
                totalPages: paginator.last_page
            };
        }
    } catch (error) {
        toast.error("Failed to load blocked URLs");
    } finally {
        isLoading.value = false;
    }
};

const handleSearch = () => {
    fetchBlockedUrls(1);
};

const openAddModal = () => {
    form.pattern = "";
    form.reason = "";
    errors.value = {};
    showAddModal.value = true;
};

const saveBlockedUrl = async () => {
    if (!form.pattern) {
        errors.value = { pattern: "URL Pattern is required" };
        return;
    }

    isSubmitting.value = true;
    try {
        await BlockedUrlService.addBlockedUrl(form.pattern, form.reason);
        toast.success("Blocked URL added successfully");
        showAddModal.value = false;
        fetchBlockedUrls(1);
    } catch (error: any) {
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors;
        } else {
            toast.error("Failed to add blocked URL");
        }
    } finally {
        isSubmitting.value = false;
    }
};

const deleteBlockedUrl = async (id: number) => {
    if (!confirm("Are you sure you want to remove this URL from the blocklist?")) return;
    
    try {
        await BlockedUrlService.deleteBlockedUrl(id);
        toast.success("Blocked URL removed");
        fetchBlockedUrls(pagination.value.currentPage);
    } catch (error) {
        toast.error("Failed to remove blocked URL");
    }
};

onMounted(() => {
    fetchBlockedUrls();
});
</script>

<template>
    <div class="bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)] overflow-hidden">
        <div class="p-4 border-b border-[var(--border-default)] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-red-500/10 flex items-center justify-center">
                    <AlertOctagon class="w-4 h-4 text-red-600" />
                </div>
                <div>
                    <h3 class="font-medium text-[var(--text-primary)]">Blocked URLs</h3>
                    <p class="text-xs text-[var(--text-muted)]">Manage unsafe URLs and domains. We use the Google Safe Browsing API for automated checks.</p>
                </div>
            </div>
            <div class="flex gap-2 w-full sm:w-auto">
                <div class="relative flex-1 sm:flex-initial">
                    <Search class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text-muted)]" />
                    <input 
                        v-model="searchQuery" 
                        @input="handleSearch"
                        type="text" 
                        placeholder="Search..." 
                        class="w-full sm:w-64 pl-9 pr-3 py-1.5 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)]"
                    />
                </div>
                <Button size="sm" @click="openAddModal">
                    <Plus class="w-4 h-4 mr-1" />
                    Add
                </Button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-[var(--text-muted)] uppercase bg-[var(--surface-secondary)] border-b border-[var(--border-default)]">
                    <tr>
                        <th class="px-6 py-3 font-medium">Pattern</th>
                        <th class="px-6 py-3 font-medium">Reason</th>
                        <th class="px-6 py-3 font-medium">Added</th>
                        <th class="px-6 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border-default)]">
                    <tr v-if="isLoading">
                        <td colspan="4" class="px-6 py-8 text-center text-[var(--text-muted)]">Loading...</td>
                    </tr>
                    <tr v-else-if="blockedUrls.length === 0">
                        <td colspan="4" class="px-6 py-8 text-center text-[var(--text-muted)]">No blocked URLs found</td>
                    </tr>
                    <tr v-for="item in blockedUrls" :key="item.id" class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                        <td class="px-6 py-3 font-medium text-[var(--text-primary)] font-mono">{{ item.pattern }}</td>
                        <td class="px-6 py-3 text-[var(--text-secondary)]">{{ item.reason || '-' }}</td>
                        <td class="px-6 py-3 text-[var(--text-secondary)] whitespace-nowrap">
                            {{ new Date(item.created_at).toLocaleDateString() }}
                        </td>
                        <td class="px-6 py-3 text-right">
                            <button 
                                @click="deleteBlockedUrl(item.id)"
                                class="text-red-500 hover:text-red-700 p-1 rounded-md hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                            >
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination (Simple) -->
        <div v-if="pagination.totalPages > 1" class="p-4 border-t border-[var(--border-default)] flex justify-end gap-2">
            <Button 
                variant="outline" 
                size="sm" 
                :disabled="pagination.currentPage === 1"
                @click="fetchBlockedUrls(pagination.currentPage - 1)"
            >
                Previous
            </Button>
            <Button 
                variant="outline" 
                size="sm" 
                :disabled="pagination.currentPage === pagination.totalPages"
                @click="fetchBlockedUrls(pagination.currentPage + 1)"
            >
                Next
            </Button>
        </div>

        <!-- Add Modal -->
        <Modal 
            v-model:open="showAddModal" 
            title="Block New URL"
            description="Add a URL pattern to the blocklist to prevent previews."
        >
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1">URL Pattern</label>
                    <Input 
                        v-model="form.pattern" 
                        placeholder="e.g. malicious.com or *.bad-site.net" 
                        :class="{ 'border-red-500': errors.pattern }"
                    />
                    <p v-if="errors.pattern" class="text-xs text-red-500 mt-1">{{ errors.pattern }}</p>
                    <p class="text-xs text-[var(--text-muted)] mt-1">
                        Supports wildcards (*) for domain matching.
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1">Reason (Optional)</label>
                    <Input v-model="form.reason" placeholder="e.g. Phishing site" />
                </div>
            </div>
            
            <template #footer>
                <Button variant="outline" @click="showAddModal = false">Cancel</Button>
                <Button variant="danger" :loading="isSubmitting" @click="saveBlockedUrl">Block URL</Button>
            </template>
        </Modal>
    </div>
</template>
