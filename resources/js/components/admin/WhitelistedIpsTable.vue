<script setup>
import { ref, onMounted } from "vue";
import api from "@/lib/api";
import { useDate } from "@/composables/useDate";
const { formatDate } = useDate();
import { toast } from "vue-sonner";
import {
    PlusIcon,
    TrashIcon,
    ShieldCheckIcon,
    MagnifyingGlassIcon,
} from "@heroicons/vue/24/outline";
import Modal from "@/components/ui/Modal.vue";

// Alias api to axios
const axios = api;

const emit = defineEmits(["updated"]);

const ips = ref([]);
const loading = ref(true);
const showAddModal = ref(false);
const newWhitelist = ref({
    ip_address: "",
    label: "",
});
const submitting = ref(false);

const fetchWhitelistedIps = async () => {
    loading.value = true;
    try {
        const response = await axios.get("/api/admin/security/whitelisted-ips");
        ips.value = response.data.data || [];
    } catch (error) {
        toast.error("Failed to fetch whitelisted IPs");
    } finally {
        loading.value = false;
    }
};

const whitelistIp = async () => {
    submitting.value = true;
    try {
        await axios.post("/api/admin/security/whitelisted-ips", newWhitelist.value);
        toast.success("IP Address whitelisted successfully");
        showAddModal.value = false;
        newWhitelist.value = { ip_address: "", label: "" };
        fetchWhitelistedIps();
        emit("updated");
    } catch (error) {
         if (error.response?.status === 422) {
            toast.error(error.response.data.message || "Validation failed");
        } else {
            toast.error("Failed to whitelist IP");
        }
    } finally {
        submitting.value = false;
    }
};

const unwhitelistIp = async (id) => {
    if (!confirm("Are you sure you want to remove this IP from the whitelist?")) return;

    try {
        await axios.delete(`/api/admin/security/whitelisted-ips/${id}`);
        toast.success("IP Address removed from whitelist");
        fetchWhitelistedIps();
        emit("updated");
    } catch (error) {
        toast.error("Failed to remove IP from whitelist");
    }
};

onMounted(() => {
    fetchWhitelistedIps();
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-between items-center">
            <h3 class="text-lg font-medium text-[var(--text-primary)]">Whitelisted IP Addresses</h3>
            <button class="btn btn-primary flex items-center gap-2" @click="showAddModal = true">
                <PlusIcon class="w-4 h-4" />
                Whitelist IP
            </button>
        </div>

        <div class="card overflow-hidden">
             <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[var(--surface-subtle)] border-b border-[var(--border-default)]">
                        <tr>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">IP Address</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">Label / Location</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">Added By</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)]">Added On</th>
                            <th class="px-6 py-4 font-semibold text-[var(--text-secondary)] text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-default)]">
                        <tr v-if="loading">
                             <td colspan="5" class="p-4 text-center text-[var(--text-tertiary)]">Loading...</td>
                        </tr>
                        <tr v-else-if="ips.length === 0">
                             <td colspan="5" class="p-12 text-center text-[var(--text-tertiary)]">
                                <ShieldCheckIcon class="w-16 h-16 mx-auto mb-4 opacity-50" />
                                <p class="text-lg">No whitelisted IPs found</p>
                                <p class="text-sm">Whitelisted IPs bypass security blocks.</p>
                             </td>
                        </tr>
                        <tr v-for="ip in ips" :key="ip.id" class="hover:bg-[var(--surface-hover)] transition-colors duration-150">
                            <td class="px-6 py-4 font-medium font-mono text-[var(--text-primary)] text-base">{{ ip.ip }}</td>
                            <td class="px-6 py-4 text-[var(--text-secondary)]">{{ ip.label || '-' }}</td>
                            <td class="px-6 py-4 text-[var(--text-secondary)]">
                                {{ ip.user?.name || 'System' }}
                            </td>
                            <td class="px-6 py-4 text-[var(--text-secondary)]">
                                {{ formatDate(ip.created_at) }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button
                                    @click="unwhitelistIp(ip.id)"
                                    class="text-red-500 hover:text-red-600 transition-colors p-1"
                                    title="Remove from Whitelist"
                                >
                                    <TrashIcon class="w-4 h-4" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
             </div>
        </div>

        <!-- Whitelist IP Modal -->
        <Modal
            :open="showAddModal"
            @update:open="showAddModal = $event"
            title="Whitelist IP Address"
            description="Specific IP addresses that should bypass security blocks."
        >
            <form @submit.prevent="whitelistIp" class="space-y-4">
                <div class="form-group">
                    <label class="block text-sm font-medium mb-1 text-[var(--text-secondary)]">IP Address</label>
                    <input
                        v-model="newWhitelist.ip_address"
                        type="text"
                        class="input w-full"
                        placeholder="e.g. 192.168.1.1"
                        required
                    />
                </div>
                <div class="form-group">
                    <label class="block text-sm font-medium mb-1 text-[var(--text-secondary)]">Label / Note</label>
                    <input
                        v-model="newWhitelist.label"
                        type="text"
                        class="input w-full"
                        placeholder="e.g. Head Office"
                    />
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
                    @click="whitelistIp"
                    class="btn btn-primary px-6"
                    :disabled="submitting"
                >
                    {{ submitting ? 'Processing...' : 'Whitelist IP' }}
                </button>
            </template>
        </Modal>
    </div>
</template>
