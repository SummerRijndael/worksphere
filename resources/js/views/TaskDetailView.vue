<script setup lang="ts">
import { ref, computed, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
    Button,
    Avatar,
    Textarea,
    Badge,
    Card,
    Input,
    Dropdown,
    Modal,
} from "@/components/ui";
import {
    Calendar,
    Clock,
    
    MessageSquare,
    History,
    
    ChevronLeft,
    Circle,
    CheckCircle2,
    Play,
    Pause,
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
    
    Trash2,
    GripVertical,
    Paperclip,
    Folder,
    Building2,
    Lock,
    Target,
    FileText,
    ChevronDown,
    Copy,
} from "lucide-vue-next";
import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import { toast } from "vue-sonner";
import { useDate } from "@/composables/useDate";
const { formatDate, formatRelativeTime: timeAgo } = useDate();
import TaskWorkflowActions from "@/components/tasks/TaskWorkflowActions.vue";
import MediaManager from "@/components/tools/MediaManager.vue";
import TaskFormModal from "@/components/tasks/TaskFormModal.vue";
import QuickAssignModal from "@/components/tasks/QuickAssignModal.vue";

import draggable from "vuedraggable";
import DOMPurify from "dompurify";

const sanitize = (content: string) => {
    return DOMPurify.sanitize(content);
};

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const isArchiving = ref(false);
const isTaskDeleting = ref(false);

// Route params
const projectId = computed(
    () => (route.params.projectId as string) || (route.params.id as string),
);
const taskId = computed(() => route.params.taskId as string);

// State
const isLoading = ref(true);
const task = ref<any>(null);
const activeTab = ref<"checklist" | "comments" | "history" | "attachments">(
    "comments",
);

// Comments
const comments = ref<any[]>([]);
const statusHistory = ref<any[]>([]);
const activityLogs = ref<any[]>([]);
const newComment = ref("");
const isSubmittingComment = ref(false);

// Checklist
const checklistItems = ref<any[]>([]);
const newChecklistText = ref("");
const isAddingItem = ref(false);
const canSubmitForReview = ref(false);

// File Management (MediaManager)
const taskFiles = ref<any[]>([]);
const filesLoading = ref(false);
const isUploading = ref(false);
const uploadQueue = ref<any[]>([]);
const fileToDelete = ref<any>(null);
const showDeleteConfirmModal = ref(false);
const isDeleting = ref(false);
// Bulk delete
const filesToBulkDelete = ref<number[]>([]);
const showBulkDeleteModal = ref(false);
const isBulkDeleting = ref(false);

// Edit modal
const showEditModal = ref(false);
const showQuickAssignModal = ref(false);
const quickAssignType = ref<"operator" | "qa">("operator");

const onQuickAssign = (type: "operator" | "qa") => {
    quickAssignType.value = type;
    showQuickAssignModal.value = true;
};

// Current team
const currentTeamId = computed(() => {
    return (route.params.teamId as string) || authStore.currentTeam?.public_id;
});

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
    on_hold: {
        label: "On Hold",
        color: "text-amber-600 dark:text-amber-400",
        bg: "bg-amber-50 dark:bg-amber-500/10",
        border: "border-amber-200 dark:border-amber-900",
        icon: Pause,
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
    pm_review: {
        label: "PM Review",
        color: "text-indigo-600 dark:text-indigo-400",
        bg: "bg-indigo-50 dark:bg-indigo-500/10",
        border: "border-indigo-200 dark:border-indigo-900",
        icon: Eye,
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
    1: {
        label: "Low",
        color: "text-slate-600 dark:text-slate-400",
        bg: "bg-slate-100 dark:bg-slate-800",
        border: "border-slate-200 dark:border-slate-700",
    },
    2: {
        label: "Medium",
        color: "text-blue-600 dark:text-blue-400",
        bg: "bg-blue-50 dark:bg-blue-500/10",
        border: "border-blue-200 dark:border-blue-900",
    },
    3: {
        label: "High",
        color: "text-amber-600 dark:text-amber-400",
        bg: "bg-amber-50 dark:bg-amber-500/10",
        border: "border-amber-200 dark:border-amber-900",
    },
    4: {
        label: "Urgent",
        color: "text-red-600 dark:text-red-400",
        bg: "bg-red-50 dark:bg-red-500/10",
        border: "border-red-200 dark:border-red-900",
    },
};

// Full workflow for stepper visualization
const workflowStatuses = [
    "open",
    "in_progress",
    "submitted",
    "in_qa",
    "pm_review",
    "sent_to_client",
    "completed",
];

const getStatus = (s: string) => statusConfig[s] || statusConfig["open"];

// Treat 'approved' (legacy) as 'pm_review' for stepper/UI purposes
// Map 'on_hold' to its previous status (visual location)
const getStatusValue = (t: any) => {
    let val = t?.status?.value || t?.status || "open";
    if (val === "approved") return "pm_review";
    if (val === "on_hold") {
        const prev = t?.metadata?.previous_status;
        // Ensure previous status is a valid workflow step, else default to in_progress
        return workflowStatuses.includes(prev) ? prev : "in_progress";
    }
    return val;
};
// const getPriority = (p: number) => priorityConfig[p] || priorityConfig[2];

// Computed
// const isAssignee = computed(() => {
//     return (
//         task.value?.assignee?.public_id === authStore.user?.public_id ||
//         task.value?.assignee?.id === authStore.user?.id
//     );
// });

// Permission-based computed properties
const canEditMetadata = computed(() => task.value?.can?.edit_metadata);
const canManageChecklist = computed(() => {
    if (!task.value) return false;
    if (task.value.can?.is_read_only) return false;
    if (!task.value.can?.manage_checklist) return false;

    // Feature: Disable structure changes if task is started, unless Creator or Lead/SME
    // "Started" = not draft/open
    const status = getStatusValue(task.value);

    // Block modifications if completed (New Recquirement)
    if (["completed", "archived", "cancelled"].includes(status)) return false;

    const isStarted = !["draft", "open"].includes(status);

    if (isStarted) {
        // Check if Creator or has Edit Metadata permission (Lead/SME)
        const isCreator = task.value.creator?.id === authStore.user?.id;
        if (!isCreator && !task.value.can?.edit_metadata) {
            return false;
        }
    }

    return true;
});
const canCompleteItems = computed(() => {
    if (!task.value) return false;
    if (task.value.can?.is_read_only) return false;

    // Feature: Checklist actions disabled unless start task is pressed
    const status = getStatusValue(task.value);
    if (status !== "in_progress") return false;

    return task.value.can?.complete_items;
});
const canAssign = computed(() => task.value?.can?.assign);
const canAddComment = computed(() => task.value?.can?.add_comment);
const isReadOnly = computed(() => task.value?.can?.is_read_only);

const completedItemsCount = computed(() => {
    return checklistItems.value.filter((i: any) => i.status === "done").length;
});

const mergedActivity = computed(() => {
    // Activity logs are the primary source now as they are comprehensive
    const activityItems = activityLogs.value.map((a: any) => ({
        id: a.id,
        user: a.user || { name: a.user_name || "System" },
        description: a.description,
        description_body: a.description_body,
        created_at: a.created_at,
        action: a.action,
        icon: a.action_icon,
        category: a.category,
        changes: a.changes,
        type: "audit",
    }));

    // Status history items for backward compatibility
    const statusItems = statusHistory.value
        .map((s: any) => ({
            id: s.id,
            user: s.changed_by || { name: "System" },
            // Format to match audit log descriptions for consistency if possible
            description: `${s.changed_by?.name || "System"} changed status from ${s.from_status || "open"} to ${s.to_status}`,
            description_body: `Changed status from ${getStatus(s.from_status || "open").label} to ${getStatus(s.to_status).label}`,
            from_status: s.from_status,
            to_status: s.to_status,
            created_at: s.created_at,
            type: "status",
        }))
        .filter((s: any) => {
            // Deduplicate: if an audit log exists for status at the same time, skip this
            return !activityItems.find(
                (a: any) =>
                    a.action === "updated" &&
                    a.description.includes("status") &&
                    Math.abs(
                        new Date(a.created_at).getTime() -
                            new Date(s.created_at).getTime(),
                    ) < 2000,
            );
        });

    return [...activityItems, ...statusItems].sort(
        (a: any, b: any) =>
            new Date(b.created_at).getTime() - new Date(a.created_at).getTime(),
    );
});

const groupedActivity = computed(() => {
    const activities = mergedActivity.value;
    const groups: { date: string; items: any[] }[] = [];
    let lastDate = "";

    activities.forEach((item: any) => {
        const date = new Date(item.created_at);
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);

        let dateLabel = "";
        if (date.toDateString() === today.toDateString()) {
            dateLabel = "Today";
        } else if (date.toDateString() === yesterday.toDateString()) {
            dateLabel = "Yesterday";
        } else {
            dateLabel = date.toLocaleDateString("en-US", {
                month: "long",
                day: "numeric",
                year: "numeric",
            });
        }

        if (dateLabel !== lastDate) {
            groups.push({ date: dateLabel, items: [] });
            lastDate = dateLabel;
        }

        groups[groups.length - 1].items.push(item);
    });

    return groups;
});

// Task Actions Dropdown Items
const taskActionItems = computed(() => {
    const items = [];

    if (canEditMetadata.value) {
        items.push({
            label: "Edit Task",
            icon: Edit3,
            action: () => {
                showEditModal.value = true;
            },
        });
    }

    items.push(
        {
            label: "Archive Task",
            icon: FileText,
            action: internalArchiveTask,
            disabled: isArchiving.value,
        },
        {
            label: "Delete Task",
            icon: Trash2,
            variant: "danger",
            action: internalDeleteTask,
            disabled: isTaskDeleting.value,
        },
    );

    return items;
});

// Navigation
const goBack = () => {
    router.push(`/projects/${projectId.value}`);
};

// API calls
const fetchTask = async (silent = false) => {
    if (!currentTeamId.value || !projectId.value || !taskId.value) return;

    try {
        if (!silent) isLoading.value = true;
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}`,
        );
        task.value = response.data.data || response.data;
        await Promise.all([
            fetchComments(),
            fetchStatusHistory(),
            fetchActivityLogs(),
            fetchChecklistItems(),
            fetchTaskFiles(),
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
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/comments`,
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
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/status-history`,
        );
        // TaskController returns raw array, not wrapped in data
        statusHistory.value = Array.isArray(response.data)
            ? response.data
            : response.data.data || [];
    } catch (err) {
        console.error("Failed to fetch history", err);
    }
};

const fetchActivityLogs = async () => {
    if (!task.value) return;
    try {
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/activity`,
        );
        activityLogs.value = response.data.data || [];
    } catch (err) {
        console.error("Failed to fetch activity logs", err);
    }
};

const fetchChecklistItems = async () => {
    if (!task.value) return;
    try {
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/checklist`,
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
            { content: newComment.value },
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
            { text: newChecklistText.value },
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
    try {
        await axios.put(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/checklist/${item.public_id}`,
            { status: newStatus },
        );
        await fetchChecklistItems();
        await fetchActivityLogs(); // Refresh activity tab too
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to update item");
    }
};

const deleteChecklistItem = async (item: any) => {
    try {
        await axios.delete(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/checklist/${item.public_id}`,
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
            { items },
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
            { status },
        );
        task.value = response.data.data || response.data;
        toast.success(`Status updated to ${getStatus(status).label}`);
        await fetchStatusHistory();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to update status");
    }
};

// const submitForReview = async () => {
//     await updateStatus("in_qa");
// };

const showArchiveConfirmModal = ref(false);
const showTaskDeleteConfirmModal = ref(false);

const internalArchiveTask = () => {
    showArchiveConfirmModal.value = true;
};

const executeArchiveTask = async () => {
    if (!task.value) return;

    try {
        isArchiving.value = true;
        const response = await axios.post(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/archive`,
        );
        task.value = response.data.data || response.data.task;
        toast.success("Task archived");
        await fetchStatusHistory();
        showArchiveConfirmModal.value = false;
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to archive task");
    } finally {
        isArchiving.value = false;
    }
};

const internalDeleteTask = () => {
    showTaskDeleteConfirmModal.value = true;
};

const executeDeleteTask = async () => {
    if (!task.value) return;

    try {
        isTaskDeleting.value = true;
        await axios.delete(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}`,
        );
        toast.success("Task deleted");
        router.push(`/projects/${projectId.value}`);
        showTaskDeleteConfirmModal.value = false;
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to delete task");
    } finally {
        isTaskDeleting.value = false;
    }
};

// Helpers
// Local formatting functions removed in favor of useDate composable

const getItemStatusIcon = (status: string) => {
    if (status === "done") return CheckSquare;
    if (status === "in_progress") return Play;
    if (status === "on_hold") return Pause;
    return Square;
};

const getNextStatus = (status: string) => {
    if (status === "todo") return "in_progress";
    if (status === "in_progress") return "done";
    if (status === "done") return "todo"; // Reopen to Todo
    return "todo";
};

// File Management Handlers
const fetchTaskFiles = async () => {
    if (!task.value) return;
    filesLoading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/files`,
        );
        taskFiles.value = (response.data.data || response.data || []).map(
            (f: any) => ({
                ...f,
                name: f.name || f.file_name,
            }),
        );
    } catch (err) {
        console.error("Failed to fetch files", err);
    } finally {
        filesLoading.value = false;
    }
};

const handleUpload = (files: FileList | File[]) => {
    for (const file of Array.from(files)) {
        uploadQueue.value.push({
            id: Math.random().toString(36).substr(2, 9),
            file,
            name: file.name,
            size: file.size,
            progress: 0,
            status: "pending",
        });
    }
    // Don't auto-upload - user clicks "Start Upload" in MediaManager
};

const processUploadQueue = async () => {
    const pending = uploadQueue.value.filter((f) => f.status === "pending");
    if (!pending.length) return;

    isUploading.value = true;
    for (const item of pending) {
        item.status = "uploading";
        try {
            const formData = new FormData();
            formData.append("file", item.file);
            await axios.post(
                `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/files`,
                formData,
                { headers: { "Content-Type": "multipart/form-data" } },
            );
            item.status = "done";
            toast.success(`${item.name} uploaded`);
        } catch (e: any) {
            item.status = "error";
            toast.error(
                e.response?.data?.message || `Failed to upload ${item.name}`,
            );
        }
    }
    isUploading.value = false;
    uploadQueue.value = uploadQueue.value.filter((f) => f.status !== "done");
    await fetchTaskFiles();
};

const removeFileFromQueue = (index: number) => {
    uploadQueue.value.splice(index, 1);
};

const handleDeleteFile = (fileId: number | string) => {
    // MediaManager emits just the ID, so find the file object
    const file = taskFiles.value.find((f: any) => f.id === fileId);
    if (!file) {
        toast.error("File not found");
        return;
    }
    fileToDelete.value = file;
    showDeleteConfirmModal.value = true;
};

const confirmDeleteFile = async () => {
    if (!fileToDelete.value) return;
    isDeleting.value = true;
    try {
        await axios.delete(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/files/${fileToDelete.value.id}`,
        );
        toast.success("File deleted");
        await fetchTaskFiles();
    } catch (e: any) {
        toast.error(e.response?.data?.message || "Failed to delete file");
    } finally {
        isDeleting.value = false;
        showDeleteConfirmModal.value = false;
        fileToDelete.value = null;
    }
};

const handleDownload = (fileOrId: number | string | any) => {
    // MediaManager might emit just ID or the whole object
    let file = null;

    if (typeof fileOrId === "object" && fileOrId !== null) {
        file = fileOrId;
    } else {
        file = taskFiles.value.find((f: any) => f.id === fileOrId);
    }

    if (!file) {
        toast.error("File not found");
        return;
    }
    // Use download_url which forces download, fallback to url
    const downloadUrl = file.download_url || file.url;

    // Create hidden link to force download event if it's a direct link
    const link = document.createElement("a");
    link.href = downloadUrl;
    link.download = file.name || "download";
    link.target = "_blank";
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
};



const copyTaskId = () => {
    const idToCopy = task.value.readable_id || task.value.public_id;
    navigator.clipboard.writeText(idToCopy).then(() => {
        toast.success("Task ID copied to clipboard");
    });
};

const handleViewMedia = (file: any) => {
    window.open(file.url, "_blank");
};

const handleBulkDownload = async (ids: number[]) => {
    if (!ids.length) {
        toast.error("No files selected");
        return;
    }

    toast.info(`Preparing zip for ${ids.length} file(s)...`);

    try {
        // Call backend endpoint that returns a zip file
        const response = await axios.post(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/files/download`,
            { ids },
            { responseType: "blob" },
        );

        // Create download link from blob
        const blob = new Blob([response.data], { type: "application/zip" });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.href = url;
        link.download = `task-files-${taskId.value}.zip`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);

        toast.success("Download started");
    } catch (e: any) {
        toast.error(e.response?.data?.message || "Failed to download files");
    }
};

const onTaskUpdated = () => {
    fetchTask(true);
    showEditModal.value = false;
};

const handleBulkDelete = (ids: number[]) => {
    if (!ids.length) return;
    filesToBulkDelete.value = ids;
    showBulkDeleteModal.value = true;
};

const confirmBulkDelete = async () => {
    if (!filesToBulkDelete.value.length) return;
    isBulkDeleting.value = true;

    try {
        for (const id of filesToBulkDelete.value) {
            await axios.delete(
                `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId.value}/files/${id}`,
            );
        }
        toast.success(`Deleted ${filesToBulkDelete.value.length} file(s)`);
        await fetchTaskFiles();
    } catch (e: any) {
        toast.error(e.response?.data?.message || "Failed to delete files");
    } finally {
        isBulkDeleting.value = false;
        showBulkDeleteModal.value = false;
        filesToBulkDelete.value = [];
    }
};

// Watch for route changes
watch(
    [currentTeamId, projectId, taskId],
    () => {
        if (currentTeamId.value && projectId.value && taskId.value) {
            fetchTask();
        }
    },
    { immediate: true },
);

// Keep task checklist in sync with local checklistItems
watch(
    checklistItems,
    (newItems) => {
        if (task.value) {
            task.value.checklist = newItems;
        }
    },
    { deep: true },
);
</script>

<template>
    <div class="min-h-screen">
        <!-- Loading State -->
        <div v-if="isLoading" class="flex items-center justify-center py-20">
            <Loader2
                class="w-8 h-8 animate-spin text-[var(--interactive-primary)]"
            />
        </div>

        <!-- Task Content -->
        <Transition name="fade-slide" mode="out-in">
            <div
                v-if="task && !isLoading"
                class="w-full p-6"
                :key="task.public_id"
            >
                <!-- Breadcrumb & Back -->
                <div class="flex items-center gap-3 mb-6">
                    <Button variant="ghost" size="sm" @click="goBack">
                        <ChevronLeft class="w-4 h-4 mr-1" />
                        Back to {{ task.project?.name || "Project" }}
                    </Button>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                    <!-- Main Content Column -->
                    <div class="lg:col-span-3 space-y-6">
                        <!-- Status Stepper -->
                        <Card padding="lg">
                            <div class="flex items-center justify-between mb-6">
                                <div>
                                    <h1
                                        class="text-2xl font-bold text-[var(--text-primary)] mb-1"
                                    >
                                        {{ task.title }}
                                    </h1>
                                    <div
                                        class="flex items-center gap-3 text-sm text-[var(--text-secondary)]"
                                    >
                                        <span
                                            >Created by {{ task.creator?.name }}
                                            {{ timeAgo(task.created_at) }}</span
                                        >
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Dropdown
                                        align="end"
                                        :items="taskActionItems"
                                    >
                                        <template #trigger>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                class="flex items-center gap-2"
                                            >
                                                Actions
                                                <ChevronDown class="w-4 h-4" />
                                            </Button>
                                        </template>
                                    </Dropdown>
                                </div>
                            </div>

                            <!-- Progress Flow (Reka Style Stepper) -->
                            <div class="mb-6 pt-4">
                                <div class="relative px-8">
                                    <!-- Connectors -->
                                    <div
                                        class="absolute top-4 left-[7.14%] right-[7.14%] h-[2px] bg-[var(--border-default)]"
                                    ></div>
                                    <div
                                        class="absolute top-4 left-[7.14%] h-[2px] bg-[var(--interactive-primary)] transition-all duration-500"
                                        :style="{
                                            width: `${(workflowStatuses.indexOf(getStatusValue(task)) / (workflowStatuses.length - 1)) * 85.72}%`,
                                        }"
                                    ></div>

                                    <!-- Steps -->
                                    <div
                                        class="relative grid grid-cols-7 gap-0"
                                    >
                                        <div
                                            v-for="(
                                                step, index
                                            ) in workflowStatuses"
                                            :key="step"
                                            class="flex flex-col items-center"
                                        >
                                            <!-- Indicator -->
                                            <div
                                                class="flex items-center justify-center w-8 h-8 rounded-full border-2 transition-all bg-[var(--surface-primary)]"
                                                :class="[
                                                    workflowStatuses.indexOf(
                                                        getStatusValue(task),
                                                    ) > index
                                                        ? 'bg-[var(--interactive-primary)] border-[var(--interactive-primary)] text-white'
                                                        : workflowStatuses.indexOf(
                                                                getStatusValue(
                                                                    task,
                                                                ),
                                                            ) === index
                                                          ? task.status ===
                                                                'on_hold' ||
                                                            task.status
                                                                ?.value ===
                                                                'on_hold'
                                                              ? 'border-amber-500 text-amber-600 bg-amber-50'
                                                              : 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                                                          : 'border-[var(--border-default)] text-[var(--text-muted)]',
                                                ]"
                                            >
                                                <CheckCircle2
                                                    v-if="
                                                        workflowStatuses.indexOf(
                                                            getStatusValue(
                                                                task,
                                                            ),
                                                        ) > index
                                                    "
                                                    class="w-5 h-5"
                                                />
                                                <Circle
                                                    v-else-if="
                                                        workflowStatuses.indexOf(
                                                            getStatusValue(
                                                                task,
                                                            ),
                                                        ) === index
                                                    "
                                                    class="w-4 h-4 fill-current"
                                                />
                                                <span
                                                    v-else
                                                    class="text-xs font-medium"
                                                >
                                                    <component
                                                        :is="
                                                            statusConfig[step]
                                                                .icon
                                                        "
                                                        v-if="
                                                            step === 'on_hold'
                                                        "
                                                        class="w-4 h-4"
                                                    />
                                                    <template v-else>{{
                                                        index + 1
                                                    }}</template>
                                                </span>
                                            </div>

                                            <!-- Label -->
                                            <span
                                                class="mt-3 text-xs font-medium uppercase tracking-wide text-center transition-colors"
                                                :class="[
                                                    workflowStatuses.indexOf(
                                                        getStatusValue(task),
                                                    ) >= index
                                                        ? 'text-[var(--text-primary)]'
                                                        : 'text-[var(--text-muted)]',
                                                ]"
                                            >
                                                {{
                                                    getStatus(step)
                                                        .label.replace(
                                                            "Client Review",
                                                            "Client",
                                                        )
                                                        .replace(
                                                            "In QA Review",
                                                            "QA",
                                                        )
                                                }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions Bar -->
                            <div
                                class="bg-[var(--surface-secondary)] rounded-lg p-4 border border-[var(--border-default)] flex flex-wrap items-center justify-between gap-4"
                            >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="text-sm font-medium text-[var(--text-secondary)]"
                                        >Current Status:</span
                                    >
                                    <Badge
                                        :variant="
                                            getStatus(
                                                getStatusValue(task),
                                            ).color.includes('slate')
                                                ? 'secondary'
                                                : 'default'
                                        "
                                    >
                                        {{
                                            getStatus(getStatusValue(task))
                                                .label
                                        }}
                                    </Badge>
                                </div>
                                <TaskWorkflowActions
                                    :task="task"
                                    @task-updated="onTaskUpdated"
                                    @error="toast.error($event)"
                                />
                            </div>
                        </Card>

                        <!-- Description -->
                        <Card padding="lg">
                            <div class="flex items-center justify-between mb-4">
                                <h3
                                    class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)]"
                                >
                                    Description
                                </h3>
                            </div>
                            <div
                                class="bg-[var(--surface-secondary)]/30 rounded-xl p-5 min-h-[120px] transition-all hover:bg-[var(--surface-secondary)]/50"
                            >
                                <div
                                    v-if="task.description"
                                    class="prose prose-sm dark:prose-invert max-w-none text-[var(--text-primary)] leading-relaxed whitespace-pre-wrap"
                                >
                                    {{ task.description }}
                                </div>
                                <p
                                    v-else
                                    class="text-[var(--text-muted)] italic text-sm"
                                >
                                    No description provided
                                </p>
                            </div>
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
                                    :disabled="!canManageChecklist"
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
                                                v-if="canManageChecklist"
                                                class="drag-handle cursor-grab active:cursor-grabbing text-[var(--text-muted)] hover:text-[var(--text-secondary)] opacity-0 group-hover:opacity-100 transition-opacity p-1"
                                            >
                                                <GripVertical class="w-4 h-4" />
                                            </div>

                                            <!-- Status Toggle Button -->
                                            <button
                                                @click="
                                                    updateChecklistItemStatus(
                                                        item,
                                                        getNextStatus(
                                                            item.status,
                                                        ),
                                                    )
                                                "
                                                :disabled="!canCompleteItems || item.status === 'on_hold'"
                                                class="shrink-0"
                                                :class="
                                                    canCompleteItems && item.status !== 'on_hold'
                                                        ? 'cursor-pointer hover:scale-110 transition-transform'
                                                        : 'cursor-not-allowed opacity-50'
                                                "
                                                :title="
                                                    item.status === 'on_hold'
                                                        ? 'Resume the item before marking as done'
                                                        : canCompleteItems
                                                        ? 'Click to change status'
                                                        : 'You do not have permission to change status or the task is locked'
                                                "
                                            >
                                                <component
                                                    :is="
                                                        getItemStatusIcon(
                                                            item.status,
                                                        )
                                                    "
                                                    class="w-5 h-5"
                                                    :class="{
                                                        'text-emerald-500':
                                                            item.status ===
                                                            'done',
                                                        'text-blue-500':
                                                            item.status ===
                                                            'in_progress',
                                                        'text-orange-500':
                                                            item.status ===
                                                            'on_hold',
                                                        'text-(--text-muted)':
                                                            item.status ===
                                                            'todo',
                                                    }"
                                                />
                                            </button>

                                            <!-- Separate Hold/Resume Button -->
                                            <button
                                                v-if="item.status === 'in_progress' || item.status === 'on_hold'"
                                                @click="
                                                    updateChecklistItemStatus(
                                                        item,
                                                        item.status === 'in_progress' ? 'on_hold' : 'in_progress'
                                                    )
                                                "
                                                :disabled="!canCompleteItems"
                                                class="shrink-0 p-1 rounded-md hover:bg-(--surface-tertiary) transition-colors"
                                                :title="item.status === 'in_progress' ? 'Put on Hold' : 'Resume Work'"
                                            >
                                                <component
                                                    :is="item.status === 'in_progress' ? Pause : Play"
                                                    class="w-4 h-4"
                                                    :class="item.status === 'in_progress' ? 'text-orange-500' : 'text-blue-500'"
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
                                                        : item.status === 'on_hold'
                                                        ? 'warning'
                                                        : 'secondary'
                                                "
                                                size="sm"
                                                class="shrink-0"
                                            >
                                                {{
                                                    item.status === "done"
                                                        ? "Done"
                                                        : item.status === "on_hold"
                                                        ? "On Hold"
                                                        : "In Progress"
                                                }}
                                            </Badge>

                                            <!-- Delete Button -->
                                            <button
                                                v-if="canManageChecklist"
                                                @click="
                                                    deleteChecklistItem(item)
                                                "
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
                            <div v-if="canManageChecklist" class="flex gap-2">
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

                            <!-- Read Only Warning -->
                            <div
                                v-else-if="isReadOnly"
                                class="bg-[var(--surface-secondary)] rounded-lg p-3 border border-[var(--border-default)] flex items-center gap-3"
                            >
                                <Lock
                                    class="w-4 h-4 text-[var(--text-muted)]"
                                />
                                <span
                                    class="text-xs text-[var(--text-muted)] italic"
                                    >Checklist is locked while task is in
                                    review.</span
                                >
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
                                <button
                                    @click="activeTab = 'attachments'"
                                    class="flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-all"
                                    :class="
                                        activeTab === 'attachments'
                                            ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                            : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                    "
                                >
                                    <Paperclip class="w-4 h-4" />
                                    Files
                                    <span
                                        v-if="taskFiles.length"
                                        class="bg-[var(--surface-tertiary)] text-xs px-1.5 py-0.5 rounded-full"
                                    >
                                        {{ taskFiles.length }}
                                    </span>
                                </button>
                            </div>

                            <!-- Attachments Tab -->
                            <div
                                v-if="activeTab === 'attachments'"
                                class="h-[400px]"
                            >
                                <MediaManager
                                    :items="taskFiles"
                                    :total="taskFiles.length"
                                    :current-page="1"
                                    :per-page="20"
                                    :loading="filesLoading"
                                    :can-upload="task.can?.manage_media"
                                    :can-delete="task.can?.manage_media"
                                    :uploading="isUploading"
                                    :upload-queue="uploadQueue"
                                    @upload="handleUpload"
                                    @delete="handleDeleteFile"
                                    @download="handleDownload"
                                    @view="handleViewMedia"
                                    @remove-upload="removeFileFromQueue"
                                    @process-queue="processUploadQueue"
                                    @bulk-delete="handleBulkDelete"
                                    @bulk-download="handleBulkDownload"
                                />
                            </div>

                            <!-- Comments Tab -->
                            <div
                                v-if="activeTab === 'comments'"
                                class="space-y-4"
                            >
                                <div v-if="canAddComment" class="flex gap-3">
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
                                            :disabled="isReadOnly"
                                        />
                                        <div class="flex justify-end">
                                            <Button
                                                size="sm"
                                                :loading="isSubmittingComment"
                                                :disabled="
                                                    !newComment.trim() ||
                                                    isReadOnly
                                                "
                                                @click="submitComment"
                                            >
                                                <Send
                                                    class="w-3.5 h-3.5 mr-1.5"
                                                />
                                                Send
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                                <div
                                    v-else
                                    class="bg-[var(--surface-secondary)]/50 rounded-lg p-4 text-center border border-dashed border-[var(--border-subtle)]"
                                >
                                    <p class="text-xs text-[var(--text-muted)]">
                                        You do not have permission to add
                                        comments.
                                    </p>
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
                                                    >{{
                                                        comment.user?.name
                                                    }}</span
                                                >
                                                <span
                                                    class="text-xs text-[var(--text-muted)]"
                                                    >{{
                                                        timeAgo(
                                                            comment.created_at,
                                                        )
                                                    }}</span
                                                >
                                            </div>
                                            <div
                                                class="text-sm text-[var(--text-secondary)] prose prose-sm dark:prose-invert max-w-none [&>p]:mb-2 [&>p:last-child]:mb-0"
                                                v-html="
                                                    sanitize(comment.content)
                                                "
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
                            <div v-if="activeTab === 'history'" class="px-2">
                                <div
                                    v-for="group in groupedActivity"
                                    :key="group.date"
                                    class="mb-8 last:mb-0"
                                >
                                    <div
                                        class="sticky top-0 z-10 bg-[var(--surface-primary)] py-2 mb-4 border-b border-[var(--border-subtle)] flex items-center gap-3"
                                    >
                                        <Clock
                                            class="w-3.5 h-3.5 text-[var(--text-muted)] ml-1"
                                        />
                                        <span
                                            class="text-xs font-semibold text-[var(--text-secondary)] uppercase tracking-wider"
                                            >{{ group.date }}</span
                                        >
                                    </div>

                                    <div
                                        class="space-y-4 ml-3 border-l border-[var(--border-default)] pl-6 pb-2"
                                    >
                                        <div
                                            v-for="entry in group.items"
                                            :key="entry.id"
                                            class="relative"
                                        >
                                            <!-- Avatar on line -->
                                            <div
                                                class="absolute -left-[36px] top-0.5 z-10 w-6 h-6 flex items-center justify-center rounded-full bg-[var(--surface-primary)]"
                                            >
                                                <Avatar
                                                    :name="entry.user?.name"
                                                    :src="
                                                        entry.user?.avatar_url
                                                    "
                                                    size="xs"
                                                    class="w-6 h-6 border-2 border-[var(--surface-primary)]"
                                                />
                                            </div>

                                            <div class="flex flex-col gap-1">
                                                <div
                                                    class="text-sm text-[var(--text-secondary)] leading-relaxed"
                                                >
                                                    <template
                                                        v-if="
                                                            entry.type ===
                                                            'status'
                                                        "
                                                    >
                                                        <span
                                                            class="font-semibold text-[var(--text-primary)]"
                                                            >{{
                                                                entry.user
                                                                    ?.name ||
                                                                "System"
                                                            }}</span
                                                        >
                                                        Changed status from
                                                        <Badge
                                                            :class="[
                                                                getStatus(
                                                                    entry.from_status ||
                                                                        'open',
                                                                ).bg,
                                                                getStatus(
                                                                    entry.from_status ||
                                                                        'open',
                                                                ).color,
                                                                'mx-1 align-middle',
                                                            ]"
                                                        >
                                                            {{
                                                                getStatus(
                                                                    entry.from_status ||
                                                                        "open",
                                                                ).label
                                                            }}
                                                        </Badge>
                                                        to
                                                        <Badge
                                                            :class="[
                                                                getStatus(
                                                                    entry.to_status,
                                                                ).bg,
                                                                getStatus(
                                                                    entry.to_status,
                                                                ).color,
                                                                'ml-1 align-middle',
                                                            ]"
                                                        >
                                                            {{
                                                                getStatus(
                                                                    entry.to_status,
                                                                ).label
                                                            }}
                                                        </Badge>
                                                    </template>
                                                    <template v-else>
                                                        <span
                                                            class="font-semibold text-[var(--text-primary)]"
                                                            >{{
                                                                entry.user
                                                                    ?.name ||
                                                                "System"
                                                            }}</span
                                                        >
                                                        <span
                                                            class="ml-1 text-[var(--text-primary)]"
                                                            >{{
                                                                entry.description_body ||
                                                                entry.description.replace(
                                                                    entry.user
                                                                        ?.name ||
                                                                        "System",
                                                                    "",
                                                                )
                                                            }}</span
                                                        >
                                                    </template>
                                                </div>
                                                <p
                                                    class="text-xs text-[var(--text-muted)] flex items-center gap-1"
                                                >
                                                    {{
                                                        new Date(
                                                            entry.created_at,
                                                        ).toLocaleTimeString(
                                                            [],
                                                            {
                                                                hour: "2-digit",
                                                                minute: "2-digit",
                                                            },
                                                        )
                                                    }}
                                                    &middot;
                                                    {{
                                                        timeAgo(
                                                            entry.created_at,
                                                        )
                                                    }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div
                                    v-if="mergedActivity.length === 0"
                                    class="flex flex-col items-center justify-center py-12 text-center"
                                >
                                    <div
                                        class="w-12 h-12 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mb-3"
                                    >
                                        <History
                                            class="w-5 h-5 text-[var(--text-muted)]"
                                        />
                                    </div>
                                    <h4
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                    >
                                        No activity yet
                                    </h4>
                                    <p
                                        class="text-xs text-[var(--text-muted)] mt-1"
                                    >
                                        Activity logs will appear here when
                                        changes are made.
                                    </p>
                                </div>
                            </div>
                        </Card>
                    </div>

                    <!-- Sidebar Column -->
                    <div class="space-y-6">
                        <!-- Progress Card (if checklist exists) -->
                        <Card
                            v-if="checklistItems.length > 0"
                            padding="lg"
                            class="overflow-hidden relative group"
                        >
                            <div
                                class="absolute top-0 right-0 p-3 opacity-10 pointer-events-none group-hover:scale-110 transition-transform duration-500"
                            >
                                <Target class="w-16 h-16" />
                            </div>

                            <div class="flex items-center justify-between mb-6">
                                <h3
                                    class="font-bold text-xs uppercase tracking-widest text-[var(--text-secondary)]"
                                >
                                    Progress
                                </h3>
                                <Badge
                                    :variant="
                                        completedItemsCount ===
                                        checklistItems.length
                                            ? 'success'
                                            : 'secondary'
                                    "
                                    class="font-bold shadow-sm"
                                >
                                    {{
                                        completedItemsCount ===
                                        checklistItems.length
                                            ? "Complete"
                                            : Math.round(
                                                  (completedItemsCount /
                                                      checklistItems.length) *
                                                      100,
                                              ) + "%"
                                    }}
                                </Badge>
                            </div>

                            <div class="flex items-center gap-6">
                                <div
                                    class="relative w-20 h-20 flex items-center justify-center shrink-0"
                                >
                                    <!-- Background Glow -->
                                    <div
                                        class="absolute inset-0 rounded-full bg-[var(--interactive-primary)] opacity-5 blur-xl pointer-events-none"
                                    ></div>

                                    <svg
                                        class="w-full h-full transform -rotate-90 drop-shadow-md"
                                    >
                                        <circle
                                            cx="40"
                                            cy="40"
                                            r="36"
                                            stroke="currentColor"
                                            stroke-width="5"
                                            fill="transparent"
                                            class="text-[var(--surface-tertiary)]"
                                        />
                                        <circle
                                            cx="40"
                                            cy="40"
                                            r="36"
                                            stroke="currentColor"
                                            stroke-width="5"
                                            fill="transparent"
                                            class="text-[var(--interactive-primary)] transition-all duration-1000 ease-out"
                                            :stroke-dasharray="2 * Math.PI * 36"
                                            :stroke-dashoffset="
                                                2 *
                                                Math.PI *
                                                36 *
                                                (1 -
                                                    completedItemsCount /
                                                        checklistItems.length)
                                            "
                                            stroke-linecap="round"
                                        />
                                    </svg>
                                    <div
                                        class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none"
                                    >
                                        <span
                                            class="text-base font-black text-[var(--text-primary)] leading-tight"
                                            >{{
                                                Math.round(
                                                    (completedItemsCount /
                                                        checklistItems.length) *
                                                        100,
                                                )
                                            }}%</span
                                        >
                                    </div>
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div
                                        class="text-2xl font-black text-[var(--text-primary)] mb-0.5 tracking-tight"
                                    >
                                        {{ completedItemsCount }}
                                        <span
                                            class="text-[var(--text-muted)] font-medium text-lg ml-1"
                                            >/ {{ checklistItems.length }}</span
                                        >
                                    </div>
                                    <p
                                        class="text-[10px] text-[var(--text-muted)] font-bold uppercase tracking-wider"
                                    >
                                        Items completed
                                    </p>
                                </div>
                            </div>
                        </Card>

                        <!-- Details Card -->
                        <Card padding="lg">
                            <label
                                class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wider mb-4 block"
                                >Task Details</label
                            >

                            <dl class="space-y-5">
                                <!-- Task ID -->
                                <div>
                                    <dt
                                        class="text-xs font-medium text-[var(--text-secondary)] mb-1.5"
                                    >
                                        Task ID
                                    </dt>
                                    <dd
                                        class="flex items-center gap-2 text-sm text-[var(--text-primary)] font-mono"
                                    >
                                        <span class="bg-[var(--surface-tertiary)] px-1.5 py-0.5 rounded text-xs select-all">{{ task.readable_id || task.public_id?.substring(0, 8) }}</span>
                                        <button
                                            @click="copyTaskId"
                                            class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
                                            title="Copy ID"
                                        >
                                            <Copy class="w-3.5 h-3.5" />
                                        </button>
                                    </dd>
                                </div>
                                <!-- Context -->
                                <div>
                                    <dt
                                        class="text-xs font-medium text-[var(--text-secondary)] mb-1.5"
                                    >
                                        Context
                                    </dt>
                                    <dd class="space-y-2">
                                        <div
                                            v-if="task.project?.client"
                                            class="flex items-center gap-2"
                                        >
                                            <Building2
                                                class="w-4 h-4 text-[var(--text-muted)]"
                                            />
                                            <span
                                                class="text-sm font-medium text-[var(--text-primary)]"
                                                >{{
                                                    task.project.client.name
                                                }}</span
                                            >
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <Folder
                                                class="w-4 h-4 text-[var(--text-muted)]"
                                            />
                                            <span
                                                class="text-sm font-medium text-[var(--text-primary)]"
                                                >{{
                                                    task.project?.name ||
                                                    "Project"
                                                }}</span
                                            >
                                        </div>
                                    </dd>
                                </div>
                                <!-- Assignee -->
                                <div>
                                    <dt
                                        class="text-xs font-medium text-[var(--text-secondary)] mb-1.5"
                                    >
                                        Operator
                                    </dt>
                                    <dd
                                        class="flex items-center gap-2 p-1 -ml-1 rounded transition-all"
                                        :class="
                                            canAssign
                                                ? 'cursor-pointer hover:bg-[var(--surface-secondary)] hover:opacity-80'
                                                : 'cursor-default'
                                        "
                                        @click="
                                            canAssign &&
                                            onQuickAssign('operator')
                                        "
                                        :title="
                                            canAssign ? 'Click to assign' : ''
                                        "
                                    >
                                        <Avatar
                                            v-if="task.assignee"
                                            :name="task.assignee.name"
                                            :src="task.assignee.avatar_url"
                                            size="sm"
                                        />
                                        <div
                                            v-else
                                            class="w-8 h-8 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center border border-[var(--border-subtle)]"
                                        >
                                            <UserPlus
                                                class="w-3.5 h-3.5 text-[var(--text-muted)]"
                                            />
                                        </div>
                                        <span
                                            class="text-sm font-medium"
                                            :class="
                                                task.assignee
                                                    ? 'text-[var(--text-primary)]'
                                                    : 'text-[var(--text-muted)] italic'
                                            "
                                        >
                                            {{
                                                task.assignee?.name ||
                                                "Unassigned"
                                            }}
                                        </span>
                                    </dd>
                                </div>

                                <!-- QA -->
                                <div>
                                    <dt
                                        class="text-xs font-medium text-[var(--text-secondary)] mb-1.5"
                                    >
                                        QA
                                    </dt>
                                    <dd
                                        class="flex items-center gap-2 p-1 -ml-1 rounded transition-all"
                                        :class="
                                            canAssign
                                                ? 'cursor-pointer hover:bg-[var(--surface-secondary)] hover:opacity-80'
                                                : 'cursor-default'
                                        "
                                        @click="
                                            canAssign && onQuickAssign('qa')
                                        "
                                        :title="
                                            canAssign
                                                ? 'Click to assign QA owner'
                                                : ''
                                        "
                                    >
                                        <Avatar
                                            v-if="task.qa_user"
                                            :name="task.qa_user.name"
                                            :src="task.qa_user.avatar_url"
                                            size="sm"
                                        />
                                        <div
                                            v-else
                                            class="w-8 h-8 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center border border-[var(--border-subtle)]"
                                        >
                                            <UserPlus
                                                class="w-3.5 h-3.5 text-[var(--text-muted)]"
                                            />
                                        </div>
                                        <span
                                            class="text-sm font-medium"
                                            :class="
                                                task.qa_user
                                                    ? 'text-[var(--text-primary)]'
                                                    : 'text-[var(--text-muted)] italic'
                                            "
                                        >
                                            {{
                                                task.qa_user?.name ||
                                                "Unassigned"
                                            }}
                                        </span>
                                    </dd>
                                </div>

                                <!-- Due Date -->
                                <div>
                                    <dt
                                        class="text-xs font-medium text-[var(--text-secondary)] mb-1.5"
                                    >
                                        Due Date
                                    </dt>
                                    <dd class="flex items-center gap-2 text-sm">
                                        <Calendar
                                            class="w-4 h-4 text-[var(--text-muted)]"
                                        />
                                        <span
                                            :class="{
                                                'text-[var(--color-error)] font-medium':
                                                    task.is_overdue,
                                                'text-[var(--text-primary)]':
                                                    !task.is_overdue,
                                            }"
                                        >
                                            {{
                                                task.due_date
                                                    ? formatDate(task.due_date)
                                                    : "Not set"
                                            }}
                                        </span>
                                    </dd>
                                </div>

                                <!-- Reporter -->
                                <div>
                                    <dt
                                        class="text-xs font-medium text-[var(--text-secondary)] mb-1.5"
                                    >
                                        Created By
                                    </dt>
                                    <dd class="flex items-center gap-2">
                                        <Avatar
                                            v-if="task.creator"
                                            :name="task.creator.name"
                                            :src="task.creator.avatar_url"
                                            size="sm"
                                        />
                                        <span
                                            class="text-sm text-[var(--text-primary)]"
                                        >
                                            {{
                                                task.creator?.name || "Unknown"
                                            }}
                                        </span>
                                    </dd>
                                </div>

                                <!-- Estimated Hours -->
                                <div v-if="task.estimated_hours">
                                    <dt
                                        class="text-xs font-medium text-[var(--text-secondary)] mb-1.5"
                                    >
                                        Time Estimate
                                    </dt>
                                    <dd
                                        class="flex items-center gap-2 text-sm text-[var(--text-primary)]"
                                    >
                                        <Clock
                                            class="w-4 h-4 text-[var(--text-muted)]"
                                        />
                                        {{ task.estimated_hours }} hours
                                    </dd>
                                </div>

                                <!-- Actual Hours -->
                                <div v-if="task.actual_hours">
                                    <dt
                                        class="text-xs font-medium text-[var(--text-secondary)] mb-1.5"
                                    >
                                        Actual Hours
                                    </dt>
                                    <dd
                                        class="flex items-center gap-2 text-sm text-[var(--text-primary)]"
                                    >
                                        <Clock
                                            class="w-4 h-4 text-[var(--text-muted)]"
                                        />
                                        {{ task.actual_hours }} hours
                                    </dd>
                                </div>

                                <!-- Internal Meta -->
                                <div
                                    class="pt-4 mt-4 border-t border-[var(--border-default)]"
                                >
                                    <div
                                        class="text-xs text-[var(--text-muted)] space-y-1"
                                    >
                                        <p v-if="task.created_at">
                                            Created
                                            {{ timeAgo(task.created_at) }}
                                        </p>
                                        <p v-if="task.updated_at">
                                            Updated
                                            {{ timeAgo(task.updated_at) }}
                                        </p>
                                    </div>
                                </div>
                            </dl>
                        </Card>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Not Found -->
        <div
            v-if="!task && !isLoading"
            class="flex flex-col items-center justify-center py-20"
        >
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
            @task-saved="onTaskUpdated"
        />

        <QuickAssignModal
            v-if="showQuickAssignModal && task"
            :open="showQuickAssignModal"
            :task="task"
            :assign-type="quickAssignType"
            @update:open="showQuickAssignModal = $event"
            @assigned="onTaskUpdated"
        />

        <!-- Delete File Confirmation Modal -->
        <Modal
            :open="showDeleteConfirmModal"
            @update:open="showDeleteConfirmModal = $event"
            title="Delete File"
            :description="`Are you sure you want to delete ${fileToDelete?.name || fileToDelete?.file_name}? This action cannot be undone.`"
            size="sm"
        >
            <template #footer>
                <Button
                    variant="ghost"
                    @click="showDeleteConfirmModal = false"
                    :disabled="isDeleting"
                >
                    Cancel
                </Button>
                <Button
                    variant="danger"
                    @click="confirmDeleteFile"
                    :loading="isDeleting"
                >
                    Delete
                </Button>
            </template>
        </Modal>

        <!-- Bulk Delete Confirmation Modal -->
        <Modal
            :open="showBulkDeleteModal"
            @update:open="showBulkDeleteModal = $event"
            title="Delete Files"
            :description="`Are you sure you want to delete ${filesToBulkDelete.length} file(s)? This action cannot be undone.`"
            size="sm"
        >
            <template #footer>
                <Button
                    variant="ghost"
                    @click="showBulkDeleteModal = false"
                    :disabled="isBulkDeleting"
                >
                    Cancel
                </Button>
                <Button
                    variant="danger"
                    @click="confirmBulkDelete"
                    :loading="isBulkDeleting"
                >
                    Delete {{ filesToBulkDelete.length }} File(s)
                </Button>
            </template>
        </Modal>
        <!-- Archive Confirmation Modal -->
        <Modal
            :open="showArchiveConfirmModal"
            @update:open="showArchiveConfirmModal = $event"
            title="Archive Task"
            description="Are you sure you want to archive this task? It will be moved to the archived list."
            size="sm"
        >
            <template #footer>
                <Button
                    variant="ghost"
                    @click="showArchiveConfirmModal = false"
                    :disabled="isArchiving"
                >
                    Cancel
                </Button>
                <Button
                    variant="primary"
                    @click="executeArchiveTask"
                    :loading="isArchiving"
                >
                    Archive Task
                </Button>
            </template>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal
            :open="showTaskDeleteConfirmModal"
            @update:open="showTaskDeleteConfirmModal = $event"
            title="Delete Task"
            description="Are you sure you want to delete this task? This action cannot be undone."
            size="sm"
        >
            <template #footer>
                <Button
                    variant="ghost"
                    @click="showTaskDeleteConfirmModal = false"
                    :disabled="isTaskDeleting"
                >
                    Cancel
                </Button>
                <Button
                    variant="danger"
                    @click="executeDeleteTask"
                    :loading="isTaskDeleting"
                >
                    Delete Task
                </Button>
            </template>
        </Modal>
    </div>
</template>

<style scoped>
.fade-slide-enter-active,
.fade-slide-leave-active {
    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.fade-slide-enter-from {
    opacity: 0;
    transform: translateY(10px);
}

.fade-slide-leave-to {
    opacity: 0;
    transform: translateY(-10px);
}

/* Fix for Safari/Firefox list transitions */
.list-move,
.list-enter-active,
.list-leave-active {
    transition: all 0.5s ease;
}

.list-enter-from,
.list-leave-to {
    opacity: 0;
    transform: translateX(30px);
}

.list-leave-active {
    position: absolute;
}
</style>
