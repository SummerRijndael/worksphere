<script setup>
import { ref, onMounted } from "vue";
import api from "@/lib/api";
import { useDate } from "@/composables/useDate";
const { formatDateTime } = useDate();

// Alias api to axios
const axios = api;
import { toast } from "vue-sonner";
import {
    PlusIcon,
    TrashIcon,
    NoSymbolIcon,
    MagnifyingGlassIcon,
} from "@heroicons/vue/24/outline";
import Modal from "@/components/ui/Modal.vue";

const emit = defineEmits(["updated"]);

const ips = ref([]);
const loading = ref(true);
const showAddModal = ref(false);
const newBlock = ref({
    ip_address: "",
    reason: "",
    expires_at: "",
});
const subtmitting = ref(false);

const fetchBlockedIps = async () => {
    loading.value = true;
    try {
        const response = await axios.get("/api/admin/security/blocked-ips");
        ips.value = response.data.data || [];
    } catch (error) {
        toast.error("Failed to fetch blocked IPs");
    } finally {
        loading.value = false;
    }
};

const blockIp = async () => {
    subtmitting.value = true;
    try {
        await axios.post("/api/admin/security/blocked-ips", newBlock.value);
        toast.success("IP Address blocked successfully");
        showAddModal.value = false;
        newBlock.value = { ip_address: "", reason: "", expires_at: "" };
        fetchBlockedIps();
        emit("updated");
    } catch (error) {
         if (error.response?.status === 422) {
            toast.error(error.response.data.message || "Validation failed");
        } else {
            toast.error("Failed to block IP");
        }
    } finally {
        subtmitting.value = false;
    }
};

const unblockIp = async (id) => {
    if (!confirm("Are you sure you want to unblock this IP?")) return;

    try {
        await axios.delete(`/api/admin/security/blocked-ips/${id}`);
        toast.success("IP Address unblocked successfully");
        fetchBlockedIps();
        emit("updated");
    } catch (error) {
        toast.error("Failed to unblock IP");
    }
};

onMounted(() => {
    fetchBlockedIps();
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-between items-center">
            <h3 class="text-lg font-medium text-[var(--text-primary)]">Blocked IP Addresses</h3>
            <button class="btn btn-primary flex items-center gap-2" @click="showAddModal = true">
                <PlusIcon class="w-4 h-4" />
                Block IP
            </button>
        </div>

        <div class="card overflow-hidden">
             <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[var(--surface-subtle)] border-b border-[var(--border-default)]">
                        <tr>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">IP Address</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">Reason</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">Blocked By</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">Expires</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)] text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-default)]">
                        <tr v-if="loading">
                             <td colspan="5" class="p-4 text-center text-[var(--text-tertiary)]">Loading...</td>
                        </tr>
                        <tr v-else-if="ips.length === 0">
                             <td colspan="5" class="p-12 text-center text-[var(--text-tertiary)]">
                                <NoSymbolIcon class="w-16 h-16 mx-auto mb-4 opacity-50" />
                                <p class="text-lg">No blocked IPs found</p>
                             </td>
                        </tr>
                        <tr v-for="ip in ips" :key="ip.id" class="hover:bg-[var(--surface-hover)] transition-colors duration-150">
                            <td class="px-6 py-4 font-medium font-mono text-[var(--text-primary)] text-base">{{ ip.ip }}</td>
                            <td class="px-6 py-4 text-[var(--text-secondary)]">{{ ip.reason || '-' }}</td>
                            <td class="px-6 py-4 text-[var(--text-secondary)]">
                                {{ ip.user?.name || 'System' }}
                            </td>
                            <td class="px-6 py-4 text-[var(--text-secondary)]">
                                {{ ip.expires_at ? formatDateTime(ip.expires_at, 'MMM d, yyyy HH:mm') : 'Permanent' }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button
                                    @click="unblockIp(ip.id)"
                                    class="text-red-500 hover:text-red-600 transition-colors p-1"
                                    title="Unblock IP"
                                >
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
             </div>
        </div>

        <!-- Block IP Modal -->
        <Modal
            :open="showAddModal"
            @update:open="showAddModal = $event"
            title="Block IP Address"
            description="Manually prevent an IP address from accessing the system."
        >
            <form @submit.prevent="blockIp" class="space-y-4">
                <div class="form-group">
                    <label class="block text-sm font-medium mb-1 text-[var(--text-secondary)]">IP Address</label>
                    <input
                        v-model="newBlock.ip_address"
                        type="text"
                        class="input w-full"
                        placeholder="e.g. 192.168.1.1"
                        required
                    />
                </div>
                <div class="form-group">
                    <label class="block text-sm font-medium mb-1 text-[var(--text-secondary)]">Reason</label>
                    <input
                        v-model="newBlock.reason"
                        type="text"
                        class="input w-full"
                        placeholder="e.g. Malicious activity"
                    />
                </div>
                <div class="form-group">
                     <label class="block text-sm font-medium mb-1 text-[var(--text-secondary)]">Expires At (Optional)</label>
                     <input
                        v-model="newBlock.expires_at"
                        type="datetime-local"
                        class="input w-full"
                     />
                     <p class="text-xs text-[var(--text-tertiary)] mt-1">Leave empty for permanent block</p>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    @click="showAddModal = false"
                    class="btn btn-secondary"
                >
                    Cancel
                </button>
                <button
                    @click="blockIp"
                    class="btn btn-primary px-6"
                    :disabled="subtmitting"
                >
                    {{ subtmitting ? 'Blocking...' : 'Block IP' }}
                </button>
            </template>
        </Modal>
    </div>
</template>
