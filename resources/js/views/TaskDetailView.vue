<script setup lang="ts">
import { ref, computed, watch, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
    Button,
    Avatar,
    Textarea,
    Badge,
    Card,
    Input,
    Dropdown,
} from "@/components/ui";
import {
    Calendar,
    Clock,
    User,
    MessageSquare,
    History,
    MoreHorizontal,
    ChevronLeft,
    Circle,
    CheckCircle2,
    Play,
    Send,
    Eye,
    Archive,
    UserPlus,
    Edit3,
    ListChecks,
    Plus,
    Square,
    CheckSquare,
    Loader2,
    X,
    Trash2,
    GripVertical,
} from "lucide-vue-next";
import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import { toast } from "vue-sonner";
import TaskFormModal from "@/components/tasks/TaskFormModal.vue";
import draggable from "vuedraggable";
import DOMPurify from "dompurify";

const sanitize = (content: string) => {
    return DOMPurify.sanitize(content);
};

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

// Route params
const projectId = computed(
    () => (route.params.projectId as string) || (route.params.id as string)
);
const taskId = computed(() => route.params.taskId as string);

// State
const isLoading = ref(true);
const task = ref<any>(null);
const activeTab = ref<"checklist" | "comments" | "history">("comments");

// Comments
const comments = ref<any[]>([]);
const statusHistory = ref<any[]>([]);
const newComment = ref("");
const isSubmittingComment = ref(false);

// Checklist
const checklistItems = ref<any[]>([]);
const newChecklistText = ref("");
const isAddingItem = ref(false);
const canSubmitForReview = ref(false);

// Edit modal
const showEditModal = ref(false);

// Current team
const currentTeamId = computed(() => authStore.currentTeam?.public_id);

// Status & Priority configs - synced with backend TaskStatus enum
const statusConfig: Record<
    string,
    { label: string; color: string; bg: string; icon: any; border: string }
> = {
    draft: {
        label: "Draft",
        color: "text-slate-500 dark:text-slate-400",
        bg: "bg-slate-100 dark:bg-slate-800",
        border: "border-slate-200 dark:border-slate-700",
        icon: Circle,
    },
    open: {
        label: "Open",
        color: "text-slate-600 dark:text-slate-400",
        bg: "bg-slate-100 dark:bg-slate-800",
        border: "border-slate-200 dark:border-slate-700",
        icon: Circle,
    },
    in_progress: {
        label: "In Progress",
        color: "text-blue-600 dark:text-blue-400",
        bg: "bg-blue-50 dark:bg-blue-500/10",
        border: "border-blue-200 dark:border-blue-900",
        icon: Play,
    },
    submitted: {
        label: "Submitted",
        color: "text-amber-600 dark:text-amber-400",
        bg: "bg-amber-50 dark:bg-amber-500/10",
        border: "border-amber-200 dark:border-amber-900",
        icon: Send,
    },
    in_qa: {
        label: "In QA Review",
        color: "text-orange-600 dark:text-orange-400",
        bg: "bg-orange-50 dark:bg-orange-500/10",
        border: "border-orange-200 dark:border-orange-900",
        icon: Eye,
    },
    approved: {
        label: "Approved",
        color: "text-emerald-600 dark:text-emerald-400",
        bg: "bg-emerald-50 dark:bg-emerald-500/10",
        border: "border-emerald-200 dark:border-emerald-900",
        icon: CheckCircle2,
    },
    rejected: {
        label: "Rejected",
        color: "text-red-600 dark:text-red-400",
        bg: "bg-red-50 dark:bg-red-500/10",
        border: "border-red-200 dark:border-red-900",
        icon: Circle,
    },
    sent_to_client: {
        label: "Sent to Client",
        color: "text-purple-600 dark:text-purple-400",
        bg: "bg-purple-50 dark:bg-purple-500/10",
        border: "border-purple-200 dark:border-purple-900",
        icon: Send,
    },
    client_approved: {
        label: "Client Approved",
        color: "text-teal-600 dark:text-teal-400",
        bg: "bg-teal-50 dark:bg-teal-500/10",
        border: "border-teal-200 dark:border-teal-900",
        icon: CheckCircle2,
    },
    client_rejected: {
        label: "Client Rejected",
        color: "text-rose-600 dark:text-rose-400",
        bg: "bg-rose-50 dark:bg-rose-500/10",
        border: "border-rose-200 dark:border-rose-900",
        icon: Circle,
    },
    completed: {
        label: "Completed",
        color: "text-emerald-600 dark:text-emerald-400",
        bg: "bg-emerald-50 dark:bg-emerald-500/10",
        border: "border-emerald-200 dark:border-emerald-900",
        icon: CheckCircle2,
    },
    archived: {
        label: "Archived",
        color: "text-slate-500 dark:text-slate-500",
        bg: "bg-slate-100 dark:bg-slate-900",
        border: "border-slate-200 dark:border-slate-800",
        icon: Archive,
    },
};

const priorityConfig: Record<
    number,
    { label: string; color: string; bg: string; border: string }
> = {
    1: { label: "Low", color: "text-slate-600 dark:text-slate-400", bg: "bg-slate-100 dark:bg-slate-800", border: "border-slate-200 dark:border-slate-700" },
    2: { label: "Medium", color: "text-blue-600 dark:text-blue-400", bg: "bg-blue-50 dark:bg-blue-500/10", border: "border-blue-200 dark:border-blue-900" },
    3: { label: "High", color: "text-amber-600 dark:text-amber-400", bg: "bg-amber-50 dark:bg-amber-500/10", border: "border-amber-200 dark:border-amber-900" },
    4: { label: "Urgent", color: "text-red-600 dark:text-red-400", bg: "bg-red-50 dark:bg-red-500/10", border: "border-red-200 dark:border-red-900" },
};

// Simplified workflow for quick status changes (users should use specific actions for full workflow)
const workflowStatuses = ["open", "in_progress", "completed"];

const getStatus = (s: string) => statusConfig[s] || statusConfig["open"];
const getStatusValue = (t: any) => t?.status?.value || t?.status || "open";
const getPriority = (p: number) => priorityConfig[p] || priorityConfig[2];

// Computed
const isAssignee = computed(() => {
    return (
        task.value?.assignee?.public_id === authStore.user?.public_id ||
        task.value?.assignee?.id === authStore.user?.id
    );
});

const completedItemsCount = computed(() => {
    return checklistItems.value.filter((i: any) => i.status === "done").length;
});

// Navigation
const goBack = () => {
    router.push(`/projects/${projectId.value}`);
};

// API calls
const fetchTask = async () => {
    if (!currentTeamId.value || !projectId.value || !taskId.value) return;

    try {
        isLoading.value = true;
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}`
        );
        task.value = response.data.data || response.data;
        await Promise.all([
            fetchComments(),
            fetchStatusHistory(),
            fetchChecklistItems(),
        ]);
    } catch (err) {
        toast.error("Failed to load task");
        console.error(err);
    } finally {
        isLoading.value = false;
    }
};

const fetchComments = async () => {
    if (!task.value) return;
    try {
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/comments`
        );
        comments.value = response.data.data || [];
    } catch (err) {
        console.error("Failed to fetch comments", err);
    }
};

const fetchStatusHistory = async () => {
    if (!task.value) return;
    try {
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/status-history`
        );
        statusHistory.value = response.data.data || [];
    } catch (err) {
        console.error("Failed to fetch history", err);
    }
};

const fetchChecklistItems = async () => {
    if (!task.value) return;
    try {
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/checklist`
        );
        checklistItems.value = response.data.data || [];
        canSubmitForReview.value =
            response.data.meta?.can_submit_for_review || false;
    } catch (err) {
        console.error("Failed to fetch checklist", err);
    }
};

const submitComment = async () => {
    if (!newComment.value.trim() || !task.value) return;
    try {
        isSubmittingComment.value = true;
        await axios.post(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/comments`,
            { content: newComment.value }
        );
        newComment.value = "";
        await fetchComments();
        toast.success("Comment added");
    } catch (err) {
        toast.error("Failed to add comment");
    } finally {
        isSubmittingComment.value = false;
    }
};

const addChecklistItem = async () => {
    if (!newChecklistText.value.trim() || !task.value) return;
    try {
        isAddingItem.value = true;
        await axios.post(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/checklist`,
            { text: newChecklistText.value }
        );
        newChecklistText.value = "";
        await fetchChecklistItems();
        toast.success("Item added");
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to add item");
    } finally {
        isAddingItem.value = false;
    }
};

const updateChecklistItemStatus = async (item: any, newStatus: string) => {
    if (!isAssignee.value) {
        toast.error("Only the assignee can change item status");
        return;
    }
    try {
        await axios.put(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/checklist/${item.public_id}`,
            { status: newStatus }
        );
        await fetchChecklistItems();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to update item");
    }
};

const deleteChecklistItem = async (item: any) => {
    try {
        await axios.delete(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/checklist/${item.public_id}`
        );
        await fetchChecklistItems();
        toast.success("Item removed");
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to remove item");
    }
};

const onChecklistReorder = async () => {
    // Update positions based on new order
    const items = checklistItems.value.map((item: any, index: number) => ({
        public_id: item.public_id,
        position: index + 1,
    }));

    try {
        await axios.post(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/checklist/reorder`,
            { items }
        );
    } catch (err: any) {
        toast.error("Failed to reorder items");
        await fetchChecklistItems();
    }
};

const updateStatus = async (status: string) => {
    if (!task.value || getStatusValue(task.value) === status) return;
    try {
        const response = await axios.put(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}`,
            { status }
        );
        task.value = response.data.data || response.data;
        toast.success(`Status updated to ${getStatus(status).label}`);
        await fetchStatusHistory();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to update status");
    }
};

const submitForReview = async () => {
    await updateStatus("in_qa");
};

// Helpers
const formatDate = (date?: string) => {
    if (!date) return "";
    return new Date(date).toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
};

const timeAgo = (date?: string) => {
    if (!date) return "";
    const now = new Date();
    const past = new Date(date);
    const diffMs = now.getTime() - past.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);
    if (diffMins < 1) return "just now";
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return formatDate(date);
};

const getItemStatusIcon = (status: string) => {
    if (status === "done") return CheckSquare;
    if (status === "in_progress") return Play;
    return Square;
};

const getNextStatus = (status: string) => {
    if (status === "todo") return "in_progress";
    if (status === "in_progress") return "done";
    return "todo";
};

const onTaskUpdated = () => {
    fetchTask();
    showEditModal.value = false;
};

// Watch for route changes
watch(
    [currentTeamId, projectId, taskId],
    () => {
        if (currentTeamId.value && projectId.value && taskId.value) {
            fetchTask();
        }
    },
    { immediate: true }
);
</script>

<template>
    <div class="min-h-screen bg-[var(--surface-secondary)]">
        <!-- Loading State -->
        <div v-if="isLoading" class="flex items-center justify-center py-20">
            <Loader2
                class="w-8 h-8 animate-spin text-[var(--interactive-primary)]"
            />
        </div>

        <!-- Task Content -->
        <div v-else-if="task" class="max-w-7xl mx-auto p-6">
            <!-- Breadcrumb & Back -->
            <div class="flex items-center gap-3 mb-6">
                <Button variant="ghost" size="sm" @click="goBack">
                    <ChevronLeft class="w-4 h-4 mr-1" />
                    Back to Project
                </Button>
                <span class="text-[var(--text-muted)]">/</span>
                <span class="text-sm text-[var(--text-muted)] font-mono">
                    {{ task.public_id?.substring(0, 8) }}
                </span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Main Content Column -->
                <div class="lg:col-span-3 space-y-6">
                    <!-- Header Card -->
                    <Card padding="lg">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <span
                                    :class="[
                                        getStatus(getStatusValue(task)).bg,
                                        getStatus(getStatusValue(task)).color,
                                        getStatus(getStatusValue(task)).border,
                                        'px-3 py-1 rounded-full text-sm font-medium border flex items-center gap-2',
                                    ]"
                                >
                                    <component :is="getStatus(getStatusValue(task)).icon" class="w-4 h-4" />
                                    {{ getStatus(getStatusValue(task)).label }}
                                </span>
                                <span
                                    :class="[
                                        getPriority(task.priority).bg,
                                        getPriority(task.priority).color,
                                        getPriority(task.priority).border,
                                        'px-3 py-1 rounded-full text-sm font-medium border',
                                    ]"
                                >
                                    {{ getPriority(task.priority).label }}
                                </span>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="showEditModal = true"
                            >
                                <Edit3 class="w-4 h-4 mr-2" />
                                Edit
                            </Button>
                        </div>

                        <h1
                            class="text-2xl font-bold text-[var(--text-primary)] mb-3"
                        >
                            {{ task.title }}
                        </h1>

                        <p
                            v-if="task.description"
                            class="text-[var(--text-secondary)] leading-relaxed whitespace-pre-wrap"
                        >
                            {{ task.description }}
                        </p>
                        <p v-else class="text-[var(--text-muted)] italic">
                            No description provided
                        </p>
                    </Card>

                    <!-- Checklist Section -->
                    <Card padding="lg">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <ListChecks
                                    class="w-5 h-5 text-[var(--text-muted)]"
                                />
                                <h2
                                    class="text-lg font-semibold text-[var(--text-primary)]"
                                >
                                    Checklist
                                </h2>
                                <Badge
                                    v-if="checklistItems.length > 0"
                                    variant="secondary"
                                    size="sm"
                                >
                                    {{ completedItemsCount }}/{{
                                        checklistItems.length
                                    }}
                                </Badge>
                            </div>
                            <Button
                                v-if="canSubmitForReview && isAssignee"
                                size="sm"
                                @click="submitForReview"
                            >
                                <Send class="w-4 h-4 mr-2" />
                                Submit for Review
                            </Button>
                        </div>

                        <!-- Progress Bar -->
                        <div v-if="checklistItems.length > 0" class="mb-4">
                            <div
                                class="h-2 w-full bg-[var(--surface-secondary)] rounded-full overflow-hidden"
                            >
                                <div
                                    class="h-full bg-[var(--color-primary-600)] rounded-full transition-all duration-500"
                                    :style="{
                                        width: `${
                                            (completedItemsCount /
                                                checklistItems.length) *
                                            100
                                        }%`,
                                    }"
                                ></div>
                            </div>
                        </div>

                        <!-- Checklist Items -->
                        <div class="mb-4">
                            <draggable
                                v-model="checklistItems"
                                item-key="public_id"
                                handle=".drag-handle"
                                @change="onChecklistReorder"
                                class="space-y-2"
                                :animation="200"
                                ghost-class="opacity-50"
                            >
                                <template #item="{ element: item }">
                                    <div
                                        class="flex items-center gap-3 p-3 rounded-lg border border-[var(--border-default)] hover:border-[var(--border-strong)] transition-colors group bg-[var(--surface-primary)]"
                                        :class="{
                                            'bg-[var(--surface-secondary)]/50':
                                                item.status === 'done',
                                        }"
                                    >
                                        <!-- Drag Handle -->
                                        <div
                                            class="drag-handle cursor-grab active:cursor-grabbing text-[var(--text-muted)] hover:text-[var(--text-secondary)] opacity-0 group-hover:opacity-100 transition-opacity p-1"
                                        >
                                            <GripVertical class="w-4 h-4" />
                                        </div>

                                        <!-- Status Toggle Button -->
                                        <button
                                            @click="
                                                updateChecklistItemStatus(
                                                    item,
                                                    getNextStatus(item.status)
                                                )
                                            "
                                            :disabled="!isAssignee"
                                            class="shrink-0"
                                            :class="
                                                isAssignee
                                                    ? 'cursor-pointer hover:scale-110 transition-transform'
                                                    : 'cursor-not-allowed opacity-50'
                                            "
                                            :title="
                                                isAssignee
                                                    ? 'Click to change status'
                                                    : 'Only assignee can change status'
                                            "
                                        >
                                            <component
                                                :is="
                                                    getItemStatusIcon(
                                                        item.status
                                                    )
                                                "
                                                class="w-5 h-5"
                                                :class="{
                                                    'text-emerald-500':
                                                        item.status === 'done',
                                                    'text-blue-500':
                                                        item.status ===
                                                        'in_progress',
                                                    'text-[var(--text-muted)]':
                                                        item.status === 'todo',
                                                }"
                                            />
                                        </button>

                                        <!-- Item Text -->
                                        <div
                                            class="flex-1 text-sm prose prose-sm dark:prose-invert max-w-none [&>p]:my-0"
                                            :class="
                                                item.status === 'done'
                                                    ? 'text-[var(--text-muted)] line-through opacity-70'
                                                    : 'text-[var(--text-primary)]'
                                            "
                                            v-html="sanitize(item.text)"
                                        ></div>

                                        <!-- Status Badge -->
                                        <Badge
                                            v-if="item.status !== 'todo'"
                                            :variant="
                                                item.status === 'done'
                                                    ? 'success'
                                                    : 'secondary'
                                            "
                                            size="sm"
                                            class="shrink-0"
                                        >
                                            {{
                                                item.status === "done"
                                                    ? "Done"
                                                    : "In Progress"
                                            }}
                                        </Badge>

                                        <!-- Delete Button -->
                                        <button
                                            @click="deleteChecklistItem(item)"
                                            class="opacity-0 group-hover:opacity-100 p-1 rounded hover:bg-[var(--surface-tertiary)] transition-all text-[var(--text-muted)] hover:text-red-500"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </div>
                                </template>
                            </draggable>

                            <p
                                v-if="checklistItems.length === 0"
                                class="text-center text-sm text-[var(--text-muted)] py-8"
                            >
                                No checklist items yet. Add some to track
                                progress!
                            </p>
                        </div>

                        <!-- Add Item Form -->
                        <div class="flex gap-2">
                            <Input
                                v-model="newChecklistText"
                                placeholder="Add a checklist item..."
                                class="flex-1"
                                @keydown.enter="addChecklistItem"
                            />
                            <Button
                                :loading="isAddingItem"
                                :disabled="!newChecklistText.trim()"
                                @click="addChecklistItem"
                            >
                                <Plus class="w-4 h-4 mr-1" />
                                Add
                            </Button>
                        </div>
                    </Card>

                    <!-- Tabs: Comments & History -->
                    <Card padding="lg">
                        <div
                            class="flex items-center gap-1 mb-4 bg-[var(--surface-secondary)] p-1 rounded-lg w-fit"
                        >
                            <button
                                @click="activeTab = 'comments'"
                                class="flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-all"
                                :class="
                                    activeTab === 'comments'
                                        ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                        : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                "
                            >
                                <MessageSquare class="w-4 h-4" />
                                Comments
                                <span
                                    v-if="comments.length"
                                    class="bg-[var(--surface-tertiary)] text-xs px-1.5 py-0.5 rounded-full"
                                >
                                    {{ comments.length }}
                                </span>
                            </button>
                            <button
                                @click="activeTab = 'history'"
                                class="flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-all"
                                :class="
                                    activeTab === 'history'
                                        ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                        : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                "
                            >
                                <History class="w-4 h-4" />
                                Activity
                            </button>
                        </div>

                        <!-- Comments Tab -->
                        <div v-if="activeTab === 'comments'" class="space-y-4">
                            <div class="flex gap-3">
                                <Avatar
                                    :name="authStore.user?.name"
                                    :src="authStore.avatarUrl"
                                    size="sm"
                                    class="shrink-0 mt-1"
                                />
                                <div class="flex-1 space-y-2">
                                    <Textarea
                                        v-model="newComment"
                                        placeholder="Add a comment..."
                                        class="min-h-[80px]"
                                    />
                                    <div class="flex justify-end">
                                        <Button
                                            size="sm"
                                            :loading="isSubmittingComment"
                                            :disabled="!newComment.trim()"
                                            @click="submitComment"
                                        >
                                            <Send class="w-3.5 h-3.5 mr-1.5" />
                                            Send
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-3 mt-4">
                                <div
                                    v-for="comment in comments"
                                    :key="comment.id"
                                    class="flex gap-3"
                                >
                                    <Avatar
                                        :name="comment.user?.name"
                                        :src="comment.user?.avatar_url"
                                        size="sm"
                                        class="shrink-0"
                                    />
                                    <div
                                        class="flex-1 bg-[var(--surface-secondary)] rounded-lg px-4 py-3 border border-[var(--border-subtle)]"
                                    >
                                        <div
                                            class="flex items-center justify-between gap-2 mb-2"
                                        >
                                            <span
                                                class="text-sm font-semibold text-[var(--text-primary)]"
                                                >{{ comment.user?.name }}</span
                                            >
                                            <span
                                                class="text-xs text-[var(--text-muted)]"
                                                >{{
                                                    timeAgo(comment.created_at)
                                                }}</span
                                            >
                                        </div>
                                        <div
                                            class="text-sm text-[var(--text-secondary)] prose prose-sm dark:prose-invert max-w-none [&>p]:mb-2 [&>p:last-child]:mb-0"
                                            v-html="sanitize(comment.content)"
                                        ></div>
                                    </div>
                                </div>
                                <p
                                    v-if="comments.length === 0"
                                    class="text-center text-sm text-[var(--text-muted)] py-8"
                                >
                                    No comments yet
                                </p>
                            </div>
                        </div>

                        <!-- History Tab -->
                        <div v-if="activeTab === 'history'" class="space-y-1">
                            <div
                                v-for="(entry, index) in statusHistory"
                                :key="entry.id"
                                class="flex gap-3 py-3"
                            >
                                <div class="flex flex-col items-center">
                                    <div
                                        class="w-2 h-2 rounded-full bg-[var(--interactive-primary)]"
                                    ></div>
                                    <div
                                        v-if="index < statusHistory.length - 1"
                                        class="w-0.5 flex-1 bg-[var(--border-default)] mt-1"
                                    ></div>
                                </div>
                                <div class="flex-1 pb-2">
                                    <p
                                        class="text-sm text-[var(--text-secondary)]"
                                    >
                                        <span
                                            class="font-medium text-[var(--text-primary)]"
                                            >{{
                                                entry.user?.name || "System"
                                            }}</span
                                        >
                                        changed status from
                                        <span
                                            :class="[
                                                getStatus(
                                                    entry.from_status || 'open'
                                                ).bg,
                                                getStatus(
                                                    entry.from_status || 'open'
                                                ).color,
                                                'px-1.5 py-0.5 rounded text-xs font-medium mx-1',
                                            ]"
                                        >
                                            {{
                                                getStatus(
                                                    entry.from_status || "open"
                                                ).label
                                            }}
                                        </span>
                                        to
                                        <span
                                            :class="[
                                                getStatus(entry.to_status).bg,
                                                getStatus(entry.to_status)
                                                    .color,
                                                'px-1.5 py-0.5 rounded text-xs font-medium ml-1',
                                            ]"
                                        >
                                            {{
                                                getStatus(entry.to_status).label
                                            }}
                                        </span>
                                    </p>
                                    <p
                                        class="text-xs text-[var(--text-muted)] mt-1"
                                    >
                                        {{ timeAgo(entry.created_at) }}
                                    </p>
                                </div>
                            </div>
                            <p
                                v-if="statusHistory.length === 0"
                                class="text-center text-sm text-[var(--text-muted)] py-8"
                            >
                                No activity recorded yet
                            </p>
                        </div>
                    </Card>
                </div>

                <!-- Sidebar Column -->
                <div class="space-y-6">
                    <!-- Status Card -->
                    <Card padding="lg">
                        <label
                            class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wider mb-3 block"
                            >Status</label
                        >
                        <div class="space-y-1.5">
                            <button
                                v-for="status in workflowStatuses"
                                :key="status"
                                @click="updateStatus(status)"
                                class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all text-left"
                                :class="
                                    getStatusValue(task) === status
                                        ? 'bg-[var(--interactive-primary)] text-white shadow-sm'
                                        : 'text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)]'
                                "
                            >
                                <component
                                    :is="statusConfig[status]?.icon || Circle"
                                    class="w-4 h-4"
                                    :class="
                                        getStatusValue(task) === status
                                            ? 'text-white'
                                            : 'text-[var(--text-muted)]'
                                    "
                                />
                                {{ statusConfig[status]?.label || status }}
                            </button>
                        </div>
                    </Card>

                    <!-- Details Card -->
                    <Card padding="lg">
                        <label
                            class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wider mb-3 block"
                            >Details</label
                        >

                        <div class="space-y-4">
                            <!-- Assignee -->
                            <div>
                                <label
                                    class="text-xs text-[var(--text-muted)] block mb-1"
                                    >Assignee</label
                                >
                                <div class="flex items-center gap-2">
                                    <Avatar
                                        v-if="task.assignee"
                                        :name="task.assignee.name"
                                        :src="task.assignee.avatar_url"
                                        size="sm"
                                    />
                                    <div
                                        v-else
                                        class="w-8 h-8 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center"
                                    >
                                        <UserPlus
                                            class="w-3.5 h-3.5 text-[var(--text-muted)]"
                                        />
                                    </div>
                                    <span
                                        class="text-sm text-[var(--text-primary)]"
                                    >
                                        {{
                                            task.assignee?.name || "Unassigned"
                                        }}
                                    </span>
                                </div>
                            </div>

                            <!-- Due Date -->
                            <div>
                                <label
                                    class="text-xs text-[var(--text-muted)] block mb-1"
                                    >Due Date</label
                                >
                                <div
                                    class="flex items-center gap-2 text-sm text-[var(--text-primary)]"
                                >
                                    <Calendar
                                        class="w-4 h-4 text-[var(--text-muted)]"
                                    />
                                    {{
                                        task.due_date
                                            ? formatDate(task.due_date)
                                            : "Not set"
                                    }}
                                </div>
                            </div>

                            <!-- Reporter -->
                            <div>
                                <label
                                    class="text-xs text-[var(--text-muted)] block mb-1"
                                    >Reporter</label
                                >
                                <div class="flex items-center gap-2">
                                    <Avatar
                                        v-if="task.creator"
                                        :name="task.creator.name"
                                        :src="task.creator.avatar_url"
                                        size="sm"
                                    />
                                    <span
                                        class="text-sm text-[var(--text-primary)]"
                                    >
                                        {{ task.creator?.name || "Unknown" }}
                                    </span>
                                </div>
                            </div>

                            <!-- Estimated Hours -->
                            <div>
                                <label
                                    class="text-xs text-[var(--text-muted)] block mb-1"
                                    >Time Estimate</label
                                >
                                <div
                                    class="flex items-center gap-2 text-sm text-[var(--text-primary)]"
                                >
                                    <Clock
                                        class="w-4 h-4 text-[var(--text-muted)]"
                                    />
                                    {{
                                        task.estimated_hours
                                            ? `${task.estimated_hours} hours`
                                            : "Not estimated"
                                    }}
                                </div>
                            </div>
                        </div>
                    </Card>

                    <!-- Meta Card -->
                    <Card padding="lg">
                        <div
                            class="text-xs text-[var(--text-muted)] space-y-1.5"
                        >
                            <p v-if="task.created_at">
                                Created {{ timeAgo(task.created_at) }}
                            </p>
                            <p v-if="task.updated_at">
                                Updated {{ timeAgo(task.updated_at) }}
                            </p>
                        </div>
                    </Card>
                </div>
            </div>
        </div>

        <!-- Not Found -->
        <div v-else class="flex flex-col items-center justify-center py-20">
            <p class="text-[var(--text-muted)]">Task not found</p>
            <Button variant="outline" class="mt-4" @click="goBack">
                <ChevronLeft class="w-4 h-4 mr-2" />
                Go Back
            </Button>
        </div>

        <!-- Edit Modal -->
        <TaskFormModal
            v-if="showEditModal && task"
            :open="showEditModal"
            :team-id="currentTeamId"
            :project-id="projectId"
            :task="task"
            @update:open="showEditModal = $event"
            @saved="onTaskUpdated"
        />
    </div>
</template>
