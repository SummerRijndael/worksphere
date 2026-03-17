<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { useRouter, useRoute } from "vue-router";
import { useToast } from "@/composables/useToast.ts";
import api from "@/lib/api";
import { useDate } from "@/composables/useDate";
import { escapeHtml } from "@/utils/sanitize";
const { formatRelativeTime } = useDate();
import {
    Card,
    Button,
    Badge,
    Avatar,
    Modal,
    Input,
    TagInput,
    RichTextEditor,
    Textarea,
    Dropdown,
    DropdownItem,
    DropdownLabel,
    DropdownSeparator,
    Alert,
    ComboBox,
} from "@/components/ui";
import {
    Plus,
    Search,
    Filter,
    Clock,
    CheckCircle2,
    AlertCircle,
    MessageSquare,
    Paperclip,
    Calendar,
    User,
    Tag,
    ChevronLeft,
    ChevronRight,
    ArrowUpRight,
    ArrowDownRight,
    Minus,
    Loader2,
    AlertTriangle,
    RotateCw,
} from "lucide-vue-next";
import { useSupportChatStore } from "@/stores/supportChat";
import { useAuthStore } from "@/stores/auth";

const router = useRouter();
const route = useRoute();
const toast = useToast();
const chatStore = useSupportChatStore();
const authStore = useAuthStore();

const openSupportChat = () => {
    chatStore.openChat(authStore.isAuthenticated ? 'history' : 'intro');
};

// Loading states
const isLoading = ref(true);
const loadError = ref(null);

// Modal state
const showNewTicketModal = ref(false);
const isSubmitting = ref(false);

// New ticket form
const newTicket = ref({
    title: "",
    description: "",
    type: "bug",
    tags: [],
});

// Data from API
const tickets = ref([]);
const filteredTickets = computed(() => tickets.value); // No complex filtering for now

const activeView = ref('my'); // Constrained to 'my' tickets

// Pagination state
const currentPage = ref(1);
const perPage = ref(20);
const totalPages = ref(1);
const totalItems = ref(0);

// Fetch tickets from API
async function fetchTickets() {
    try {
        isLoading.value = true;
        loadError.value = null;

        const params = {
            page: currentPage.value,
            per_page: perPage.value,
            reporter_id: 'me', // Force filter
        };

        const response = await api.get("/api/tickets", { params });

        // Update pagination from response
        const meta = response.data.meta || {};
        totalPages.value = meta.last_page || 1;
        totalItems.value = meta.total || response.data.data.length;
        currentPage.value = meta.current_page || 1;

        tickets.value = response.data.data.map((ticket) => ({
            id: ticket.id,
            displayId: ticket.display_id,
            title: ticket.title,
            description: ticket.description,
            status: ticket.status.value,
            priority: ticket.priority.value,
            type: ticket.type.value,
            tags: ticket.tags || [],
            assignee: ticket.assignee, // Display only
            createdAt: formatRelativeTime(ticket.created_at),
            updatedAt: formatRelativeTime(ticket.updated_at),
            comments: ticket.comment_count || 0,
        }));
    } catch (error) {
        console.error("Failed to fetch tickets:", error);
        loadError.value = "Failed to load tickets. Please try again.";
        toast.error("Error", "Failed to load tickets.");
    } finally {
        isLoading.value = false;
    }
}

function goToPage(page) {
    if (page >= 1 && page <= totalPages.value) {
        currentPage.value = page;
        fetchTickets();
    }
}


function getStatusConfig(status) {
    const configs = {
        open: { label: "Open", variant: "default", icon: AlertCircle },
        in_progress: { label: "In Progress", variant: "primary", icon: Clock },
        resolved: { label: "Resolved", variant: "success", icon: CheckCircle2 },
        closed: { label: "Closed", variant: "secondary", icon: CheckCircle2 },
    };
    return configs[status] || configs.open;
}

function getPriorityConfig(priority) {
    const configs = {
        critical: { label: "Critical", icon: ArrowUpRight, bgClass: "bg-red-500" },
        high: { label: "High", icon: ArrowUpRight, bgClass: "bg-orange-500" },
        medium: { label: "Medium", icon: Minus, bgClass: "bg-amber-500" },
        low: { label: "Low", icon: ArrowDownRight, bgClass: "bg-emerald-500" },
    };
    return configs[priority] || configs.medium;
}

function getTypeConfig(type) {
    const configs = {
        bug: { label: "Bug", variant: "danger" },
        feature: { label: "Feature", variant: "primary" },
        task: { label: "Task", variant: "secondary" },
        question: { label: "Question", variant: "warning" },
        improvement: { label: "Improvement", variant: "success" },
    };
    return configs[type] || configs.task;
}

function resetNewTicketForm() {
    newTicket.value = {
        title: "",
        description: "",
        priority: "medium",
        type: "bug",
        tags: [],
    };
}

async function handleSubmitTicket() {
    if (!newTicket.value.title.trim()) {
        toast.error("Validation Error", "Please enter a ticket title.");
        return;
    }

    isSubmitting.value = true;

    try {
        let finalDescription = escapeHtml(newTicket.value.description).replace(/\n/g, '<br>');

        await api.post("/api/tickets", {
            title: newTicket.value.title,
            description: finalDescription,
            type: newTicket.value.type,
            tags: newTicket.value.tags,
        });

        showNewTicketModal.value = false;
        toast.success(
            "Ticket Created",
            "Your ticket has been submitted. Our team will review it shortly.",
        );
        fetchTickets();
    } catch (error) {
        console.error("Failed to create ticket:", error);
        toast.error(
            "Error",
            error.response?.data?.message || "Failed to create ticket.",
        );
    } finally {
        isSubmitting.value = false;
    }
}

function viewTicket(ticketId) {
    router.push({ name: "ticket-detail", params: { id: ticketId } });
}

onMounted(() => {
    fetchTickets();
});
</script>

<template>
    <div>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-[var(--text-primary)]">
                        Help Desk
                    </h1>
                    <p class="text-[var(--text-secondary)]">
                        Submit and track your support requests.
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Button variant="outline" @click="openSupportChat">
                        <MessageSquare class="mr-2 h-4 w-4" />
                        Live Chat
                    </Button>
                    <Button variant="outline" @click="fetchTickets" :disabled="isLoading">
                        <RotateCw class="mr-2 h-4 w-4" :class="{ 'animate-spin': isLoading }" />
                        Refresh
                    </Button>
                    <Button @click="showNewTicketModal = true">
                        <Plus class="mr-2 h-4 w-4" />
                        Submit Ticket
                    </Button>
                </div>
            </div>

            <!-- Ticket List -->
            <Card class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-[var(--text-secondary)] uppercase bg-[var(--surface-secondary)] border-b border-[var(--border-default)]">
                            <tr>
                                <th class="px-6 py-3 font-medium tracking-wider w-[100px]">ID</th>
                                <th class="px-6 py-3 font-medium tracking-wider">Subject</th>
                                <th class="px-6 py-3 font-medium tracking-wider w-[140px]">Status</th>
                                <th class="px-6 py-3 font-medium tracking-wider w-[140px] hidden sm:table-cell">Priority</th>
                                <th class="px-6 py-3 font-medium tracking-wider w-[180px] hidden md:table-cell">Last Updated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border-default)]">
                            <tr v-if="isLoading" class="bg-[var(--surface-primary)]">
                                <td colspan="5" class="px-6 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        <Loader2 class="h-8 w-8 animate-spin text-[var(--interactive-primary)] mb-4" />
                                        <p class="text-[var(--text-secondary)]">Loading your tickets...</p>
                                    </div>
                                </td>
                            </tr>
                            <tr v-else-if="tickets.length === 0" class="bg-[var(--surface-primary)]">
                                <td colspan="5" class="px-6 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center">
                                        <div class="h-12 w-12 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mb-4">
                                            <MessageSquare class="h-6 w-6 text-[var(--text-secondary)]" />
                                        </div>
                                        <h3 class="text-lg font-medium text-[var(--text-primary)] mb-1">No tickets found</h3>
                                        <p class="text-[var(--text-secondary)] mb-4">You haven't submitted any support requests yet.</p>
                                        <Button @click="showNewTicketModal = true" variant="outline">
                                            Submit your first ticket
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                            <tr
                                v-for="ticket in tickets"
                                :key="ticket.id"
                                @click="viewTicket(ticket.id)"
                                class="bg-[var(--surface-primary)] hover:bg-[var(--surface-secondary)]/50 transition-colors cursor-pointer group"
                            >
                                <td class="px-6 py-4 font-mono text-xs text-[var(--text-secondary)]">
                                    {{ ticket.displayId }}
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-medium text-[var(--text-primary)] group-hover:text-[var(--interactive-primary)] transition-colors">
                                        {{ ticket.title }}
                                    </div>
                                    <div class="text-xs text-[var(--text-muted)] mt-0.5 line-clamp-1">
                                        <span v-if="ticket.comments > 0" class="inline-flex items-center mr-2">
                                            <MessageSquare class="w-3 h-3 mr-1" /> {{ ticket.comments }}
                                        </span>
                                        Created {{ ticket.createdAt }}
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <Badge :variant="getStatusConfig(ticket.status).variant" size="sm">
                                        <component :is="getStatusConfig(ticket.status).icon" class="h-3 w-3 mr-1" />
                                        {{ getStatusConfig(ticket.status).label }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4 hidden sm:table-cell">
                                    <div class="flex items-center gap-1.5">
                                        <component :is="getPriorityConfig(ticket.priority).icon" 
                                            class="w-3.5 h-3.5" 
                                            :class="getPriorityConfig(ticket.priority).bgClass.replace('bg-', 'text-')" 
                                        />
                                        <span class="text-sm text-[var(--text-secondary)] capitalize">{{ ticket.priority }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 hidden md:table-cell text-sm text-[var(--text-secondary)]">
                                    {{ ticket.updatedAt }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div v-if="totalPages > 1" class="border-t border-[var(--border-default)] px-6 py-4 flex items-center justify-between">
                    <p class="text-sm text-[var(--text-secondary)]">
                        Showing <span class="font-medium">{{ (currentPage - 1) * perPage + 1 }}</span> to <span class="font-medium">{{ Math.min(currentPage * perPage, totalItems) }}</span> of <span class="font-medium">{{ totalItems }}</span> results
                    </p>
                    <div class="flex gap-2">
                        <Button variant="outline" size="sm" :disabled="currentPage === 1" @click="goToPage(currentPage - 1)">
                            <ChevronLeft class="h-4 w-4" />
                        </Button>
                        <Button variant="outline" size="sm" :disabled="currentPage === totalPages" @click="goToPage(currentPage + 1)">
                            <ChevronRight class="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            </Card>
        </div>

        <!-- New Ticket Modal -->
        <Modal v-model:open="showNewTicketModal" title="Submit Support Request">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1">Subject</label>
                    <Input v-model="newTicket.title" placeholder="Brief summary of the issue" autofocus />
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1">Type</label>
                    <select
                        v-model="newTicket.type"
                        class="w-full rounded-md border border-[var(--border-default)] bg-[var(--surface-primary)] px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)] text-[var(--text-primary)]"
                    >
                        <option value="bug">Bug Report</option>
                        <option value="feature">Feature Request</option>
                        <option value="question">Question</option>
                        <option value="task">Task / Request</option>
                        <option value="improvement">Improvement</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1">Description</label>
                    <Textarea 
                        v-model="newTicket.description" 
                        placeholder="Please provide detailed information about your request..." 
                        rows="6"
                        class="w-full"
                    />
                    <p class="text-xs text-[var(--text-muted)] mt-1">
                        Providing specific details helps us resolve your issue faster.
                    </p>
                </div>
            </div>
            
            <template #footer>
                <div class="flex justify-end gap-3">
                    <Button variant="ghost" @click="showNewTicketModal = false">Cancel</Button>
                    <Button :loading="isSubmitting" @click="handleSubmitTicket">Submit Ticket</Button>
                </div>
            </template>
        </Modal>
    </div>
</template>
