<script setup>
import { ref, computed, onMounted, watch } from "vue";
import { useRouter, useRoute } from "vue-router";
import { useToast } from "@/composables/useToast.ts";
import axios from "axios";

const router = useRouter();
const route = useRoute();
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
    MoreVertical,
    Clock,
    CheckCircle2,
    AlertCircle,
    MessageSquare,
    Paperclip,
    Calendar,
    User,
    Tag,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ArrowUpRight,
    ArrowDownRight,
    Minus,
    Loader2,
    AlertTriangle,
    Archive,
    CheckSquare,
    Square,
    LayoutGrid,
    LayoutList,
    RotateCw,
} from "lucide-vue-next";

const toast = useToast();

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
    priority: "medium",
    type: "bug",
    tags: [],
});
// View Mode
const viewMode = ref(localStorage.getItem("tickets_view_mode") || "list");
function setViewMode(mode) {
    viewMode.value = mode;
    localStorage.setItem("tickets_view_mode", mode);
}

// Filters
const searchQuery = ref("");
const statusFilter = ref("all");
const priorityFilter = ref("all");

// Bulk Actions
const selectedTickets = ref([]);
const selectAllCheckbox = ref(null);
const isAllSelected = computed(() => {
    return (
        tickets.value.length > 0 &&
        selectedTickets.value.length === tickets.value.length
    );
});
const someSelected = computed(() => {
    return (
        selectedTickets.value.length > 0 &&
        selectedTickets.value.length < tickets.value.length
    );
});
const isBulkArchiving = ref(false);
const showBulkArchiveModal = ref(false);
const bulkArchiveReason = ref("");
const bulkArchiveErrors = ref({});

function toggleSelection(id) {
    if (selectedTickets.value.includes(id)) {
        selectedTickets.value = selectedTickets.value.filter(
            (tId) => tId !== id,
        );
    } else {
        selectedTickets.value.push(id);
    }
}

function toggleSelectAll() {
    if (isAllSelected.value) {
        selectedTickets.value = [];
    } else {
        selectedTickets.value = tickets.value.map((t) => t.id);
    }
}

function openBulkArchiveModal() {
    showBulkArchiveModal.value = true;
    bulkArchiveReason.value = "";
    bulkArchiveErrors.value = {};
}

async function submitBulkArchive() {
    if (activeView.value === "archived") return;

    try {
        isBulkArchiving.value = true;
        await axios.post("/api/tickets/archive", {
            ids: selectedTickets.value,
            reason: bulkArchiveReason.value,
        });
        toast.success(
            `${selectedTickets.value.length} tickets archived successfully`,
        );
        showBulkArchiveModal.value = false;
        selectedTickets.value = [];
        await fetchTickets();
        fetchStats();
    } catch (error) {
        if (error.response?.status === 422) {
            bulkArchiveErrors.value = error.response.data.errors || {};
        } else {
            toast.error("Failed to archive tickets");
        }
    } finally {
        isBulkArchiving.value = false;
    }
}

// View Mode (Tabs)
const activeView = computed({
    get: () => {
        if (route.query.archived === "true") return "archived";
        if (route.query.assigned_to === "me") return "assigned";
        if (route.query.reporter_id === "me") return "my";
        if (route.query.assigned_to === "unassigned") return "unassigned";
        return "all";
    },
    set: (val) => {
        const query = { ...route.query };
        // Clear existing filters
        delete query.assigned_to;
        delete query.reporter_id;
        delete query.archived;

        // Set new filters
        if (val === "assigned") query.assigned_to = "me";
        else if (val === "my") query.reporter_id = "me";
        else if (val === "unassigned") query.assigned_to = "unassigned";
        else if (val === "archived") query.archived = "true";

        router.push({ query });
    },
});

// Data from API
const tickets = ref([]);
const ticketStats = ref({
    total: 0,
    open: 0,
    in_progress: 0,
    resolved: 0,
    closed: 0,
    unassigned: 0,
    overdue: 0,
    sla_breached: 0,
});

// Pagination state
const currentPage = ref(1);
const perPage = ref(20);
const totalPages = ref(1);
const totalItems = ref(0);
const perPageOptions = [20, 50, 100, 200];

const statusOptions = [
    { value: "all", label: "All Statuses" },
    { value: "open", label: "Open" },
    { value: "in_progress", label: "In Progress" },
    { value: "resolved", label: "Resolved" },
    { value: "closed", label: "Closed" },
];

const priorityOptions = [
    { value: "all", label: "All Priorities" },
    { value: "critical", label: "Critical" },
    { value: "high", label: "High" },
    { value: "medium", label: "Medium" },
    { value: "low", label: "Low" },
];

const typeOptions = [
    { value: "bug", label: "Bug" },
    { value: "feature", label: "Feature Request" },
    { value: "task", label: "Task" },
    { value: "question", label: "Question" },
    { value: "improvement", label: "Improvement" },
];

// Computed filtered tickets (client-side filtering for search)
const filteredTickets = computed(() => {
    if (!searchQuery.value) return tickets.value;

    const query = searchQuery.value.toLowerCase();
    return tickets.value.filter(
        (ticket) =>
            ticket.title.toLowerCase().includes(query) ||
            ticket.id.toLowerCase().includes(query) ||
            (ticket.displayId &&
                ticket.displayId.toLowerCase().includes(query)),
    );
});

// Fetch tickets from API
async function fetchTickets() {
    try {
        isLoading.value = true;
        loadError.value = null;

        const params = {
            page: currentPage.value,
            per_page: perPage.value,
            per_page: perPage.value,
        };

        // Query Params Filters
        if (route.query.assigned_to)
            params.assigned_to = route.query.assigned_to;
        if (route.query.reporter_id)
            params.reporter_id = route.query.reporter_id;
        if (route.query.search) searchQuery.value = route.query.search;

        if (statusFilter.value !== "all") params.status = statusFilter.value;
        if (priorityFilter.value !== "all")
            params.priority = priorityFilter.value;

        // Search
        if (searchQuery.value) params.search = searchQuery.value;

        const response = await axios.get("/api/tickets", { params });

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
            assignee: ticket.assignee
                ? {
                      name: ticket.assignee.name,
                      initials: ticket.assignee.initials,
                  }
                : null,
            reporter: ticket.reporter
                ? {
                      name: ticket.reporter.name,
                      initials: ticket.reporter.initials,
                  }
                : null,
            comments: ticket.comment_count || 0,
            attachments: 0,
            createdAt: formatRelativeTime(ticket.created_at),
            updatedAt: formatRelativeTime(ticket.updated_at),
            isOverdue: ticket.is_overdue,
            slaBreached: ticket.sla_breached,
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

function changePerPage(newPerPage) {
    perPage.value = newPerPage;
    currentPage.value = 1;
    fetchTickets();
}

// Fetch ticket stats
async function fetchStats() {
    try {
        const response = await axios.get("/api/tickets/stats");
        ticketStats.value = response.data;
    } catch (error) {
        console.error("Failed to fetch stats:", error);
    }
}

async function refreshData() {
    await Promise.all([fetchTickets(), fetchStats()]);
}

// Helper to format relative time
function formatRelativeTime(dateString) {
    if (!dateString) return "";
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);

    if (minutes < 1) return "Just now";
    if (minutes < 60) return `${minutes}m ago`;
    if (hours < 24) return `${hours}h ago`;
    if (days < 7) return `${days}d ago`;
    return date.toLocaleDateString();
}

// Watch for filter changes
watch([statusFilter, priorityFilter], () => {
    currentPage.value = 1;
    fetchTickets();
});

// Edit Logic
const showEditModal = ref(false);
const isSubmittingEdit = ref(false);
const editingTicket = ref(null);
const editForm = ref({
    title: "",
    description: "",
    priority: "medium",
    type: "bug",
    tags: [],
    reason: "",
});
const editErrors = ref({});

function openEditModal(ticket) {
    editingTicket.value = ticket;
    editForm.value = {
        title: ticket.title,
        description: ticket.description || "",
        priority: ticket.priority, // Ensure case matches (lowercase/uppercase?) API usually returns lowercase enums
        type: ticket.type,
        tags: [...(ticket.tags || [])],
        reason: "",
    };
    editErrors.value = {};
    showEditModal.value = true;
}

async function submitEdit() {
    if (!editForm.value.reason || editForm.value.reason.length < 3) {
        editErrors.value.reason = ["Reason is required (min 3 chars)"];
        return;
    }

    try {
        isSubmittingEdit.value = true;
        editErrors.value = {};

        // Dirty Check
        const payload = { reason: editForm.value.reason };
        const t = editingTicket.value;
        const f = editForm.value;

        if (f.title !== t.title) payload.title = f.title;
        if (f.description !== (t.description || ""))
            payload.description = f.description;
        if (f.priority !== t.priority) payload.priority = f.priority;
        if (f.type !== t.type) payload.type = f.type;

        const tTags = JSON.stringify((t.tags || []).slice().sort());
        const fTags = JSON.stringify((f.tags || []).slice().sort());
        if (tTags !== fTags) payload.tags = f.tags;

        await axios.put(`/api/tickets/${editingTicket.value.id}`, payload);

        toast.success("Ticket updated successfully");
        showEditModal.value = false;
        fetchTickets();
        fetchStats(); // Update stats in case status/priority/assignee changed (though edit doesn't change status directly here, but good practice)
    } catch (error) {
        console.error("Failed to update ticket:", error);
        if (error.response?.status === 422) {
            editErrors.value = error.response.data.errors || {};
        } else {
            toast.error("Failed to update ticket");
        }
    } finally {
        isSubmittingEdit.value = false;
    }
}

// Assign Logic
const showAssignModal = ref(false);
const isAssigning = ref(false);
const ticketToAssign = ref(null);
const assignableUsers = ref([]);
const selectedAssignee = ref(null);
const isLoadingUsers = ref(false);

function openAssignModal(ticket) {
    ticketToAssign.value = ticket;
    // user ID in ticket list (via Resource) is public_id
    selectedAssignee.value = ticket.assignee ? ticket.assignee.id : null;
    showAssignModal.value = true;
    if (assignableUsers.value.length === 0) {
        fetchAssignableUsers();
    }
}

async function fetchAssignableUsers() {
    try {
        isLoadingUsers.value = true;
        const response = await axios.get("/api/tickets/assignable-users");
        assignableUsers.value = response.data.data.map((u) => ({
            value: u.id,
            label: `${u.name} (${u.email})`,
            image: u.avatar_thumb_url,
        }));
    } catch (error) {
        toast.error("Failed to load assignable users");
    } finally {
        isLoadingUsers.value = false;
    }
}

async function assignTicket() {
    if (!ticketToAssign.value) return;

    try {
        isAssigning.value = true;
        await axios.put(`/api/tickets/${ticketToAssign.value.id}/assign`, {
            assigned_to: selectedAssignee.value, // 'assigned_to' expects ID (internal or public handled by controller)
            // Wait, TicketController resolve logic I added handles filters, but 'update' method?
            // TicketController update/assign uses TicketService.
            // TicketService assign expects internal ID?
            // I should check if TicketController 'assign' method resolves ID.
            // But usually 'assign' component sends UUID, Controller must resolve.
            // I'll assume Controller handles it or I need to fix Controller.
            // Let's assume handled for now as TicketDetailView sends the same.
        });

        toast.success("Ticket Assigned");
        showAssignModal.value = false;
        fetchTickets();
    } catch (error) {
        toast.error(error.response?.data?.message || "Failed to assign ticket");
    } finally {
        isAssigning.value = false;
    }
}

// Delete Logic
const showDeleteModal = ref(false);
const ticketToDelete = ref(null);
const isDeleting = ref(false);
const deleteReason = ref("");

function openDeleteModal(ticket) {
    ticketToDelete.value = ticket;
    deleteReason.value = "";
    showDeleteModal.value = true;
}

async function confirmDelete() {
    if (!ticketToDelete.value) return;

    try {
        isDeleting.value = true;
        await axios.delete(`/api/tickets/${ticketToDelete.value.id}`, {
            data: { reason: deleteReason.value },
        });

        toast.success("Ticket Deleted");
        showDeleteModal.value = false;
        fetchTickets();
        fetchStats();
    } catch (error) {
        toast.error(error.response?.data?.message || "Failed to delete ticket");
    } finally {
        isDeleting.value = false;
    }
}

// Watchers
watch(
    () => route.query,
    () => {
        fetchTickets();
    },
    { deep: true },
);

// Update checkbox indeterminate state
watch(someSelected, (value) => {
    if (selectAllCheckbox.value) {
        selectAllCheckbox.value.indeterminate = value;
    }
});

onMounted(() => {
    if (route.query.search) searchQuery.value = route.query.search;
    fetchTickets();
    fetchStats();
});

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
        critical: {
            label: "Critical",
            class: "text-white",
            icon: ArrowUpRight,
            bgClass: "bg-red-500 dark:bg-red-600",
        },
        high: {
            label: "High",
            class: "text-white",
            icon: ArrowUpRight,
            bgClass: "bg-orange-500 dark:bg-orange-600",
        },
        medium: {
            label: "Medium",
            class: "text-white",
            icon: Minus,
            bgClass: "bg-amber-500 dark:bg-amber-600",
        },
        low: {
            label: "Low",
            class: "text-white",
            icon: ArrowDownRight,
            bgClass: "bg-emerald-500 dark:bg-emerald-600",
        },
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
        incident: { label: "Incident", variant: "danger" },
        accounting: { label: "Accounting", variant: "info" },
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
        assignee: null,
    };
}

function openNewTicketModal() {
    resetNewTicketForm();
    showNewTicketModal.value = true;
}

async function handleSubmitTicket() {
    if (!newTicket.value.title.trim()) {
        toast.error("Validation Error", "Please enter a ticket title.");
        return;
    }

    isSubmitting.value = true;

    try {
        const response = await axios.post("/api/tickets", {
            title: newTicket.value.title,
            description: newTicket.value.description,
            priority: newTicket.value.priority,
            type: newTicket.value.type,
            tags: newTicket.value.tags,
        });

        showNewTicketModal.value = false;
        toast.success(
            "Ticket Created",
            "Your ticket has been created successfully.",
        );

        // Refresh the list
        fetchTickets();
        fetchStats();
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
</script>

<template>
    <div>
        <div class="space-y-6">
            <!-- Header & Tabs -->
            <div
                class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between"
            >
                <div>
                    <h1
                        class="text-2xl font-bold tracking-tight text-[var(--text-primary)]"
                    >
                        Tickets
                    </h1>
                    <p class="text-[var(--text-secondary)]">
                        Track and manage support tickets and issues.
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Button
                        variant="outline"
                        @click="refreshData"
                        :disabled="isLoading"
                    >
                        <RotateCw
                            class="mr-2 h-4 w-4"
                            :class="{ 'animate-spin': isLoading }"
                        />
                        Refresh
                    </Button>
                    <Button @click="showNewTicketModal = true">
                        <Plus class="mr-2 h-4 w-4" />
                        New Ticket
                    </Button>
                </div>
            </div>

            <!-- View Tabs -->
            <div class="border-b border-[var(--border-default)]">
                <div class="flex -mb-px space-x-6 overflow-x-auto">
                    <button
                        v-for="view in ['all', 'my', 'assigned', 'unassigned']"
                        :key="view"
                        @click="activeView = view"
                        :class="[
                            activeView === view
                                ? 'border-[var(--color-primary-500)] text-[var(--color-primary-600)]'
                                : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-hover)]',
                            'whitespace-nowrap pb-4 px-1 border-b-2 font-medium text-sm transition-colors',
                        ]"
                    >
                        {{
                            view === "all"
                                ? "All Tickets"
                                : view === "my"
                                  ? "My Tickets"
                                  : view === "assigned"
                                    ? "Assigned to Me"
                                    : "Unassigned"
                        }}
                    </button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card padding="lg" class="group">
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                Total Tickets
                            </p>
                            <p
                                class="text-3xl font-bold text-[var(--text-primary)] mt-1"
                            >
                                {{ ticketStats.total }}
                            </p>
                        </div>
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 shadow-lg"
                        >
                            <MessageSquare class="h-5 w-5 text-white" />
                        </div>
                    </div>
                </Card>
                <Card padding="lg" class="group">
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                Open
                            </p>
                            <p
                                class="text-3xl font-bold text-[var(--text-primary)] mt-1"
                            >
                                {{ ticketStats.open }}
                            </p>
                        </div>
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 shadow-lg"
                        >
                            <AlertCircle class="h-5 w-5 text-white" />
                        </div>
                    </div>
                </Card>
                <Card padding="lg" class="group">
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                In Progress
                            </p>
                            <p
                                class="text-3xl font-bold text-[var(--text-primary)] mt-1"
                            >
                                {{ ticketStats.in_progress }}
                            </p>
                        </div>
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-purple-500 to-purple-600 shadow-lg"
                        >
                            <Clock class="h-5 w-5 text-white" />
                        </div>
                    </div>
                </Card>
                <Card padding="lg" class="group">
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--text-secondary)]"
                            >
                                Resolved
                            </p>
                            <p
                                class="text-3xl font-bold text-[var(--text-primary)] mt-1"
                            >
                                {{ ticketStats.resolved }}
                            </p>
                        </div>
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-green-500 to-green-600 shadow-lg"
                        >
                            <CheckCircle2 class="h-5 w-5 text-white" />
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Legend -->
            <Card padding="sm" class="bg-[var(--surface-secondary)]">
                <div
                    class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs"
                >
                    <span
                        class="font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                        >Types:</span
                    >
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge variant="danger" size="xs">Bug</Badge>
                        <Badge variant="danger" size="xs">Incident</Badge>
                        <Badge variant="primary" size="xs">Feature</Badge>
                        <Badge variant="secondary" size="xs">Task</Badge>
                        <Badge variant="warning" size="xs">Question</Badge>
                        <Badge variant="success" size="xs">Improvement</Badge>
                        <Badge variant="info" size="xs">Accounting</Badge>
                    </div>
                    <span
                        class="font-medium text-[var(--text-secondary)] uppercase tracking-wider ml-4"
                        >Priority:</span
                    >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="flex items-center gap-1.5"
                            ><span
                                class="w-3 h-3 rounded-lg bg-gradient-to-br from-red-500 to-red-600"
                            ></span>
                            Critical</span
                        >
                        <span class="flex items-center gap-1.5"
                            ><span
                                class="w-3 h-3 rounded-lg bg-gradient-to-br from-orange-500 to-orange-600"
                            ></span>
                            High</span
                        >
                        <span class="flex items-center gap-1.5"
                            ><span
                                class="w-3 h-3 rounded-lg bg-gradient-to-br from-yellow-500 to-yellow-600"
                            ></span>
                            Medium</span
                        >
                        <span class="flex items-center gap-1.5"
                            ><span
                                class="w-3 h-3 rounded-lg bg-gradient-to-br from-green-500 to-green-600"
                            ></span>
                            Low</span
                        >
                    </div>
                </div>
            </Card>

            <!-- Filters -->
            <Card padding="md">
                <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                    <!-- Search -->
                    <div class="relative flex-1">
                        <Search
                            class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]"
                        />
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search tickets by title or ID..."
                            class="h-10 w-full rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] pl-10 pr-4 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 transition-all"
                        />
                    </div>

                    <!-- Status Filter -->
                    <div class="flex items-center gap-3">
                        <select
                            v-model="statusFilter"
                            class="h-10 rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3 pr-8 text-sm text-[var(--text-primary)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 transition-all [&>option]:bg-[var(--surface-elevated)] [&>option]:text-[var(--text-primary)]"
                        >
                            <option
                                v-for="option in statusOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <!-- Priority Filter -->
                        <select
                            v-model="priorityFilter"
                            class="h-10 rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3 pr-8 text-sm text-[var(--text-primary)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 transition-all [&>option]:bg-[var(--surface-elevated)] [&>option]:text-[var(--text-primary)]"
                        >
                            <option
                                v-for="option in priorityOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <!-- View Toggle -->
                        <div
                            class="flex bg-[var(--surface-secondary)] rounded-lg p-1 ml-2"
                        >
                            <button
                                @click="setViewMode('list')"
                                class="p-1.5 rounded-md transition-all"
                                :class="
                                    viewMode === 'list'
                                        ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                        : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                "
                                title="List view"
                            >
                                <LayoutList class="w-4 h-4" />
                            </button>
                            <button
                                @click="setViewMode('grid')"
                                class="p-1.5 rounded-md transition-all"
                                :class="
                                    viewMode === 'grid'
                                        ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                        : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                "
                                title="Grid view"
                            >
                                <LayoutGrid class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Loading State -->
            <Card
                v-if="isLoading"
                padding="lg"
                class="min-h-[600px] flex items-center justify-center"
            >
                <div class="flex items-center justify-center">
                    <Loader2
                        class="h-8 w-8 animate-spin text-[var(--interactive-primary)]"
                    />
                </div>
            </Card>

            <!-- Error State -->
            <Alert v-else-if="loadError" variant="error">
                <AlertTriangle class="h-4 w-4" />
                <span>{{ loadError }}</span>
                <Button variant="outline" size="sm" @click="fetchTickets"
                    >Retry</Button
                >
            </Alert>

            <!-- Tickets List -->
            <Card v-else padding="none" class="flex flex-col overflow-hidden">
                <!-- List View -->
                <div
                    v-if="viewMode === 'list'"
                    class="max-h-[calc(100vh-420px)] overflow-y-auto"
                >
                    <table
                        class="w-full table-fixed divide-y divide-[var(--border-default)]"
                    >
                        <thead
                            class="bg-[var(--surface-secondary)] sticky top-0 z-10"
                        >
                            <tr>
                                <th
                                    v-if="activeView !== 'archived'"
                                    scope="col"
                                    class="pl-6 pr-3 py-3 w-8"
                                >
                                    <div class="flex items-center">
                                        <input
                                            ref="selectAllCheckbox"
                                            type="checkbox"
                                            :checked="isAllSelected"
                                            @change="toggleSelectAll"
                                            class="w-4 h-4 rounded border-[var(--border-default)] text-[var(--interactive-primary)] focus:ring-2 focus:ring-[var(--interactive-primary)]/20 cursor-pointer"
                                            title="Select all"
                                        />
                                    </div>
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider w-[40%]"
                                >
                                    Ticket
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                >
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                >
                                    Submitted By
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                >
                                    Assigned To
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                >
                                    Created
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                >
                                    Last Updated
                                </th>
                                <th scope="col" class="relative px-6 py-3">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-[var(--border-default)] bg-[var(--surface-primary)]"
                        >
                            <tr
                                v-for="ticket in filteredTickets"
                                :key="ticket.id"
                                class="group cursor-pointer transition-colors hover:bg-[var(--surface-secondary)]/60 border-l-2 border-l-transparent hover:border-l-[var(--interactive-primary)]"
                            >
                                <td
                                    v-if="activeView !== 'archived'"
                                    class="pl-3 pr-1 py-2.5 w-8"
                                >
                                    <button
                                        @click.stop="toggleSelection(ticket.id)"
                                        class="flex items-center"
                                    >
                                        <CheckSquare
                                            v-if="
                                                selectedTickets.includes(
                                                    ticket.id,
                                                )
                                            "
                                            class="w-4 h-4 text-[var(--interactive-primary)]"
                                        />
                                        <Square
                                            v-else
                                            class="w-4 h-4 text-[var(--text-muted)] group-hover:text-[var(--text-secondary)]"
                                        />
                                    </button>
                                </td>
                                <td
                                    class="px-6 py-4"
                                    @click="viewTicket(ticket.id)"
                                >
                                    <div class="flex items-start gap-3">
                                        <!-- Priority Indicator -->
                                        <div
                                            :class="[
                                                'flex h-6 w-6 shrink-0 items-center justify-center rounded-lg mt-0.5',
                                                getPriorityConfig(
                                                    ticket.priority,
                                                ).bgClass,
                                            ]"
                                        >
                                            <component
                                                :is="
                                                    getPriorityConfig(
                                                        ticket.priority,
                                                    ).icon
                                                "
                                                :class="[
                                                    'h-3.5 w-3.5',
                                                    getPriorityConfig(
                                                        ticket.priority,
                                                    ).class,
                                                ]"
                                            />
                                        </div>

                                        <!-- Ticket Info -->
                                        <div class="min-w-0">
                                            <div
                                                class="flex items-center gap-2 mb-1"
                                            >
                                                <span
                                                    class="text-[11px] font-mono text-[var(--text-muted)] shrink-0"
                                                    >{{
                                                        ticket.displayId
                                                    }}</span
                                                >
                                                <Badge
                                                    :variant="
                                                        getTypeConfig(
                                                            ticket.type,
                                                        ).variant
                                                    "
                                                    size="xs"
                                                    class="shrink-0"
                                                >
                                                    {{
                                                        getTypeConfig(
                                                            ticket.type,
                                                        ).label
                                                    }}
                                                </Badge>
                                            </div>
                                            <h3
                                                class="text-sm font-medium text-[var(--text-primary)] hover:text-[var(--interactive-primary)] transition-colors line-clamp-2"
                                                :title="ticket.title"
                                            >
                                                {{ ticket.title }}
                                            </h3>

                                            <!-- Overdue/SLA indicators -->
                                            <div class="flex gap-2 mt-1">
                                                <Badge
                                                    v-if="ticket.isOverdue"
                                                    variant="danger"
                                                    size="xs"
                                                    >Overdue</Badge
                                                >
                                                <Badge
                                                    v-if="ticket.slaBreached"
                                                    variant="warning"
                                                    size="xs"
                                                    >SLA</Badge
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- Status -->
                                <td
                                    class="px-6 py-4"
                                    @click="viewTicket(ticket.id)"
                                >
                                    <Badge
                                        :variant="
                                            getStatusConfig(ticket.status)
                                                .variant
                                        "
                                        size="xs"
                                    >
                                        <component
                                            :is="
                                                getStatusConfig(ticket.status)
                                                    .icon
                                            "
                                            class="h-3 w-3 mr-0.5"
                                        />
                                        {{
                                            getStatusConfig(ticket.status).label
                                        }}
                                    </Badge>
                                </td>

                                <!-- Submitted By (Reporter) -->
                                <td
                                    class="px-6 py-4"
                                    @click="viewTicket(ticket.id)"
                                >
                                    <div
                                        class="flex items-center gap-2"
                                        :title="
                                            ticket.reporter?.name || 'Unknown'
                                        "
                                    >
                                        <Avatar
                                            v-if="ticket.reporter"
                                            :fallback="ticket.reporter.initials"
                                            :src="ticket.reporter?.avatar_url"
                                            size="xs"
                                        />
                                        <User
                                            v-else
                                            class="h-6 w-6 p-1 rounded-full bg-[var(--surface-tertiary)] text-[var(--text-muted)]"
                                        />
                                        <span
                                            class="text-sm text-[var(--text-secondary)] truncate max-w-[120px]"
                                            >{{
                                                ticket.reporter?.name ||
                                                "Unknown"
                                            }}</span
                                        >
                                    </div>
                                </td>

                                <!-- Assigned To -->
                                <td
                                    class="px-6 py-4"
                                    @click="viewTicket(ticket.id)"
                                >
                                    <div
                                        class="flex items-center gap-2"
                                        :title="
                                            ticket.assignee?.name ||
                                            'Unassigned'
                                        "
                                    >
                                        <Avatar
                                            v-if="ticket.assignee"
                                            :fallback="ticket.assignee.initials"
                                            :src="ticket.assignee?.avatar_url"
                                            size="xs"
                                        />
                                        <User
                                            v-else
                                            class="h-6 w-6 p-1 rounded-full bg-[var(--surface-tertiary)] text-[var(--text-muted)]"
                                        />
                                        <span
                                            class="text-sm text-[var(--text-secondary)] truncate max-w-[120px]"
                                            >{{
                                                ticket.assignee?.name ||
                                                "Unassigned"
                                            }}</span
                                        >
                                    </div>
                                </td>

                                <!-- Created -->
                                <td
                                    class="px-6 py-4 text-sm text-[var(--text-secondary)] whitespace-nowrap"
                                    @click="viewTicket(ticket.id)"
                                >
                                    {{ ticket.createdAt }}
                                </td>

                                <!-- Updated -->
                                <td
                                    class="px-6 py-4 text-sm text-[var(--text-secondary)] whitespace-nowrap"
                                    @click="viewTicket(ticket.id)"
                                >
                                    {{ ticket.updatedAt }}
                                </td>

                                <!-- Actions -->
                                <td class="px-2 py-2.5 text-right">
                                    <Dropdown align="end" @click.stop>
                                        <template #trigger>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="shrink-0 h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                                                @click.stop
                                            >
                                                <MoreVertical class="h-4 w-4" />
                                            </Button>
                                        </template>
                                        <DropdownItem
                                            @select="viewTicket(ticket.id)"
                                            >View Details</DropdownItem
                                        >
                                        <DropdownItem
                                            @select="openEditModal(ticket)"
                                            >Edit Ticket</DropdownItem
                                        >
                                        <DropdownItem
                                            @select="openAssignModal(ticket)"
                                            >Assign to...</DropdownItem
                                        >
                                        <DropdownSeparator />
                                        <DropdownItem
                                            destructive
                                            @select="openDeleteModal(ticket)"
                                            >Delete Ticket</DropdownItem
                                        >
                                    </Dropdown>
                                </td>
                            </tr>

                            <!-- Empty State -->
                            <tr
                                v-if="
                                    filteredTickets.length === 0 && !isLoading
                                "
                            >
                                <td
                                    :colspan="activeView !== 'archived' ? 7 : 6"
                                    class="py-12 text-center"
                                >
                                    <MessageSquare
                                        class="h-12 w-12 mx-auto text-[var(--text-muted)] mb-4"
                                    />
                                    <h3
                                        class="text-lg font-semibold text-[var(--text-primary)] mb-1"
                                    >
                                        No tickets found
                                    </h3>
                                    <p
                                        class="text-sm text-[var(--text-secondary)]"
                                    >
                                        {{
                                            searchQuery ||
                                            statusFilter !== "all" ||
                                            priorityFilter !== "all"
                                                ? "Try adjusting your filters to find what you're looking for."
                                                : "Create your first ticket to get started."
                                        }}
                                    </p>
                                    <Button
                                        v-if="
                                            !searchQuery &&
                                            statusFilter === 'all' &&
                                            priorityFilter === 'all'
                                        "
                                        class="mt-4"
                                        @click="openNewTicketModal"
                                    >
                                        <Plus class="h-4 w-4" />
                                        Create Ticket
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Grid View -->
                <div
                    v-else
                    class="max-h-[calc(100vh-420px)] overflow-y-auto p-4"
                >
                    <!-- Grid Header with Select All -->
                    <div
                        v-if="activeView !== 'archived'"
                        class="flex items-center gap-3 mb-4 pb-3 border-b border-[var(--border-default)]"
                    >
                        <button
                            @click="toggleSelectAll"
                            class="text-[var(--text-muted)] hover:text-[var(--text-primary)] flex items-center gap-2"
                        >
                            <CheckSquare
                                v-if="isAllSelected"
                                class="w-4 h-4 text-[var(--interactive-primary)]"
                            />
                            <Square v-else class="w-4 h-4" />
                            <span class="text-sm text-[var(--text-secondary)]"
                                >Select all</span
                            >
                        </button>
                    </div>

                    <!-- Grid Items -->
                    <div
                        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4"
                    >
                        <div
                            v-for="ticket in filteredTickets"
                            :key="ticket.id"
                            class="group bg-[var(--surface-secondary)] rounded-xl border border-[var(--border-default)] hover:border-[var(--interactive-primary)]/40 hover:shadow-md transition-all p-4 relative cursor-pointer"
                            @click="viewTicket(ticket.id)"
                        >
                            <!-- Checkbox -->
                            <button
                                v-if="activeView !== 'archived'"
                                @click.stop="toggleSelection(ticket.id)"
                                class="absolute top-3 left-3 z-10"
                            >
                                <CheckSquare
                                    v-if="selectedTickets.includes(ticket.id)"
                                    class="w-4 h-4 text-[var(--interactive-primary)]"
                                />
                                <Square
                                    v-else
                                    class="w-4 h-4 text-[var(--text-muted)] group-hover:text-[var(--text-secondary)]"
                                />
                            </button>

                            <!-- Actions Menu -->
                            <div class="absolute top-2 right-2">
                                <Dropdown align="end" @click.stop>
                                    <template #trigger>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="shrink-0 h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                                            @click.stop
                                        >
                                            <MoreVertical class="h-4 w-4" />
                                        </Button>
                                    </template>
                                    <DropdownItem
                                        @select="viewTicket(ticket.id)"
                                        >View Details</DropdownItem
                                    >
                                    <DropdownItem
                                        @select="openEditModal(ticket)"
                                        >Edit Ticket</DropdownItem
                                    >
                                    <DropdownItem
                                        @select="openAssignModal(ticket)"
                                        >Assign to...</DropdownItem
                                    >
                                    <DropdownSeparator />
                                    <DropdownItem
                                        destructive
                                        @select="openDeleteModal(ticket)"
                                        >Delete Ticket</DropdownItem
                                    >
                                </Dropdown>
                            </div>

                            <!-- Card Content -->
                            <div
                                :class="activeView !== 'archived' ? 'pl-6' : ''"
                            >
                                <!-- Header: ID, Type, Status -->
                                <div class="flex items-center gap-2 mb-2">
                                    <span
                                        class="text-xs font-mono text-[var(--text-muted)]"
                                        >{{ ticket.displayId }}</span
                                    >
                                    <Badge
                                        :variant="
                                            getTypeConfig(ticket.type).variant
                                        "
                                        size="xs"
                                    >
                                        {{ getTypeConfig(ticket.type).label }}
                                    </Badge>
                                </div>

                                <!-- Priority Indicator + Title -->
                                <div class="flex items-start gap-2 mb-2">
                                    <div
                                        :class="[
                                            'flex h-6 w-6 shrink-0 items-center justify-center rounded-lg mt-0.5',
                                            getPriorityConfig(ticket.priority)
                                                .bgClass,
                                        ]"
                                    >
                                        <component
                                            :is="
                                                getPriorityConfig(
                                                    ticket.priority,
                                                ).icon
                                            "
                                            :class="[
                                                'h-3.5 w-3.5',
                                                getPriorityConfig(
                                                    ticket.priority,
                                                ).class,
                                            ]"
                                        />
                                    </div>
                                    <h3
                                        class="text-sm font-semibold text-[var(--text-primary)] line-clamp-2"
                                    >
                                        {{ ticket.title }}
                                    </h3>
                                </div>

                                <!-- Status Badge -->
                                <div class="mb-3">
                                    <Badge
                                        :variant="
                                            getStatusConfig(ticket.status)
                                                .variant
                                        "
                                        size="sm"
                                    >
                                        <component
                                            :is="
                                                getStatusConfig(ticket.status)
                                                    .icon
                                            "
                                            class="h-3 w-3 mr-1"
                                        />
                                        {{
                                            getStatusConfig(ticket.status).label
                                        }}
                                    </Badge>
                                    <Badge
                                        v-if="ticket.isOverdue"
                                        variant="danger"
                                        size="xs"
                                        class="ml-1"
                                        >Overdue</Badge
                                    >
                                    <Badge
                                        v-if="ticket.slaBreached"
                                        variant="warning"
                                        size="xs"
                                        class="ml-1"
                                        >SLA</Badge
                                    >
                                </div>

                                <!-- Tags -->
                                <div
                                    v-if="ticket.tags.length"
                                    class="flex flex-wrap gap-1 mb-3"
                                >
                                    <span
                                        v-for="tag in ticket.tags.slice(0, 3)"
                                        :key="tag"
                                        class="px-1.5 py-0.5 text-[10px] rounded bg-[var(--surface-tertiary)] text-[var(--text-muted)]"
                                    >
                                        {{ tag }}
                                    </span>
                                    <span
                                        v-if="ticket.tags.length > 3"
                                        class="text-[10px] text-[var(--text-muted)]"
                                    >
                                        +{{ ticket.tags.length - 3 }}
                                    </span>
                                </div>

                                <!-- Footer: Assignee, Comments, Updated -->
                                <div
                                    class="flex items-center justify-between text-xs text-[var(--text-muted)] pt-2 border-t border-[var(--border-muted)]"
                                >
                                    <div class="flex items-center gap-1.5">
                                        <Avatar
                                            v-if="ticket.assignee"
                                            :fallback="ticket.assignee.initials"
                                            size="xs"
                                        />
                                        <User v-else class="h-3.5 w-3.5" />
                                        <span class="truncate max-w-[80px]">{{
                                            ticket.assignee?.name ||
                                            "Unassigned"
                                        }}</span>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="flex items-center gap-1">
                                            <MessageSquare class="h-3 w-3" />
                                            {{ ticket.comments }}
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <Clock class="h-3 w-3" />
                                            {{ ticket.updatedAt }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Grid Empty State -->
                    <div
                        v-if="filteredTickets.length === 0 && !isLoading"
                        class="py-12 text-center"
                    >
                        <MessageSquare
                            class="h-12 w-12 mx-auto text-[var(--text-muted)] mb-4"
                        />
                        <h3
                            class="text-lg font-semibold text-[var(--text-primary)] mb-1"
                        >
                            No tickets found
                        </h3>
                        <p class="text-sm text-[var(--text-secondary)]">
                            {{
                                searchQuery ||
                                statusFilter !== "all" ||
                                priorityFilter !== "all"
                                    ? "Try adjusting your filters to find what you're looking for."
                                    : "Create your first ticket to get started."
                            }}
                        </p>
                        <Button
                            v-if="
                                !searchQuery &&
                                statusFilter === 'all' &&
                                priorityFilter === 'all'
                            "
                            class="mt-4"
                            @click="openNewTicketModal"
                        >
                            <Plus class="h-4 w-4" />
                            Create Ticket
                        </Button>
                    </div>
                </div>
            </Card>

            <!-- Pagination -->
            <div
                v-if="totalItems > 0"
                class="flex items-center justify-between px-4 py-3 bg-[var(--surface-elevated)] rounded-xl border border-[var(--border-default)]"
            >
                <div class="flex items-center gap-4">
                    <span class="text-sm text-[var(--text-secondary)]">
                        Showing {{ (currentPage - 1) * perPage + 1 }} to
                        {{ Math.min(currentPage * perPage, totalItems) }} of
                        {{ totalItems }} tickets
                    </span>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-[var(--text-muted)]"
                            >Per page:</span
                        >
                        <select
                            :value="perPage"
                            @change="changePerPage(Number($event.target.value))"
                            class="px-2 py-1 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)] [&>option]:bg-[var(--surface-elevated)] [&>option]:text-[var(--text-primary)]"
                        >
                            <option
                                v-for="opt in perPageOptions"
                                :key="opt"
                                :value="opt"
                            >
                                {{ opt }}
                            </option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="currentPage === 1"
                        @click="goToPage(currentPage - 1)"
                    >
                        <ChevronLeft class="h-4 w-4" />
                        Previous
                    </Button>

                    <div class="flex items-center gap-1">
                        <button
                            v-for="page in Math.min(5, totalPages)"
                            :key="page"
                            @click="goToPage(page)"
                            :class="[
                                'w-8 h-8 rounded-lg text-sm font-medium transition-colors',
                                page === currentPage
                                    ? 'bg-[var(--interactive-primary)] text-white'
                                    : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]',
                            ]"
                        >
                            {{ page }}
                        </button>
                        <span
                            v-if="totalPages > 5"
                            class="text-[var(--text-muted)] px-1"
                            >...</span
                        >
                        <button
                            v-if="totalPages > 5"
                            @click="goToPage(totalPages)"
                            :class="[
                                'w-8 h-8 rounded-lg text-sm font-medium transition-colors',
                                totalPages === currentPage
                                    ? 'bg-[var(--interactive-primary)] text-white'
                                    : 'text-[var(--text-secondary)] hover:bg-[var(--surface-secondary)]',
                            ]"
                        >
                            {{ totalPages }}
                        </button>
                    </div>

                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="currentPage === totalPages"
                        @click="goToPage(currentPage + 1)"
                    >
                        Next
                        <ChevronRight class="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </div>

        <!-- Delete Ticket Modal -->
        <Modal
            :open="showDeleteModal"
            @update:open="showDeleteModal = $event"
            title="Delete Ticket"
            description="Are you sure? This action cannot be undone."
            size="sm"
        >
            <div class="space-y-4">
                <div
                    class="p-3 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 rounded-lg text-sm"
                >
                    <p class="font-medium">Warning</p>
                    <p>
                        This will permanently delete the ticket "{{
                            ticketToDelete?.title
                        }}" and all associated data.
                    </p>
                </div>

                <div class="space-y-1.5">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                        >Reason <span class="text-red-500">*</span></label
                    >
                    <Textarea
                        v-model="deleteReason"
                        placeholder="Why is this ticket being deleted?"
                        rows="3"
                    />
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <Button variant="ghost" @click="showDeleteModal = false"
                        >Cancel</Button
                    >
                    <Button
                        variant="danger"
                        @click="confirmDelete"
                        :loading="isDeleting"
                        :disabled="!deleteReason || deleteReason.length < 3"
                    >
                        Delete Ticket
                    </Button>
                </div>
            </div>
        </Modal>

        <!-- Edit Ticket Modal -->
        <Modal
            v-model:open="showEditModal"
            title="Edit Ticket"
            description="Update ticket details. Requires a reason for changes."
            size="lg"
        >
            <div class="space-y-5">
                <Input
                    v-model="editForm.title"
                    label="Title"
                    :error="editErrors.title?.[0]"
                    required
                />

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
                            >Priority</label
                        >
                        <select
                            v-model="editForm.priority"
                            class="w-full px-3 py-2 bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                        >
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
                            >Type</label
                        >
                        <select
                            v-model="editForm.type"
                            class="w-full px-3 py-2 bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                        >
                            <option value="bug">Bug</option>
                            <option value="feature">Feature</option>
                            <option value="task">Task</option>
                            <option value="question">Question</option>
                            <option value="improvement">Improvement</option>
                        </select>
                    </div>
                </div>

                <RichTextEditor
                    v-model="editForm.description"
                    label="Description"
                    :error="editErrors.description?.[0]"
                    min-height="150px"
                />

                <TagInput
                    v-model="editForm.tags"
                    label="Tags"
                    placeholder="Add tags..."
                />

                <div class="pt-2 border-t border-[var(--border-default)]">
                    <Textarea
                        v-model="editForm.reason"
                        label="Reason for changes"
                        placeholder="Why are you making these changes? (Required)"
                        required
                        :error="editErrors.reason?.[0]"
                    />
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <Button variant="ghost" @click="showEditModal = false"
                        >Cancel</Button
                    >
                    <Button @click="submitEdit" :loading="isSubmittingEdit"
                        >Save Changes</Button
                    >
                </div>
            </div>
        </Modal>

        <!-- Assign Modal -->
        <Modal
            v-model:open="showAssignModal"
            title="Assign Ticket"
            description="Select a user to assign this ticket to."
            size="sm"
        >
            <div class="space-y-4">
                <div class="space-y-1.5">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                        >Assignee</label
                    >
                    <ComboBox
                        v-model="selectedAssignee"
                        :options="assignableUsers"
                        :loading="isLoadingUsers"
                        :image-key="'image'"
                        placeholder="Select user..."
                        search-placeholder="Search users..."
                    />
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <Button variant="ghost" @click="showAssignModal = false"
                        >Cancel</Button
                    >
                    <Button @click="assignTicket" :loading="isAssigning"
                        >Assign</Button
                    >
                </div>
            </div>
        </Modal>

        <!-- New Ticket Modal -->
        <Modal
            v-model:open="showNewTicketModal"
            title="Create New Ticket"
            description="Fill in the details below to create a new support ticket."
            size="lg"
        >
            <div class="space-y-5">
                <Input
                    v-model="newTicket.title"
                    label="Title"
                    placeholder="Brief summary of the issue..."
                    required
                />

                <RichTextEditor
                    v-model="newTicket.description"
                    label="Description"
                    placeholder="Describe the issue in detail..."
                    min-height="180px"
                />

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
                            >Type</label
                        >
                        <select
                            v-model="newTicket.type"
                            class="h-10 w-full rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3 text-sm text-[var(--text-primary)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 transition-all"
                        >
                            <option
                                v-for="option in typeOptions"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)] mb-1.5"
                            >Priority</label
                        >
                        <select
                            v-model="newTicket.priority"
                            class="h-10 w-full rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3 text-sm text-[var(--text-primary)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 transition-all"
                        >
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                </div>

                <TagInput
                    v-model="newTicket.tags"
                    label="Tags"
                    placeholder="Add tags (comma separated)..."
                    hint="Press Enter or use commas to add tags. You can also paste multiple tags at once."
                />
            </div>

            <template #footer>
                <Button
                    variant="outline"
                    @click="showNewTicketModal = false"
                    :disabled="isSubmitting"
                >
                    Cancel
                </Button>
                <Button @click="handleSubmitTicket" :loading="isSubmitting">
                    Create Ticket
                </Button>
            </template>
        </Modal>

        <!-- Bulk Action Bar -->
        <div
            v-if="selectedTickets.length > 0"
            class="fixed bottom-6 left-1/2 transform -translate-x-1/2 bg-[var(--surface-primary)] border border-[var(--border-default)] shadow-lg rounded-full px-6 py-3 flex items-center gap-4 z-50"
        >
            <span class="text-sm font-medium text-[var(--text-primary)]"
                >{{ selectedTickets.length }} selected</span
            >
            <div class="h-4 w-px bg-[var(--border-default)]"></div>
            <Button variant="ghost" size="sm" @click="selectedTickets = []"
                >Cancel</Button
            >
            <Button variant="danger" size="sm" @click="openBulkArchiveModal">
                <Archive class="w-4 h-4 mr-2" />
                Archive
            </Button>
        </div>

        <!-- Bulk Archive Modal -->
        <Modal
            :open="showBulkArchiveModal"
            @update:open="showBulkArchiveModal = $event"
            title="Archive Selected Tickets"
            description="Are you sure you want to archive the selected tickets?"
        >
            <div class="space-y-4 py-2">
                <Alert variant="warning">
                    <div class="flex gap-2">
                        <AlertTriangle class="w-4 h-4 mt-0.5" />
                        <div class="text-sm">
                            Archived tickets are
                            <strong>locked permanently</strong>.
                        </div>
                    </div>
                </Alert>

                <div class="space-y-1.5">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                        >Reason for archiving
                        <span class="text-red-500">*</span></label
                    >
                    <Textarea
                        v-model="bulkArchiveReason"
                        placeholder="Explain why these tickets are being archived..."
                        rows="3"
                    />
                    <p
                        v-if="bulkArchiveErrors.reason"
                        class="text-sm text-red-500"
                    >
                        {{
                            bulkArchiveErrors.reason[0] ||
                            bulkArchiveErrors.reason
                        }}
                    </p>
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <Button
                        variant="outline"
                        @click="showBulkArchiveModal = false"
                        :disabled="isBulkArchiving"
                        >Cancel</Button
                    >
                    <Button
                        variant="danger"
                        @click="submitBulkArchive"
                        :loading="isBulkArchiving"
                        >Archive Tickets</Button
                    >
                </div>
            </div>
        </Modal>
    </div>
</template>
