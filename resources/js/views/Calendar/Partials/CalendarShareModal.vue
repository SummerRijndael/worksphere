<script setup lang="ts">
import { ref, watch } from "vue";
import axios from "axios";
import { Trash } from "lucide-vue-next";
import Modal from "@/components/ui/Modal.vue";
import Button from "@/components/ui/Button.vue";
import ParticipantSelector from "@/components/ui/ParticipantSelector.vue";

interface Share {
    share_id: number;
    name: string;
    email: string;
    permission_level: "view" | "edit";
}

interface Participant {
    type: "user" | "email";
    id?: number | string;
    email?: string;
    name?: string;
    avatar?: string;
}

interface CalendarSharesResponse {
    my_shares: Share[];
    shared_with_me: any[];
}

const props = defineProps<{
    isOpen: boolean;
    currentUserId?: number | string;
}>();

const emit = defineEmits(["close", "update:isOpen"]);

const shares = ref<Share[]>([]);
const hasSharedWithMe = ref<any[]>([]);
const isLoading = ref(false);
const isAdding = ref(false);

// New state for multi-select
const selectedParticipants = ref<Participant[]>([]);
const newSharePermission = ref("view");
const errorMsg = ref("");

// Proxies for Modal
const closeModal = () => {
    emit("close");
    emit("update:isOpen", false);
    errorMsg.value = "";
    selectedParticipants.value = [];
};

const handleOpenUpdate = (val: boolean) => {
    emit("update:isOpen", val);
    if (!val) {
        emit("close");
    }
};

const fetchShares = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get<CalendarSharesResponse>(
            "/api/calendar/shares"
        );
        shares.value = response.data.my_shares;
        hasSharedWithMe.value = response.data.shared_with_me;
    } catch (e) {
        console.error("Failed to fetch shares", e);
    } finally {
        isLoading.value = false;
    }
};

watch(
    () => props.isOpen,
    (newVal) => {
        if (newVal) {
            fetchShares();
            selectedParticipants.value = [];
        }
    }
);

const addShares = async () => {
    if (selectedParticipants.value.length === 0) return;

    isAdding.value = true;
    errorMsg.value = "";

    const errors: string[] = [];

    for (const p of selectedParticipants.value) {
        // Since allowExternal is false, they should usually be users with IDs or emails
        // But the API might expect email.
        const emailToShare = p.email;

        if (!emailToShare) continue;

        try {
            await axios.post("/api/calendar/shares", {
                email: emailToShare,
                permission: newSharePermission.value,
            });
        } catch (e: any) {
            const msg =
                e.response?.data?.message ||
                `Failed to share with ${p.name || p.email}`;
            errors.push(msg);
        }
    }

    if (errors.length > 0) {
        errorMsg.value = errors.join(", ");
    } else {
        selectedParticipants.value = [];
    }

    await fetchShares();
    isAdding.value = false;
};

const updateShare = async (id: number, permission: string) => {
    try {
        await axios.put(`/api/calendar/shares/${id}`, { permission });
        await fetchShares();
    } catch (e) {
        console.error("Failed to update share", e);
        alert("Failed to update permission.");
    }
};

const removeShare = async (id: number) => {
    if (!confirm("Are you sure you want to stop sharing with this user?"))
        return;

    try {
        await axios.delete(`/api/calendar/shares/${id}`);
        await fetchShares();
    } catch (e) {
        console.error("Failed to remove share", e);
        alert("Failed to revoke access.");
    }
};

const getInitials = (name: string) => {
    return name
        .split(" ")
        .map((word) => word[0])
        .join("")
        .toUpperCase()
        .slice(0, 2);
};
</script>

<template>
    <Modal
        :open="isOpen"
        title="Share Calendar"
        size="md"
        @update:open="handleOpenUpdate"
    >
        <div class="space-y-6">
            <!-- Add New Share -->
            <div>
                <label
                    class="block text-sm font-medium text-[var(--text-secondary)] mb-2"
                    >Share with people</label
                >
                <div class="space-y-3">
                    <ParticipantSelector
                        v-model="selectedParticipants"
                        :allow-external="false"
                        :excluded-ids="currentUserId ? [currentUserId] : []"
                        placeholder="Search users..."
                        class="w-full"
                    />

                    <div class="flex items-center justify-end gap-2">
                        <select
                            v-model="newSharePermission"
                            class="h-9 rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] text-sm px-3 focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/30"
                        >
                            <option value="view">Can view</option>
                            <option value="edit">Can edit</option>
                        </select>
                        <Button
                            @click="addShares"
                            :disabled="
                                selectedParticipants.length === 0 || isAdding
                            "
                            :loading="isAdding"
                            variant="primary"
                            size="sm"
                        >
                            Invite
                        </Button>
                    </div>
                </div>
                <p
                    v-if="errorMsg"
                    class="mt-2 text-sm text-[var(--color-error)]"
                >
                    {{ errorMsg }}
                </p>
            </div>

            <!-- Shared With User List -->
            <div>
                <h4 class="text-sm font-medium text-[var(--text-primary)] mb-3">
                    People with access
                </h4>

                <div
                    v-if="isLoading && shares.length === 0"
                    class="text-center py-8 text-[var(--text-muted)]"
                >
                    Loading...
                </div>

                <ul
                    v-else-if="shares.length > 0"
                    class="divide-y divide-[var(--border-subtle)]"
                >
                    <li
                        v-for="share in shares"
                        :key="share.share_id"
                        class="py-3 flex items-center justify-between group"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="h-8 w-8 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-[var(--text-secondary)] font-bold text-xs"
                            >
                                {{ getInitials(share.name) }}
                            </div>
                            <div>
                                <p
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                >
                                    {{ share.name }}
                                </p>
                                <p class="text-xs text-[var(--text-secondary)]">
                                    {{ share.email }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <select
                                :value="share.permission_level"
                                @change="
                                    updateShare(
                                        share.share_id,
                                        ($event.target as HTMLSelectElement)
                                            .value
                                    )
                                "
                                class="text-xs border-none bg-transparent text-[var(--text-secondary)] focus:ring-0 cursor-pointer hover:bg-[var(--surface-secondary)] rounded py-1 px-2 transition-colors"
                            >
                                <option value="view">Can view</option>
                                <option value="edit">Can edit</option>
                            </select>
                            <button
                                @click="removeShare(share.share_id)"
                                class="text-[var(--text-muted)] hover:text-[var(--color-error)] p-1.5 rounded-md hover:bg-[var(--surface-secondary)] transition-colors opacity-0 group-hover:opacity-100 focus:opacity-100"
                                title="Revoke access"
                            >
                                <Trash class="h-4 w-4" />
                            </button>
                        </div>
                    </li>
                </ul>

                <div
                    v-else
                    class="text-center py-8 bg-[var(--surface-secondary)]/30 rounded-lg border border-dashed border-[var(--border-default)]"
                >
                    <p class="text-sm text-[var(--text-muted)]">
                        Not shared with anyone yet.
                    </p>
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="secondary" @click="closeModal"> Done </Button>
        </template>
    </Modal>
</template>
