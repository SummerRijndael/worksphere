<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useToast } from "@/composables/useToast.ts";
import { sanitizeHtml } from "@/utils/sanitize";
import api from "@/lib/api";
import { debounce } from "lodash";
import {
    Card,
    Button,
    Badge,
    Avatar,
    Input,
    RichTextEditor,
    TagInput,
    Dropdown,
    DropdownItem,
    DropdownSeparator,
    Switch,
    Alert,
    Modal,
    Textarea,
    ComboBox,
} from "@/components/ui";
import {
    ArrowLeft,
    CheckCircle2,
    Clock,
    AlertCircle,
    MessageSquare,
    Paperclip,
    Edit3,
    Trash2,
    Send,
    MoreVertical,
    ArrowUpRight,
    ArrowDownRight,
    Minus,
    Plus,
    UserPlus,
    X,
    Lock,
    Loader2,
    Star,
    StarOff,
    AlertTriangle,
    Play,
    Copy,
    Link as LinkIcon,
    Unlink as UnlinkIcon,
    Archive as ArchiveIcon,
    // Activity timeline icons
    PlusCircle,
    Edit,
    UserMinus,
    Upload,
    FileX,
    RefreshCw,
    Key,
    Shield,
    Settings,
    Eye,
    LogOut,
    LogIn,
    MailCheck,
} from "lucide-vue-next";
import MediaManager from "@/components/tools/MediaManager.vue";
import MediaViewer from "@/components/tools/MediaViewer.vue";
import { useAuthStore } from "@/stores/auth";

// Types
interface UserLite {
    id: number;
    name: string;
    initials: string;
    avatarUrl?: string | null;
    avatar_url?: string | null; // For followers
}

interface TicketChild {
    id: number;
    public_id: string;
    title: string;
    status: { label: string; color: string; value: string };
}

interface TicketParent {
    id: string;
    title: string;
    status: { label: string; color: string; value: string };
}

interface Attachment {
    id: number;
    name: string;
    url: string;
    thumb_url?: string;
    mime_type: string;
    size: number;
}

interface Comment {
    id: string;
    author: UserLite;
    content: string;
    createdAt: string;
    isInternal: boolean;
    attachments: Attachment[];
}

interface Activity {
    id: number;
    user: { name: string } | null;
    description: string;
    created_at: string;
    changes?: { new?: Record<string, any>; old?: Record<string, any> };
    metadata?: Record<string, any>;
}

interface TicketDetail {
    id: number;
    displayId: string;
    title: string;
    description: string;
    status: string;
    statusLabel: string;
    priority: string;
    priorityLabel: string;
    type: string;
    typeLabel: string;
    tags: string[];
    isOverdue: boolean;
    isSlaBreached: boolean;
    isLocked: boolean;
    isArchived: boolean;
    archive_reason?: string;
    parentId: number | null;
    parent?: TicketParent;
    childrenCount: number;
    children: TicketChild[];
    assignee: UserLite | null;
    reporter: UserLite | null;
    createdAt: string;
    updatedAt: string;
    dueDate?: string | null;
    slaBreached?: boolean;
    followers?: UserLite[];
    comments: Comment[];
    internalNotes: Comment[];
    attachments: Attachment[];
}

const route = useRoute();
const router = useRouter();
const toast = useToast();
const authStore = useAuthStore();

function can(permission: string) {
    return authStore.user?.permissions?.includes(permission) || false;
}

const ticketId = computed(() => route.params.id as string);
const isLoading = ref(true);
const loadError = ref<string | null>(null);
const newComment = ref("");
const isSubmittingComment = ref(false);

const ticket = ref<TicketDetail | null>(null);

const canManageAttachments = computed(() => {
    if (ticket.value?.status === "closed") return false;
    if (ticket.value?.isLocked) return false;
    return can("tickets.update");
});

const isInternalNote = ref(false);
const commentFiles = ref<File[]>([]);
// fileInput ref removed
const isFollowing = ref(false);
const isTogglingFollow = ref(false);
const canViewInternalNotes = ref(false);

// Edit modal state
const showEditModal = ref(false);
const isSubmittingEdit = ref(false);
interface EditForm {
    title: string;
    description: string;
    priority: string;
    type: string;
    tags: string[];
    reason: string;
}
const editForm = ref<EditForm>({
    title: "",
    description: "",
    priority: "",
    type: "",
    tags: [],
    reason: "",
});
const editErrors = ref<Record<string, string[]>>({});

// Activity state
const activities = ref<Activity[]>([]);
const isLoadingActivities = ref(false);
const activeTab = ref("comments"); // 'comments', 'activity', 'attachments'

const visibleTabs = computed(() => {
    const tabs = ["comments"];
    // Updated to match backend policy (tickets.update instead of tickets.manage)
    if (can("tickets.update")) {
        tabs.push("activity", "attachments");
    }
    return tabs;
});

// Attachments state
const attachments = ref<Attachment[]>([]);
const isLoadingAttachments = ref(false);
const isUploadingFile = ref(false);
const uploadQueue = ref<any[]>([]);

// Delete attachment modal state
const showDeleteAttachmentModal = ref(false);
const attachmentToDelete = ref<string | null>(null);
const isDeletingAttachment = ref(false);

// Reporter's other tickets state
interface ReporterTicket {
    id: string;
    displayId: string;
    title: string;
    status: { label: string; value: string };
    priority: { label: string; value: string };
    createdAt: string;
}
const reporterTickets = ref<ReporterTicket[]>([]);
const isLoadingReporterTickets = ref(false);

// Inline tag management
const showTagInput = ref(false);
const newTagValue = ref("");
const isSavingTag = ref(false);

async function addTag() {
    if (!newTagValue.value.trim() || !ticket.value) return;

    const tag = newTagValue.value.trim();
    if (ticket.value.tags.includes(tag)) {
        toast.error("Tag already exists");
        return;
    }

    isSavingTag.value = true;
    try {
        const updatedTags = [...ticket.value.tags, tag];
        await api.put(`/api/tickets/${ticketId.value}`, {
            tags: updatedTags,
            reason: `Added tag: ${tag}`,
        });
        ticket.value.tags = updatedTags;
        newTagValue.value = "";
        showTagInput.value = false;
        toast.success("Tag added");
    } catch (error) {
        toast.error("Failed to add tag");
    } finally {
        isSavingTag.value = false;
    }
}

async function removeTag(tag: string) {
    if (!ticket.value) return;

    isSavingTag.value = true;
    try {
        const updatedTags = ticket.value.tags.filter((t) => t !== tag);
        await api.put(`/api/tickets/${ticketId.value}`, {
            tags: updatedTags,
            reason: `Removed tag: ${tag}`,
        });
        ticket.value.tags = updatedTags;
        toast.success("Tag removed");
    } catch (error) {
        toast.error("Failed to remove tag");
    } finally {
        isSavingTag.value = false;
    }
}

onMounted(() => {
    fetchTicket();
});

async function fetchTicket() {
    try {
        isLoading.value = true;
        loadError.value = null;

        const response = await api.get(`/api/tickets/${ticketId.value}`);
        const data = response.data.data;

        ticket.value = {
            id: data.id,
            displayId: data.display_id,
            title: data.title,
            description: data.description || "",
            status: data.status.value,
            statusLabel: data.status.label,
            priority: data.priority.value,
            priorityLabel: data.priority.label,
            type: data.type.value,
            typeLabel: data.type.label,
            tags: data.tags || [],
            isOverdue: data.is_overdue,
            isSlaBreached: data.is_sla_breached,
            isLocked: data.is_locked,
            isArchived: data.is_archived,
            archive_reason: data.archive_reason,
            parentId: data.parent_id,
            parent: data.parent,
            childrenCount: data.children_count,
            children: data.children || [],
            assignee: data.assignee
                ? {
                      id: data.assignee.id,
                      name: data.assignee.name,
                      initials: data.assignee.initials,
                      avatarUrl: data.assignee.avatar_thumb_url,
                  }
                : null,
            reporter: data.reporter
                ? {
                      id: data.reporter.id,
                      name: data.reporter.name,
                      initials: data.reporter.initials,
                      avatarUrl: data.reporter.avatar_thumb_url,
                  }
                : null,
            createdAt: data.created_at,
            updatedAt: data.updated_at,
            dueDate: data.due_date,
            slaBreached: data.sla_breached,
            followers: data.followers || [],
            comments: (data.comments || []).map((c: any) => ({
                id: c.public_id,
                author: {
                    name: c.author?.name || "Unknown",
                    initials: c.author?.initials || "??",
                    avatarUrl: c.author?.avatar_thumb_url,
                },
                content: c.content,
                createdAt: c.created_at,
                isInternal: false,
                attachments: c.attachments || [],
            })),
            internalNotes: (data.internal_notes || []).map((n: any) => ({
                id: n.public_id,
                author: {
                    name: n.author?.name || "Unknown",
                    initials: n.author?.initials || "??",
                    avatarUrl: n.author?.avatar_thumb_url,
                },
                content: n.content,
                createdAt: n.created_at,
                isInternal: true,
                attachments: n.attachments || [],
            })),
            attachments: [],
        };

        isFollowing.value = data.is_following || false;
        canViewInternalNotes.value = data.can_view_internal_notes || false;

        // Fetch other tickets by this reporter
        fetchReporterTickets();
    } catch (error: any) {
        console.error("Failed to fetch ticket:", error);
        loadError.value =
            error.response?.status === 404
                ? "Ticket not found"
                : "Failed to load ticket. Please try again.";
    } finally {
        isLoading.value = false;
    }
}

// Fetch other tickets by the same reporter
async function fetchReporterTickets() {
    if (!ticket.value?.reporter?.id) return;

    isLoadingReporterTickets.value = true;
    try {
        // Fetch tickets by this reporter, excluding current ticket
        const response = await api.get("/api/tickets", {
            params: {
                reporter_id: ticket.value.reporter.id,
                exclude: ticketId.value,
                per_page: 5,
            },
        });

        reporterTickets.value = (response.data.data || []).map((t: any) => ({
            id: t.public_id,
            displayId: t.display_id,
            title: t.title,
            status: t.status,
            priority: t.priority,
            createdAt: t.created_at,
        }));
    } catch (error) {
        console.error("Failed to fetch reporter tickets:", error);
        reporterTickets.value = [];
    } finally {
        isLoadingReporterTickets.value = false;
    }
}

// Sanitized ticket description
const sanitizedDescription = computed(() => {
    return ticket.value?.description
        ? sanitizeHtml(ticket.value.description)
        : "";
});

// Combine comments and internal notes for display with sanitized content
const allComments = computed(() => {
    if (!ticket.value) return [];

    const comments = ticket.value.comments.map((c) => ({
        ...c,
        content: sanitizeHtml(c.content || ""),
        isInternal: false,
    }));
    const notes = canViewInternalNotes.value
        ? ticket.value.internalNotes.map((n) => ({
              ...n,
              content: sanitizeHtml(n.content || ""),
              isInternal: true,
          }))
        : [];

    return [...comments, ...notes].sort(
        (a, b) =>
            new Date(a.createdAt).getTime() - new Date(b.createdAt).getTime(),
    );
});

function getStatusConfig(status: string) {
    const configs: Record<
        string,
        {
            label: string;
            variant: "default" | "primary" | "success" | "secondary";
            icon: any;
        }
    > = {
        open: { label: "Open", variant: "default", icon: AlertCircle },
        in_progress: { label: "In Progress", variant: "primary", icon: Clock },
        resolved: { label: "Resolved", variant: "success", icon: CheckCircle2 },
        closed: { label: "Closed", variant: "secondary", icon: CheckCircle2 },
    };
    return configs[status] || configs.open;
}

function getPriorityConfig(priority: string) {
    const configs: Record<
        string,
        { label: string; class: string; icon: any; bgClass: string }
    > = {
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

function getTypeConfig(type: string) {
    const configs: Record<
        string,
        {
            label: string;
            variant:
                | "default"
                | "primary"
                | "secondary"
                | "warning"
                | "success"
                | "danger"
                | "info";
        }
    > = {
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

// getFileIcon removed

function formatDate(dateString: string) {
    if (!dateString) return "";
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}

function formatRelativeTime(dateString: string) {
    if (!dateString) return "";
    const date = new Date(dateString);
    const now = new Date();
    const diff = now.getTime() - date.getTime();
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(diff / 3600000);
    const days = Math.floor(diff / 86400000);

    if (minutes < 1) return "Just now";
    if (minutes < 60) return `${minutes}m ago`;
    if (hours < 24) return `${hours}h ago`;
    if (days < 7) return `${days}d ago`;
    return formatDate(dateString);
}

// Activity timeline helper functions
const activityIconMap: Record<string, any> = {
    "plus-circle": PlusCircle,
    edit: Edit,
    trash: Trash2,
    "trash-2": Trash2,
    "user-plus": UserPlus,
    "user-minus": UserMinus,
    upload: Upload,
    "file-x": FileX,
    "message-square": MessageSquare,
    paperclip: Paperclip,
    "refresh-cw": RefreshCw,
    archive: ArchiveIcon,
    key: Key,
    shield: Shield,
    lock: Lock,
    settings: Settings,
    eye: Eye,
    "log-out": LogOut,
    login: LogIn,
    logout: LogOut,
    "mail-check": MailCheck,
    "alert-triangle": AlertTriangle,
    "alert-circle": AlertCircle,
    "check-circle": CheckCircle2,
    ticket: Paperclip,
};

function getActivityIcon(iconName: string) {
    if (!iconName) return Edit;
    return activityIconMap[iconName] || Edit;
}

function getActivityIconStyle(action: string) {
    const styleMap: Record<string, string> = {
        created:
            "bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400",
        ticket_created:
            "bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400",
        updated:
            "bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400",
        ticket_updated:
            "bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400",
        deleted: "bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400",
        force_deleted:
            "bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400",
        archived:
            "bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400",
        ticket_status_changed:
            "bg-purple-100 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400",
        ticket_assigned:
            "bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400",
        ticket_comment_added:
            "bg-cyan-100 dark:bg-cyan-900/30 text-cyan-600 dark:text-cyan-400",
        ticket_attachment_added:
            "bg-teal-100 dark:bg-teal-900/30 text-teal-600 dark:text-teal-400",
        ticket_attachment_removed:
            "bg-orange-100 dark:bg-orange-900/30 text-orange-600 dark:text-orange-400",
        file_uploaded:
            "bg-teal-100 dark:bg-teal-900/30 text-teal-600 dark:text-teal-400",
        file_deleted:
            "bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400",
    };
    return (
        styleMap[action] ||
        "bg-[var(--surface-tertiary)] text-[var(--text-muted)]"
    );
}

function getActivityActionText(activity: any) {
    // Use action_label from API if available, otherwise generate from action
    if (activity.action_label) {
        return activity.action_label.toLowerCase();
    }

    const actionTextMap: Record<string, string> = {
        created: "created this ticket",
        updated: "updated this ticket",
        deleted: "deleted this ticket",
        archived: "archived this ticket",
        ticket_created: "created this ticket",
        ticket_updated: "updated this ticket",
        ticket_status_changed: "changed the status",
        ticket_assigned: "assigned this ticket",
        ticket_comment_added: "added a comment",
        ticket_attachment_added: "added an attachment",
        ticket_attachment_removed: "removed an attachment",
        file_uploaded: "uploaded a file",
        file_deleted: "deleted a file",
    };

    return (
        actionTextMap[activity.action] ||
        activity.description ||
        "performed an action"
    );
}

function hasVisibleChanges(changes: any) {
    if (!changes?.new) return false;
    const hiddenKeys = [
        "updated_at",
        "created_at",
        "id",
        "public_id",
        "user_id",
        "team_id",
    ];
    return Object.keys(changes.new).some((key) => !hiddenKeys.includes(key));
}

function isHiddenChangeKey(key: string) {
    const hiddenKeys = [
        "updated_at",
        "created_at",
        "id",
        "public_id",
        "user_id",
        "team_id",
        "uuid",
        "remember_token",
    ];
    return hiddenKeys.includes(key);
}

function formatChangeKey(key: string) {
    return key.replace(/_/g, " ").replace(/id$/i, "");
}

function formatChangeValue(value: any) {
    if (value === null || value === undefined) return "N/A";
    if (typeof value === "boolean") return value ? "Yes" : "No";
    if (typeof value === "object") return JSON.stringify(value);
    if (typeof value === "string" && value.length > 50)
        return value.substring(0, 50) + "...";
    return String(value);
}

function goBack() {
    if (can("tickets.view")) {
        router.push({ name: "tickets" });
    } else {
        router.push({ name: "support" });
    }
}

async function submitComment() {
    if (!newComment.value.trim()) return;

    isSubmittingComment.value = true;
    const wasInternal = isInternalNote.value;

    try {
        const endpoint = wasInternal
            ? `/api/tickets/${ticketId.value}/internal-notes`
            : `/api/tickets/${ticketId.value}/comments`;

        let response;
        if (commentFiles.value.length > 0) {
            const formData = new FormData();
            formData.append("content", newComment.value);
            commentFiles.value.forEach((file: File, index: number) => {
                formData.append(`attachments[${index}]`, file);
            });
            response = await api.post(endpoint, formData, {
                headers: { "Content-Type": "multipart/form-data" },
            });
        } else {
            response = await api.post(endpoint, {
                content: newComment.value,
            });
        }

        const newEntry: Comment = {
            id: response.data.data.public_id,
            author: {
                id: response.data.data.author?.id || 0,
                name: response.data.data.author?.name || "You",
                initials: response.data.data.author?.initials || "YO",
                avatarUrl: response.data.data.author?.avatar_thumb_url,
            },
            content: response.data.data.content,
            createdAt: response.data.data.created_at,
            isInternal: wasInternal,
            attachments: response.data.data.attachments || [],
        };

        if (ticket.value) {
            if (wasInternal) {
                ticket.value.internalNotes.push(newEntry);
                toast.success(
                    "Internal Note Added",
                    "Your internal note has been posted.",
                );
            } else {
                ticket.value.comments.push(newEntry);
                toast.success("Comment Added", "Your comment has been posted.");
            }
        }

        newComment.value = "";
        commentFiles.value = [];
        isInternalNote.value = false;

        if (activeTab.value === "activity") fetchActivity();
    } catch (error: any) {
        console.error("Failed to add comment:", error);
        toast.error(
            "Error",
            error.response?.data?.message || "Failed to add comment.",
        );
    } finally {
        isSubmittingComment.value = false;
    }
}

async function updateStatus(newStatus: string) {
    try {
        await api.put(`/api/tickets/${ticketId.value}/status`, {
            status: newStatus,
        });

        if (ticket.value) ticket.value.status = newStatus;
        toast.success(
            "Status Updated",
            `Ticket status changed to ${getStatusConfig(newStatus).label}.`,
        );
    } catch (error: any) {
        console.error("Failed to update status:", error);
        toast.error(
            "Error",
            error.response?.data?.message || "Failed to update status.",
        );
    }
}

async function toggleFollow() {
    isTogglingFollow.value = true;

    try {
        if (isFollowing.value) {
            await api.delete(`/api/tickets/${ticketId.value}/follow`);
            isFollowing.value = false;
            toast.success(
                "Unfollowed",
                "You will no longer receive updates for this ticket.",
            );
        } else {
            await api.post(`/api/tickets/${ticketId.value}/follow`);
            isFollowing.value = true;
            toast.success(
                "Following",
                "You will now receive updates for this ticket.",
            );
        }
    } catch (error) {
        console.error("Failed to toggle follow:", error);
        toast.error("Error", "Failed to update follow status.");
    } finally {
        isTogglingFollow.value = false;
    }
}

// Edit modal methods
function openEditModal() {
    if (!ticket.value) return;
    editForm.value = {
        title: ticket.value.title,
        description: ticket.value.description || "",
        priority: ticket.value.priority,
        type: ticket.value.type,
        tags: [...(ticket.value.tags || [])],
        reason: "",
    };
    editErrors.value = {};
    showEditModal.value = true;
}

const showDeleteModal = ref(false);
const isDeleting = ref(false);
const deleteReason = ref("");

// _openDeleteModal removed

async function confirmDelete() {
    try {
        isDeleting.value = true;
        await api.delete(`/api/tickets/${ticketId.value}`, {
            data: { reason: deleteReason.value },
        });
        toast.success("Ticket Deleted");
        router.push({ name: "tickets" });
    } catch (e: any) {
        toast.error(e.response?.data?.message || "Failed to delete ticket");
    } finally {
        isDeleting.value = false;
    }
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
        const payload: Record<string, any> = { reason: editForm.value.reason };
        const t = ticket.value!;
        const f = editForm.value;

        if (f.title !== t.title) payload.title = f.title;
        if (f.description !== (t.description || ""))
            payload.description = f.description;
        if (f.priority !== t.priority) payload.priority = f.priority;
        if (f.type !== t.type) payload.type = f.type;

        // Tags check
        const tTags = JSON.stringify((t.tags || []).slice().sort());
        const fTags = JSON.stringify((f.tags || []).slice().sort());
        if (tTags !== fTags) payload.tags = f.tags;

        await api.put(`/api/tickets/${ticketId.value}`, payload);

        toast.success("Ticket updated successfully");
        showEditModal.value = false;
        await fetchTicket();
    } catch (error: any) {
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

// Assignment state
const showAssignModal = ref(false);
const isAssigning = ref(false);
const assignableUsers = ref<any[]>([]); // {value, label, image}
const selectedAssignee = ref<number | null>(null);
const isLoadingUsers = ref(false);

function openAssignModal() {
    showAssignModal.value = true;
    selectedAssignee.value = ticket.value?.assignee?.id || null;
    fetchAssignableUsers();
}

async function fetchAssignableUsers() {
    try {
        isLoadingUsers.value = true;
        const response = await api.get("/api/tickets/assignable-users");
        assignableUsers.value = response.data.data.map((u: any) => ({
            value: u.id,
            label: u.name,
            image: u.avatar_thumb_url,
        }));
    } catch (error) {
        console.error("Failed to fetch assignable users:", error);
        toast.error("Failed to load assignable users");
    } finally {
        isLoadingUsers.value = false;
    }
}

// Hierarchy State
const showLinkModal = ref(false);
const isLinking = ref(false);
const linkForm = ref({
    parentId: "",
});
const linkErrors = ref<Record<string, string[]>>({});
const linkableTickets = ref<any[]>([]);
const isLoadingLinkable = ref(false);

function openLinkModal() {
    showLinkModal.value = true;
    linkForm.value.parentId = "";
    linkErrors.value = {};
    fetchLinkableTickets();
}

const linkSearchQuery = ref("");
// _isSearchingLinkable removed

const debouncedLinkSearch = debounce((query) => {
    fetchLinkableTickets(query);
}, 300);

function handleLinkSearch(query: string) {
    linkSearchQuery.value = query;
    debouncedLinkSearch(query);
}

async function fetchLinkableTickets(query = "") {
    try {
        isLoadingLinkable.value = true;
        const params = {
            search: query,
            exclude: ticketId.value, // Exclude current ticket
        };
        const response = await api.get("/api/tickets/search-linkable", {
            params,
        });
        linkableTickets.value = response.data.data.map((t: any) => ({
            value: t.id,
            label: t.label,
        }));
    } catch (e) {
        console.error(e);
    } finally {
        isLoadingLinkable.value = false;
    }
}

async function submitLink() {
    if (!linkForm.value.parentId) {
        linkErrors.value = { parentId: ["Please select a ticket"] };
        return;
    }

    try {
        isLinking.value = true;
        await api.post(`/api/tickets/${ticketId.value}/link`, {
            parent_id: linkForm.value.parentId,
        });
        toast.success("Ticket linked successfully");
        showLinkModal.value = false;
        await fetchTicket();
    } catch (error: any) {
        if (error.response?.status === 422) {
            linkErrors.value = error.response.data.errors || {};
            toast.error(error.response.data.message || "Failed to link");
        } else {
            toast.error("Failed to link ticket");
        }
    } finally {
        isLinking.value = false;
    }
}

async function unlinkChild(child: TicketChild) {
    if (
        !confirm(
            `Are you sure you want to unlink ticket #${child.public_id.substr(
                0,
                8,
            )}?`,
        )
    )
        return;

    try {
        await api.post(`/api/tickets/${child.public_id}/unlink`);
        toast.success("Ticket unlinked successfully");
        await fetchTicket();
    } catch (error) {
        toast.error("Failed to unlink ticket");
    }
}

// Archive State
const showArchiveModal = ref(false);
const isArchiving = ref(false);
const archiveForm = ref({
    reason: "",
});
const archiveErrors = ref<Record<string, string[]>>({});

function openArchiveModal() {
    showArchiveModal.value = true;
    archiveForm.value.reason = "";
    archiveErrors.value = {};
}

async function submitArchive() {
    try {
        isArchiving.value = true;
        await api.post(`/api/tickets/${ticketId.value}/archive`, {
            reason: archiveForm.value.reason,
        });
        toast.success("Ticket archived successfully");
        showArchiveModal.value = false;
        router.push("/tickets"); // Redirect to list or refresh?
        // Logic says "archived tickets locked". Maybe stay and show locked state.
        await fetchTicket();
    } catch (error: any) {
        if (error.response?.status === 422) {
            archiveErrors.value = error.response.data.errors || {};
        } else {
            toast.error("Failed to archive ticket");
        }
    } finally {
        isArchiving.value = false;
    }
}

// Renamed from assignTicket to submitAssign to match template usage
async function submitAssign() {
    try {
        isAssigning.value = true;
        await api.put(`/api/tickets/${ticketId.value}/assign`, {
            assigned_to: selectedAssignee.value,
        });

        toast.success(
            "Ticket Assigned",
            "Ticket assignee updated successfully.",
        ); // Fixed typo "Assinged"
        showAssignModal.value = false;
        await fetchTicket();
        // Also add logic to update audit log / activity silently if needed, but fetchTicket refreshes state
        fetchActivity();
    } catch (error: any) {
        console.error("Failed to assign ticket:", error);
        toast.error(
            "Error",
            error.response?.data?.message || "Failed to assign ticket.",
        );
    } finally {
        isAssigning.value = false;
    }
}

// Activity methods
async function fetchActivity() {
    try {
        isLoadingActivities.value = true;
        const response = await api.get(
            `/api/tickets/${ticketId.value}/activity`,
        );
        activities.value = response.data.data || [];
    } catch (error) {
        console.error("Failed to fetch activity:", error);
    } finally {
        isLoadingActivities.value = false;
    }
}

// Attachments methods
async function fetchAttachments() {
    try {
        isLoadingAttachments.value = true;
        const response = await api.get(
            `/api/tickets/${ticketId.value}/attachments`,
        );
        attachments.value = response.data.data || [];
    } catch (error) {
        console.error("Failed to fetch attachments:", error);
    } finally {
        isLoadingAttachments.value = false;
    }
}

async function uploadAttachment(files: File[] | File) {
    const fileList = Array.isArray(files) ? files : [files];

    fileList.forEach((file) => {
        uploadQueue.value.push({
            id: Math.random().toString(36).substr(2, 9),
            file,
            progress: 0,
            status: "pending",
        });
    });
}

const processUploadQueue = async () => {
    if (isUploadingFile.value) return;

    const pendingItems = uploadQueue.value.filter(
        (i) => i.status === "pending",
    );
    if (pendingItems.length === 0) return;

    isUploadingFile.value = true;
    let successCount = 0;

    for (const item of pendingItems) {
        item.status = "uploading";
        const formData = new FormData();
        formData.append("file", item.file);

        try {
            await api.post(
                `/api/tickets/${ticketId.value}/attachments`,
                formData,
                {
                    headers: { "Content-Type": "multipart/form-data" },
                    onUploadProgress: (progressEvent) => {
                        const percentCompleted = Math.round(
                            (progressEvent.loaded * 100) / progressEvent.total,
                        );
                        item.progress = percentCompleted;
                    },
                },
            );
            item.status = "completed";
            item.progress = 100;
            successCount++;
        } catch (error) {
            console.error("Failed to upload file:", error);
            item.status = "error";
            item.error = "Upload failed";
            toast.error(`Failed to upload ${item.file.name}`);
        }
    }

    if (successCount > 0) {
        toast.success(`Uploaded ${successCount} files`);
        await fetchAttachments();
        // Clear completed
        uploadQueue.value = uploadQueue.value.filter(
            (item) => item.status !== "completed",
        );
    }

    isUploadingFile.value = uploadQueue.value.some(
        (i) => i.status === "uploading",
    );
};

const removeFileFromQueue = (index: number) => {
    uploadQueue.value.splice(index, 1);
};

function openDeleteAttachmentModal(mediaId: string) {
    attachmentToDelete.value = mediaId;
    showDeleteAttachmentModal.value = true;
}

async function confirmDeleteAttachment() {
    if (!attachmentToDelete.value) return;

    try {
        isDeletingAttachment.value = true;
        await api.delete(
            `/api/tickets/${ticketId.value}/attachments/${attachmentToDelete.value}`,
        );
        toast.success("Attachment deleted");
        showDeleteAttachmentModal.value = false;
        attachmentToDelete.value = null;
        await fetchAttachments();
    } catch (error) {
        console.error("Failed to delete attachment:", error);
        toast.error("Failed to delete attachment");
    } finally {
        isDeletingAttachment.value = false;
    }
}

function copyToClipboard(text: string) {
    navigator.clipboard.writeText(text);
    toast.success("Copied to clipboard");
}

function formatFileSize(bytes: number) {
    if (bytes < 1024) return bytes + " B";
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + " KB";
    return (bytes / (1024 * 1024)).toFixed(1) + " MB";
}

// Watch tab changes to load data
function onTabChange(tab: string) {
    activeTab.value = tab;
    if (tab === "activity" && activities.value.length === 0) {
        fetchActivity();
    }
    if (tab === "attachments" && attachments.value.length === 0) {
        fetchAttachments();
    }
}
// File handling for comments
function handleFileSelect(event: Event) {
    const target = event.target as HTMLInputElement;
    if (target.files) {
        const files = Array.from(target.files);
        commentFiles.value = [...commentFiles.value, ...files];
        // Reset input so same file can be selected again if needed
        target.value = "";
    }
}

function removeFile(index: number) {
    commentFiles.value.splice(index, 1);
}

function isVisualMedia(att: Attachment) {
    if (att.mime_type) {
        return (
            att.mime_type.startsWith("image/") ||
            att.mime_type.startsWith("video/")
        );
    }
    const ext = att.name.split(".").pop()?.toLowerCase();
    return ext
        ? ["jpg", "jpeg", "png", "gif", "webp", "mp4", "webm", "mov"].includes(
              ext,
          )
        : false;
}
</script>

<template>
    <div>
        <!-- Loading State -->
        <div v-if="isLoading" class="flex items-center justify-center py-20">
            <Loader2
                class="h-8 w-8 animate-spin text-[var(--interactive-primary)]"
            />
        </div>

        <!-- Error State -->
        <div v-else-if="loadError" class="text-center py-20">
            <AlertTriangle
                class="h-12 w-12 mx-auto text-[var(--text-muted)] mb-4"
            />
            <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-2">
                {{ loadError }}
            </h2>
            <p class="text-[var(--text-secondary)] mb-4">
                The ticket you're looking for may not exist or you don't have
                permission to view it.
            </p>
            <Button @click="goBack">
                <ArrowLeft class="h-4 w-4" />
                Back to Tickets
            </Button>
        </div>

        <div v-else-if="ticket" class="p-6 space-y-6">
            <Alert v-if="ticket.isLocked" variant="warning" class="mb-4">
                <div class="flex items-center gap-2">
                    <Lock class="w-4 h-4" />
                    <span v-if="ticket.isArchived"
                        >This ticket is archived and read-only. Reason:
                        {{ ticket.archive_reason }}</span
                    >
                    <span v-else-if="ticket.parent"
                        >This ticket is a child of ticket #{{
                            ticket.parent.id.substr(0, 8)
                        }}
                        and is read-only.</span
                    >
                </div>
            </Alert>

            <!-- Header -->
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between min-w-0"
            >
                <div
                    class="flex items-start gap-4 min-w-0 flex-1 overflow-hidden"
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        @click="goBack"
                        class="shrink-0"
                    >
                        <ArrowLeft class="h-5 w-5" />
                    </Button>
                    <div class="flex-1 min-w-0">
                        <!-- Ticket ID and Badges Row -->
                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                            <span
                                class="text-sm font-mono text-[var(--text-muted)] flex items-center gap-1.5 bg-[var(--surface-secondary)] px-2 py-0.5 rounded-md border border-[var(--border-default)]"
                            >
                                {{ ticket.displayId }}
                                <button
                                    @click="copyToClipboard(ticket.displayId)"
                                    class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
                                    title="Copy Ticket ID"
                                >
                                    <Copy class="h-3 w-3" />
                                </button>
                            </span>
                            <Badge
                                :variant="getTypeConfig(ticket.type).variant"
                                size="sm"
                            >
                                {{ getTypeConfig(ticket.type).label }}
                            </Badge>
                            <Badge
                                :variant="
                                    getStatusConfig(ticket.status).variant
                                "
                                size="sm"
                            >
                                <component
                                    :is="getStatusConfig(ticket.status).icon"
                                    class="h-3 w-3 mr-1"
                                />
                                {{ getStatusConfig(ticket.status).label }}
                            </Badge>
                            <Badge
                                v-if="ticket.isOverdue"
                                variant="danger"
                                size="sm"
                            >
                                Overdue
                            </Badge>
                            <Badge
                                v-if="ticket.slaBreached"
                                variant="warning"
                                size="sm"
                            >
                                SLA Breached
                            </Badge>
                        </div>
                        <!-- Title with word wrap -->
                        <h1
                            class="text-xl lg:text-2xl font-bold text-[var(--text-primary)] break-words"
                        >
                            {{ ticket.title }}
                        </h1>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="toggleFollow"
                        :disabled="isTogglingFollow"
                    >
                        <Star
                            v-if="isFollowing"
                            class="h-4 w-4 fill-yellow-500 text-yellow-500"
                        />
                        <StarOff v-else class="h-4 w-4" />
                        {{ isFollowing ? "Following" : "Follow" }}
                    </Button>

                    <Dropdown align="end" v-if="can('tickets.update')">
                        <template #trigger>
                            <Button variant="outline" size="sm">
                                Status
                                <component
                                    :is="getStatusConfig(ticket.status).icon"
                                    class="h-4 w-4"
                                />
                            </Button>
                        </template>
                        <DropdownItem @select="updateStatus('open')"
                            >Open</DropdownItem
                        >
                        <DropdownItem @select="updateStatus('in_progress')"
                            >In Progress</DropdownItem
                        >
                        <DropdownItem @select="updateStatus('resolved')"
                            >Resolved</DropdownItem
                        >
                        <DropdownItem @select="updateStatus('closed')"
                            >Closed</DropdownItem
                        >
                    </Dropdown>

                    <Dropdown
                        align="end"
                        v-if="
                            can('tickets.update') ||
                            can('tickets.assign') ||
                            can('tickets.delete')
                        "
                    >
                        <template #trigger>
                            <Button variant="ghost" size="icon">
                                <MoreVertical class="h-5 w-5" />
                            </Button>
                        </template>
                        <DropdownItem
                            @select="openEditModal"
                            v-if="can('tickets.update')"
                        >
                            <Edit3 class="h-4 w-4" />
                            Edit Ticket
                        </DropdownItem>
                        <DropdownItem
                            @select="openAssignModal"
                            v-if="can('tickets.assign')"
                        >
                            <UserPlus class="h-4 w-4" />
                            Assign to...
                        </DropdownItem>
                        <DropdownSeparator
                            v-if="
                                (can('tickets.update') ||
                                    can('tickets.assign')) &&
                                can('tickets.delete')
                            "
                        />
                        <DropdownItem
                            v-if="can('tickets.delete')"
                            @click="
                                showDeleteModal = true;
                                deleteReason = '';
                            "
                        >
                            <Trash2 class="mr-2 h-4 w-4 text-red-500" />
                            <span class="text-red-500">Delete Ticket</span>
                        </DropdownItem>
                        <DropdownItem
                            @click="openArchiveModal"
                            v-if="can('tickets.delete')"
                        >
                            <ArchiveIcon class="mr-2 h-4 w-4" />
                            <span>Archive Ticket</span>
                        </DropdownItem>
                    </Dropdown>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-5">
                <!-- Main Content -->
                <div class="lg:col-span-4 space-y-6">
                    <!-- Description -->
                    <Card padding="lg">
                        <h2
                            class="text-lg font-semibold text-[var(--text-primary)] mb-4"
                        >
                            Description
                        </h2>
                        <div
                            v-if="ticket.description"
                            class="prose prose-sm max-w-none text-[var(--text-secondary)] prose-p:my-2 prose-strong:text-[var(--text-primary)] prose-code:text-[var(--text-primary)] prose-code:bg-[var(--surface-secondary)] prose-code:px-1 prose-code:rounded prose-ol:my-2 prose-li:my-0"
                            v-html="sanitizedDescription"
                        />
                        <p v-else class="text-[var(--text-muted)] italic">
                            No description provided.
                        </p>
                    </Card>

                    <!-- Tabs -->
                    <div class="border-b border-[var(--border-default)]">
                        <div class="flex items-center gap-6">
                            <button
                                v-for="tab in visibleTabs"
                                :key="tab"
                                @click="onTabChange(tab)"
                                class="pb-3 text-sm font-medium transition-colors relative"
                                :class="
                                    activeTab === tab
                                        ? 'text-[var(--interactive-primary)]'
                                        : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                                "
                            >
                                {{ tab.charAt(0).toUpperCase() + tab.slice(1) }}
                                <span
                                    v-if="activeTab === tab"
                                    class="absolute bottom-0 left-0 right-0 h-0.5 bg-[var(--interactive-primary)] translate-y-[1px]"
                                ></span>
                            </button>
                        </div>
                    </div>

                    <!-- Comments Tab -->
                    <div v-show="activeTab === 'comments'" class="space-y-6">
                        <Card padding="lg">
                            <h2
                                class="text-lg font-semibold text-[var(--text-primary)] mb-4"
                            >
                                Comments ({{ allComments.length }})
                            </h2>

                            <!-- Comment List -->
                            <div
                                v-if="allComments.length"
                                class="space-y-4 mb-6"
                            >
                                <div
                                    v-for="comment in allComments"
                                    :key="comment.id"
                                    :class="[
                                        'flex gap-3 p-3 -mx-3 rounded-xl transition-colors',
                                        comment.isInternal
                                            ? 'bg-amber-50/50 dark:bg-amber-900/10 border border-amber-200 dark:border-amber-800/50'
                                            : '',
                                    ]"
                                >
                                    <Avatar
                                        :fallback="comment.author.initials"
                                        :src="comment.author.avatarUrl"
                                        size="sm"
                                        class="shrink-0 mt-1"
                                    />
                                    <div class="flex-1 min-w-0">
                                        <div
                                            class="flex items-center gap-2 mb-1"
                                        >
                                            <span
                                                class="text-sm font-semibold text-[var(--text-primary)]"
                                            >
                                                {{ comment.author.name }}
                                            </span>
                                            <span
                                                v-if="comment.isInternal"
                                                class="inline-flex items-center gap-1 px-1.5 py-0.5 text-xs font-medium rounded bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400"
                                            >
                                                <Lock class="h-3 w-3" />
                                                Internal
                                            </span>
                                            <span
                                                class="text-xs text-[var(--text-muted)]"
                                            >
                                                {{
                                                    formatRelativeTime(
                                                        comment.createdAt,
                                                    )
                                                }}
                                            </span>
                                        </div>
                                        <div
                                            class="text-sm text-[var(--text-secondary)] prose prose-sm max-w-none prose-p:my-1 prose-code:text-[var(--text-primary)] prose-code:bg-[var(--surface-secondary)] prose-code:px-1 prose-code:rounded"
                                            v-html="comment.content"
                                        />
                                        <div
                                            v-if="
                                                comment.attachments &&
                                                comment.attachments.length
                                            "
                                            class="mt-2"
                                        >
                                            <!-- Visual Gallery -->
                                            <div
                                                class="mv-gallery flex flex-wrap gap-2 mb-2"
                                            >
                                                <template
                                                    v-for="att in comment.attachments"
                                                    :key="'img-' + att.id"
                                                >
                                                    <div
                                                        v-if="
                                                            isVisualMedia(att)
                                                        "
                                                        class="relative group"
                                                    >
                                                        <img
                                                            :src="
                                                                att.thumb_url ||
                                                                att.url
                                                            "
                                                            :data-full="att.url"
                                                            :data-download="
                                                                att.url
                                                            "
                                                            :data-media-id="
                                                                att.id
                                                            "
                                                            :data-mime-type="
                                                                att.mime_type
                                                            "
                                                            data-can-delete="false"
                                                            class="h-20 w-20 object-cover rounded-md border border-[var(--border-default)] cursor-pointer hover:opacity-90 transition-opacity"
                                                        />
                                                        <div
                                                            v-if="
                                                                att.mime_type?.startsWith(
                                                                    'video/',
                                                                )
                                                            "
                                                            class="absolute inset-0 flex items-center justify-center pointer-events-none"
                                                        >
                                                            <div
                                                                class="bg-black/50 rounded-full p-1"
                                                            >
                                                                <Play
                                                                    class="h-4 w-4 text-white fill-current"
                                                                />
                                                            </div>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>

                                            <!-- Documents -->
                                            <div class="flex flex-wrap gap-2">
                                                <template
                                                    v-for="att in comment.attachments"
                                                    :key="'doc-' + att.id"
                                                >
                                                    <a
                                                        v-if="
                                                            !isVisualMedia(att)
                                                        "
                                                        :href="att.url"
                                                        target="_blank"
                                                        class="flex items-center gap-1.5 bg-[var(--surface-tertiary)] hover:bg-[var(--surface-secondary)] px-2.5 py-1 rounded-md text-xs border border-[var(--border-default)] transition-colors no-underline"
                                                    >
                                                        <Paperclip
                                                            class="h-3 w-3 text-[var(--text-secondary)]"
                                                        />
                                                        <span
                                                            class="text-[var(--text-primary)] font-medium"
                                                            >{{
                                                                att.name
                                                            }}</span
                                                        >
                                                        <span
                                                            class="text-[var(--text-muted)]"
                                                            >({{
                                                                formatFileSize(
                                                                    att.size,
                                                                )
                                                            }})</span
                                                        >
                                                    </a>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                v-else
                                class="text-center py-8 text-[var(--text-muted)]"
                            >
                                <MessageSquare
                                    class="h-8 w-8 mx-auto mb-2 opacity-50"
                                />
                                <p class="text-sm">
                                    No comments yet. Be the first to comment.
                                </p>
                            </div>

                            <!-- Add Comment -->
                            <div
                                class="pt-4 border-t border-[var(--border-muted)]"
                            >
                                <div class="flex gap-3">
                                    <Avatar
                                        fallback="YO"
                                        size="sm"
                                        class="shrink-0 mt-1"
                                    />
                                    <div class="flex-1">
                                        <div
                                            :class="[
                                                'rounded-xl border transition-all',
                                                isInternalNote
                                                    ? 'border-amber-400 dark:border-amber-500 bg-amber-50/50 dark:bg-amber-900/10'
                                                    : 'border-[var(--border-default)]',
                                            ]"
                                        >
                                            <RichTextEditor
                                                v-model="newComment"
                                                placeholder="Write a comment..."
                                                min-height="100px"
                                                :class="
                                                    isInternalNote
                                                        ? '[&_.ProseMirror]:bg-transparent'
                                                        : ''
                                                "
                                            />

                                            <!-- File Drop/Selected Area -->
                                            <div
                                                v-if="commentFiles.length > 0"
                                                class="flex flex-wrap gap-2 px-3 pb-2 pt-2 border-t border-[var(--border-muted)] bg-[var(--surface-secondary)]/30"
                                            >
                                                <div
                                                    v-for="(
                                                        file, index
                                                    ) in commentFiles"
                                                    :key="index"
                                                    class="flex items-center gap-2 bg-[var(--surface-primary)] px-2 py-1 rounded text-xs border border-[var(--border-default)] shadow-sm"
                                                >
                                                    <Paperclip
                                                        class="h-3 w-3 text-[var(--text-muted)]"
                                                    />
                                                    <span
                                                        class="max-w-[150px] truncate text-[var(--text-secondary)]"
                                                        >{{ file.name }}</span
                                                    >
                                                    <button
                                                        @click="
                                                            removeFile(index)
                                                        "
                                                        class="text-[var(--text-muted)] hover:text-red-500 transition-colors"
                                                    >
                                                        <X class="h-3 w-3" />
                                                    </button>
                                                </div>
                                            </div>
                                            <input
                                                type="file"
                                                ref="fileInput"
                                                multiple
                                                class="hidden"
                                                @change="handleFileSelect"
                                            />
                                        </div>
                                        <div
                                            class="flex items-center justify-between mt-3"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="px-2 h-7"
                                                    title="Attach files"
                                                    @click="
                                                        $refs.fileInput.click()
                                                    "
                                                >
                                                    <Paperclip
                                                        class="h-4 w-4 text-[var(--text-secondary)]"
                                                    />
                                                </Button>
                                                <Switch
                                                    v-if="canViewInternalNotes"
                                                    v-model="isInternalNote"
                                                    size="sm"
                                                />
                                                <div
                                                    v-if="canViewInternalNotes"
                                                    class="flex items-center gap-1.5"
                                                >
                                                    <Lock
                                                        v-if="isInternalNote"
                                                        class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400"
                                                    />
                                                    <span
                                                        :class="[
                                                            'text-sm',
                                                            isInternalNote
                                                                ? 'font-medium text-amber-600 dark:text-amber-400'
                                                                : 'text-[var(--text-secondary)]',
                                                        ]"
                                                    >
                                                        Internal note
                                                    </span>
                                                </div>
                                                <span
                                                    v-if="isInternalNote"
                                                    class="text-xs text-[var(--text-muted)]"
                                                >
                                                    (only visible to team
                                                    members)
                                                </span>
                                            </div>
                                            <Button
                                                size="sm"
                                                :disabled="!newComment.trim()"
                                                :loading="isSubmittingComment"
                                                :class="
                                                    isInternalNote
                                                        ? 'bg-amber-500 hover:bg-amber-600'
                                                        : ''
                                                "
                                                @click="submitComment"
                                            >
                                                <Lock
                                                    v-if="isInternalNote"
                                                    class="h-4 w-4"
                                                />
                                                <Send v-else class="h-4 w-4" />
                                                {{
                                                    isInternalNote
                                                        ? "Add Note"
                                                        : "Send"
                                                }}
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </Card>
                    </div>

                    <!-- Activity Tab -->
                    <div v-show="activeTab === 'activity'" class="space-y-6">
                        <Card padding="lg">
                            <h2
                                class="text-lg font-semibold text-[var(--text-primary)] mb-4"
                            >
                                Activity Timeline
                            </h2>
                            <div
                                v-if="isLoadingActivities"
                                class="py-8 flex justify-center"
                            >
                                <Loader2
                                    class="h-6 w-6 animate-spin text-[var(--text-muted)]"
                                />
                            </div>
                            <div
                                v-else-if="activities.length === 0"
                                class="py-8 text-center text-[var(--text-muted)]"
                            >
                                <Clock
                                    class="h-10 w-10 mx-auto mb-3 opacity-50"
                                />
                                <p>No activity recorded yet.</p>
                            </div>
                            <div v-else class="relative">
                                <!-- Timeline line -->
                                <div
                                    class="absolute left-5 top-0 bottom-0 w-0.5 bg-[var(--border-default)]"
                                ></div>

                                <div class="space-y-6">
                                    <div
                                        v-for="activity in activities"
                                        :key="activity.id"
                                        class="relative pl-12"
                                    >
                                        <!-- Timeline dot with icon -->
                                        <div
                                            class="absolute left-0 top-0 h-10 w-10 rounded-full flex items-center justify-center border-2 border-[var(--surface-primary)]"
                                            :class="
                                                getActivityIconStyle(
                                                    activity.action,
                                                )
                                            "
                                        >
                                            <component
                                                :is="
                                                    getActivityIcon(
                                                        activity.action_icon ||
                                                            activity.action,
                                                    )
                                                "
                                                class="h-4 w-4"
                                            />
                                        </div>

                                        <!-- Activity Card -->
                                        <div
                                            class="bg-[var(--surface-secondary)] rounded-lg p-4 border border-[var(--border-default)] hover:border-[var(--border-strong)] transition-colors"
                                        >
                                            <!-- Header: User + Action + Time -->
                                            <div
                                                class="flex items-start justify-between gap-3 mb-2"
                                            >
                                                <div
                                                    class="flex items-center gap-2 flex-wrap"
                                                >
                                                    <Avatar
                                                        v-if="activity.user"
                                                        :src="
                                                            activity.user
                                                                .avatar_url
                                                        "
                                                        :fallback="
                                                            activity.user.name?.charAt(
                                                                0,
                                                            ) || '?'
                                                        "
                                                        size="xs"
                                                    />
                                                    <span
                                                        class="font-semibold text-[var(--text-primary)] text-sm"
                                                    >
                                                        {{
                                                            activity.user
                                                                ?.name ||
                                                            activity.user_name ||
                                                            "System"
                                                        }}
                                                    </span>
                                                    <span
                                                        class="text-[var(--text-secondary)] text-sm"
                                                    >
                                                        {{
                                                            getActivityActionText(
                                                                activity,
                                                            )
                                                        }}
                                                    </span>
                                                </div>
                                                <span
                                                    class="text-xs text-[var(--text-muted)] whitespace-nowrap"
                                                >
                                                    {{
                                                        formatRelativeTime(
                                                            activity.created_at,
                                                        )
                                                    }}
                                                </span>
                                            </div>

                                            <!-- Reason (if provided) -->
                                            <div
                                                v-if="activity.metadata?.reason"
                                                class="mt-3 pl-3 border-l-2 border-[var(--border-default)] text-sm text-[var(--text-secondary)] italic"
                                            >
                                                "{{ activity.metadata.reason }}"
                                            </div>

                                            <!-- Changes (if any) -->
                                            <div
                                                v-if="
                                                    activity.changes &&
                                                    hasVisibleChanges(
                                                        activity.changes,
                                                    )
                                                "
                                                class="mt-3 space-y-2"
                                            >
                                                <div
                                                    v-for="(
                                                        newVal, key
                                                    ) in activity.changes.new ||
                                                    {}"
                                                    :key="key"
                                                    v-show="
                                                        !isHiddenChangeKey(key)
                                                    "
                                                    class="flex items-start gap-2 text-sm"
                                                >
                                                    <span
                                                        class="text-[var(--text-muted)] font-medium capitalize shrink-0"
                                                    >
                                                        {{
                                                            formatChangeKey(
                                                                key,
                                                            )
                                                        }}:
                                                    </span>
                                                    <div
                                                        class="flex items-center gap-2 flex-wrap min-w-0"
                                                    >
                                                        <span
                                                            v-if="
                                                                activity.changes
                                                                    .old &&
                                                                activity.changes
                                                                    .old[
                                                                    key
                                                                ] !== undefined
                                                            "
                                                            class="px-2 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 line-through text-xs"
                                                        >
                                                            {{
                                                                formatChangeValue(
                                                                    activity
                                                                        .changes
                                                                        .old[
                                                                        key
                                                                    ],
                                                                )
                                                            }}
                                                        </span>
                                                        <ArrowUpRight
                                                            v-if="
                                                                activity.changes
                                                                    .old &&
                                                                activity.changes
                                                                    .old[
                                                                    key
                                                                ] !== undefined
                                                            "
                                                            class="h-3 w-3 text-[var(--text-muted)]"
                                                        />
                                                        <span
                                                            class="px-2 py-0.5 rounded bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 text-xs"
                                                        >
                                                            {{
                                                                formatChangeValue(
                                                                    newVal,
                                                                )
                                                            }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Attachments (if any) -->
                                            <div
                                                v-if="
                                                    activity.metadata
                                                        ?.attachment_names
                                                        ?.length
                                                "
                                                class="mt-3"
                                            >
                                                <div
                                                    class="flex flex-wrap gap-1.5"
                                                >
                                                    <span
                                                        v-for="name in activity
                                                            .metadata
                                                            .attachment_names"
                                                        :key="name"
                                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-xs font-medium bg-[var(--surface-tertiary)] text-[var(--text-secondary)] border border-[var(--border-default)]"
                                                    >
                                                        <Paperclip
                                                            class="w-3 h-3"
                                                        />
                                                        {{ name }}
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Comment excerpt (if any) -->
                                            <div
                                                v-if="
                                                    activity.metadata?.excerpt
                                                "
                                                class="mt-3 p-3 bg-[var(--surface-tertiary)] rounded-md text-sm text-[var(--text-secondary)]"
                                            >
                                                <MessageSquare
                                                    class="h-3 w-3 inline mr-1 opacity-50"
                                                />
                                                {{ activity.metadata.excerpt }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </Card>
                    </div>

                    <!-- Attachments Tab -->
                    <div v-show="activeTab === 'attachments'" class="h-[600px]">
                        <MediaManager
                            :items="attachments"
                            :loading="isLoadingAttachments"
                            :items-per-page="50"
                            :can-upload="canManageAttachments"
                            :can-delete="canManageAttachments"
                            :uploading="isUploadingFile"
                            :upload-queue="uploadQueue"
                            @upload="uploadAttachment"
                            @delete="openDeleteAttachmentModal"
                            @process-queue="processUploadQueue"
                            @remove-upload="removeFileFromQueue"
                        />
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="lg:col-span-1 space-y-6">
                    <!-- Details -->
                    <Card padding="lg">
                        <h2
                            class="text-lg font-semibold text-[var(--text-primary)] mb-4"
                        >
                            Details
                        </h2>
                        <div class="space-y-4">
                            <!-- Priority -->
                            <div class="flex items-center justify-between">
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Priority</span
                                >
                                <div class="flex items-center gap-2">
                                    <div
                                        :class="[
                                            'flex h-6 w-6 items-center justify-center rounded-md',
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
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                    >
                                        {{
                                            getPriorityConfig(ticket.priority)
                                                .label
                                        }}
                                    </span>
                                </div>
                            </div>

                            <!-- Assignee -->
                            <div class="flex items-center justify-between">
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Assignee</span
                                >
                                <div
                                    v-if="ticket.assignee"
                                    class="flex items-center gap-2"
                                >
                                    <Avatar
                                        :fallback="ticket.assignee.initials"
                                        :src="ticket.assignee.avatarUrl"
                                        size="xs"
                                    />
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                    >
                                        {{ ticket.assignee.name }}
                                    </span>
                                </div>
                                <Button
                                    v-else-if="can('tickets.assign')"
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 px-2"
                                    @click="openAssignModal"
                                >
                                    <UserPlus class="h-3.5 w-3.5" />
                                    Assign
                                </Button>
                            </div>

                            <!-- Reporter -->
                            <div class="flex items-center justify-between">
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Reporter</span
                                >
                                <div class="flex items-center gap-2">
                                    <Avatar
                                        :fallback="ticket.reporter.initials"
                                        :src="ticket.reporter?.avatarUrl"
                                        size="xs"
                                    />
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                    >
                                        {{ ticket.reporter?.name || "Unknown" }}
                                    </span>
                                </div>
                            </div>

                            <!-- Other Tickets by This User -->
                            <div
                                v-if="
                                    reporterTickets.length > 0 ||
                                    isLoadingReporterTickets
                                "
                                class="flex flex-col gap-2 pt-4 border-t border-[var(--border-default)]"
                            >
                                <div class="flex items-center justify-between">
                                    <span
                                        class="text-sm font-medium text-[var(--text-primary)]"
                                    >
                                        Other Tickets by This User
                                    </span>
                                    <Badge
                                        v-if="!isLoadingReporterTickets"
                                        variant="secondary"
                                        size="xs"
                                    >
                                        {{ reporterTickets.length }}
                                    </Badge>
                                </div>

                                <div
                                    v-if="isLoadingReporterTickets"
                                    class="flex justify-center py-2"
                                >
                                    <Loader2
                                        class="h-4 w-4 animate-spin text-[var(--text-muted)]"
                                    />
                                </div>

                                <div v-else class="space-y-2">
                                    <router-link
                                        v-for="rTicket in reporterTickets"
                                        :key="rTicket.id"
                                        :to="`/tickets/${rTicket.id}`"
                                        class="block p-2 rounded-lg bg-[var(--surface-secondary)] hover:bg-[var(--surface-tertiary)] transition-colors"
                                    >
                                        <div
                                            class="flex items-center justify-between mb-1"
                                        >
                                            <span
                                                class="text-xs text-[var(--text-muted)] font-mono"
                                            >
                                                {{ rTicket.displayId }}
                                            </span>
                                            <Badge
                                                :variant="
                                                    getStatusConfig(
                                                        rTicket.status?.value ||
                                                            'open',
                                                    ).variant
                                                "
                                                size="xs"
                                            >
                                                {{
                                                    rTicket.status?.label ||
                                                    "Open"
                                                }}
                                            </Badge>
                                        </div>
                                        <p
                                            class="text-sm text-[var(--text-primary)] line-clamp-1"
                                        >
                                            {{ rTicket.title }}
                                        </p>
                                    </router-link>
                                </div>

                                <!-- View All Link -->
                                <router-link
                                    v-if="
                                        ticket.reporter &&
                                        reporterTickets.length > 0
                                    "
                                    :to="`/tickets?reporter_id=${ticket.reporter.id}`"
                                    class="text-xs text-[var(--interactive-primary)] hover:underline mt-2 inline-block"
                                >
                                    View all tickets by this user →
                                </router-link>
                            </div>

                            <!-- Hierarchy -->
                            <div
                                class="flex flex-col gap-2 pt-4 border-t border-[var(--border-default)]"
                            >
                                <span
                                    class="text-sm font-medium text-[var(--text-primary)]"
                                    >Hierarchy</span
                                >

                                <!-- Parent -->
                                <div
                                    v-if="ticket.parent"
                                    class="bg-[var(--surface-secondary)] rounded-md p-2"
                                >
                                    <div
                                        class="text-xs text-[var(--text-muted)] mb-1"
                                    >
                                        Parent Ticket
                                    </div>
                                    <router-link
                                        :to="`/tickets/${ticket.parent.id}`"
                                        class="block font-medium text-sm text-[var(--text-primary)] hover:text-[var(--interactive-primary)] truncate"
                                    >
                                        #{{ ticket.parent.id.substr(0, 8) }}
                                        {{ ticket.parent.title }}
                                    </router-link>
                                    <div class="mt-1">
                                        <Badge
                                            :color="ticket.parent.status.color"
                                            size="sm"
                                            >{{
                                                ticket.parent.status.label
                                            }}</Badge
                                        >
                                    </div>
                                </div>
                                <div
                                    v-else-if="
                                        !ticket.children ||
                                        ticket.children.length === 0
                                    "
                                    class="text-sm text-[var(--text-secondary)]"
                                >
                                    <button
                                        v-if="can('tickets.update')"
                                        @click="openLinkModal"
                                        class="text-[var(--interactive-primary)] hover:underline flex items-center gap-1"
                                        :disabled="ticket.isLocked"
                                    >
                                        <LinkIcon class="w-3 h-3" /> Link to
                                        Master
                                    </button>
                                </div>

                                <!-- Children -->
                                <div
                                    v-if="
                                        ticket.children &&
                                        ticket.children.length > 0
                                    "
                                    class="space-y-2"
                                >
                                    <div
                                        class="text-xs text-[var(--text-muted)]"
                                    >
                                        Child Tickets ({{
                                            ticket.children.length
                                        }})
                                    </div>
                                    <div
                                        v-for="child in ticket.children"
                                        :key="child.id"
                                        class="bg-[var(--surface-secondary)] rounded-md p-2"
                                    >
                                        <router-link
                                            :to="`/tickets/${child.public_id}`"
                                            class="block font-medium text-sm text-[var(--text-primary)] hover:text-[var(--interactive-primary)] truncate"
                                        >
                                            #{{ child.public_id.substr(0, 8) }}
                                            {{ child.title }}
                                        </router-link>
                                        <div
                                            class="mt-1 flex items-center justify-between"
                                        >
                                            <Badge
                                                :color="child.status.color"
                                                size="sm"
                                                >{{ child.status.label }}</Badge
                                            >
                                            <button
                                                v-if="can('tickets.update')"
                                                @click="unlinkChild(child)"
                                                class="text-[var(--text-muted)] hover:text-red-500"
                                                title="Unlink"
                                            >
                                                <UnlinkIcon class="w-3 h-3" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Due Date -->
                            <div
                                v-if="ticket.dueDate"
                                class="flex items-center justify-between"
                            >
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Due Date</span
                                >
                                <span
                                    :class="[
                                        'text-sm',
                                        ticket.isOverdue
                                            ? 'text-red-600 dark:text-red-400 font-medium'
                                            : 'text-[var(--text-primary)]',
                                    ]"
                                >
                                    {{ formatDate(ticket.dueDate) }}
                                </span>
                            </div>

                            <!-- Created -->
                            <div class="flex items-center justify-between">
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Created</span
                                >
                                <span
                                    class="text-sm text-[var(--text-primary)]"
                                >
                                    {{ formatDate(ticket.createdAt) }}
                                </span>
                            </div>

                            <!-- Updated -->
                            <div class="flex items-center justify-between">
                                <span
                                    class="text-sm text-[var(--text-secondary)]"
                                    >Updated</span
                                >
                                <span
                                    class="text-sm text-[var(--text-primary)]"
                                >
                                    {{ formatDate(ticket.updatedAt) }}
                                </span>
                            </div>
                        </div>
                    </Card>

                    <!-- Tags -->
                    <Card padding="lg" v-if="can('tickets.update')">
                        <div class="flex items-center justify-between mb-4">
                            <h2
                                class="text-lg font-semibold text-[var(--text-primary)]"
                            >
                                Tags
                            </h2>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="h-7 w-7"
                                @click="showTagInput = !showTagInput"
                                title="Add Tag"
                                :disabled="isSavingTag"
                            >
                                <Plus class="h-4 w-4" />
                            </Button>
                        </div>

                        <!-- Inline Tag Input -->
                        <div v-if="showTagInput" class="mb-3">
                            <div class="flex flex-col sm:flex-row gap-2">
                                <input
                                    v-model="newTagValue"
                                    type="text"
                                    class="w-full px-3 py-1.5 text-sm rounded-lg border border-[var(--border-default)] bg-[var(--surface-primary)] text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/50"
                                    placeholder="Enter tag..."
                                    @keyup.enter="addTag"
                                    @keyup.escape="
                                        showTagInput = false;
                                        newTagValue = '';
                                    "
                                />
                                <Button
                                    size="sm"
                                    class="w-full sm:w-auto shrink-0"
                                    @click="addTag"
                                    :loading="isSavingTag"
                                    :disabled="!newTagValue.trim()"
                                >
                                    Add
                                </Button>
                            </div>
                        </div>

                        <div
                            v-if="ticket.tags.length"
                            class="flex flex-wrap gap-2"
                        >
                            <span
                                v-for="tag in ticket.tags"
                                :key="tag"
                                class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium rounded-lg bg-[var(--surface-tertiary)] text-[var(--text-secondary)] group"
                            >
                                {{ tag }}
                                <button
                                    @click="removeTag(tag)"
                                    class="opacity-0 group-hover:opacity-100 hover:text-[var(--color-error)] transition-all"
                                    :disabled="isSavingTag"
                                >
                                    <X class="h-3 w-3" />
                                </button>
                            </span>
                        </div>
                        <p
                            v-else-if="!showTagInput"
                            class="text-sm text-[var(--text-muted)]"
                        >
                            No tags
                        </p>
                    </Card>

                    <!-- Followers -->
                    <Card padding="lg" v-if="can('tickets.update')">
                        <h2
                            class="text-lg font-semibold text-[var(--text-primary)] mb-4"
                        >
                            Followers
                        </h2>
                        <div
                            v-if="
                                ticket.followers && ticket.followers.length > 0
                            "
                            class="flex flex-wrap gap -1"
                        >
                            <div
                                v-for="follower in ticket.followers"
                                :key="follower.id"
                                class="group relative -ml-2 first:ml-0 transition-all hover:z-10 hover:-translate-y-1"
                            >
                                <Avatar
                                    :src="follower.avatar_url"
                                    :fallback="follower.initials"
                                    size="sm"
                                    class="ring-2 ring-[var(--surface-primary)] cursor-help"
                                />
                                <div
                                    class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 px-2 py-1 text-xs font-medium text-white bg-gray-900 rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap z-20 pointer-events-none shadow-lg"
                                >
                                    {{ follower.name }}
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-sm text-[var(--text-muted)]">
                            No followers
                        </p>
                    </Card>
                </div>
            </div>
        </div>

        <!-- Media Viewer -->
        <MediaViewer />

        <!-- Edit Ticket Modal -->
        <Modal
            :open="showEditModal"
            @update:open="showEditModal = $event"
            size="lg"
            title="Edit Ticket"
            description="Update ticket details and status"
        >
            <div class="space-y-4">
                <!-- Title -->
                <div class="space-y-1.5">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                        >Title</label
                    >
                    <Input
                        v-model="editForm.title"
                        placeholder="Ticket title"
                    />
                    <p v-if="editErrors.title" class="text-sm text-red-500">
                        {{ editErrors.title[0] }}
                    </p>
                </div>

                <!-- Description -->
                <div class="space-y-1.5">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                        >Description</label
                    >
                    <RichTextEditor
                        v-model="editForm.description"
                        placeholder="Describe the issue or task..."
                    />
                </div>

                <!-- Priority & Type -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-primary)]"
                            >Priority</label
                        >
                        <select
                            v-model="editForm.priority"
                            class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                        >
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="critical">Critical</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <label
                            class="text-sm font-medium text-[var(--text-primary)]"
                            >Type</label
                        >
                        <select
                            v-model="editForm.type"
                            class="w-full px-3 py-2 text-sm bg-[var(--surface-primary)] border border-[var(--border-default)] rounded-lg text-[var(--text-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                        >
                            <option value="bug">Bug</option>
                            <option value="feature">Feature</option>
                            <option value="task">Task</option>
                            <option value="question">Question</option>
                            <option value="improvement">Improvement</option>
                            <option value="incident">Incident</option>
                            <option value="accounting">Accounting</option>
                        </select>
                    </div>
                </div>

                <!-- Tags -->
                <div class="space-y-1.5">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                        >Tags</label
                    >
                    <TagInput
                        v-model="editForm.tags"
                        placeholder="Add tags..."
                    />
                </div>

                <!-- Reason (Required) -->
                <div
                    class="space-y-1.5 border-t border-[var(--border-default)] pt-4"
                >
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                    >
                        Reason for changes <span class="text-red-500">*</span>
                    </label>
                    <Textarea
                        v-model="editForm.reason"
                        placeholder="Explain why you are making these changes (min 3 characters)..."
                        rows="3"
                    />
                    <p v-if="editErrors.reason" class="text-sm text-red-500">
                        {{ editErrors.reason[0] || editErrors.reason }}
                    </p>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-3">
                    <Button
                        variant="outline"
                        @click="showEditModal = false"
                        :disabled="isSubmittingEdit"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        @click="submitEdit"
                        :loading="isSubmittingEdit"
                        :disabled="
                            !editForm.reason || editForm.reason.length < 3
                        "
                    >
                        Save Changes
                    </Button>
                </div>
            </template>
        </Modal>

        <!-- Assign Ticket Modal -->
        <Modal
            :open="showAssignModal"
            @update:open="showAssignModal = $event"
            size="sm"
            title="Assign Ticket"
            description="Select a team member to assign this ticket to."
        >
            <div class="space-y-4 py-2">
                <div class="space-y-1.5">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                        >Assignee</label
                    >
                    <ComboBox
                        v-model="selectedAssignee"
                        :options="assignableUsers"
                        :loading="isLoadingUsers"
                        placeholder="Search for a user..."
                    />
                </div>
                <div class="flex justify-end gap-3 pt-4">
                    <Button
                        variant="outline"
                        @click="showAssignModal = false"
                        :disabled="isAssigning"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="primary"
                        @click="submitAssign"
                        :loading="isAssigning"
                    >
                        Assign Ticket
                    </Button>
                </div>
            </div>
        </Modal>

        <!-- Link Ticket Modal -->
        <Modal
            :open="showLinkModal"
            @update:open="showLinkModal = $event"
            size="sm"
            title="Link to Master Ticket"
            description="Select a master ticket to link this ticket to. This ticket will become read-only."
        >
            <div class="space-y-4 py-2">
                <div class="space-y-1.5">
                    <label
                        class="text-sm font-medium text-[var(--text-primary)]"
                        >Master Ticket</label
                    >
                    <ComboBox
                        v-model="linkForm.parentId"
                        :options="linkableTickets"
                        :loading="isLoadingLinkable"
                        placeholder="Search for a ticket..."
                        search-placeholder="Search tickets..."
                        @search="handleLinkSearch"
                    />
                    <p class="text-xs text-[var(--text-secondary)]">
                        Search by ID (e.g., INC-123) or title.
                    </p>
                    <p v-if="linkErrors.parentId" class="text-sm text-red-500">
                        {{ linkErrors.parentId[0] || linkErrors.parentId }}
                    </p>
                </div>
                <div class="flex justify-end gap-3 pt-4">
                    <Button
                        variant="outline"
                        @click="showLinkModal = false"
                        :disabled="isLinking"
                        >Cancel</Button
                    >
                    <Button
                        variant="primary"
                        @click="submitLink"
                        :loading="isLinking"
                        >Link Ticket</Button
                    >
                </div>
            </div>
        </Modal>

        <!-- Archive Ticket Modal -->
        <Modal
            :open="showArchiveModal"
            @update:open="showArchiveModal = $event"
            title="Archive Ticket"
            description="Are you sure you want to archive this ticket?"
        >
            <div class="space-y-4 py-2">
                <Alert variant="warning">
                    <div class="flex gap-2">
                        <AlertTriangle class="w-4 h-4 mt-0.5" />
                        <div class="text-sm">
                            Archived tickets are
                            <strong>locked permanently</strong> and cannot be
                            reopened.
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
                        v-model="archiveForm.reason"
                        placeholder="Explain why this ticket is being archived..."
                        rows="3"
                    />
                    <p v-if="archiveErrors.reason" class="text-sm text-red-500">
                        {{ archiveErrors.reason[0] || archiveErrors.reason }}
                    </p>
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <Button
                        variant="outline"
                        @click="showArchiveModal = false"
                        :disabled="isArchiving"
                        >Cancel</Button
                    >
                    <Button
                        variant="danger"
                        @click="submitArchive"
                        :loading="isArchiving"
                        >Confirm Archive</Button
                    >
                </div>
            </div>
        </Modal>

        <!-- Delete Modal -->
        <Modal
            :open="showDeleteModal"
            @update:open="showDeleteModal = $event"
            size="sm"
            title="Delete Ticket"
            description="Are you sure? This action cannot be undone."
        >
            <div class="space-y-4">
                <div
                    class="p-3 bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 rounded-lg text-sm"
                >
                    <p class="font-medium">Warning</p>
                    <p>
                        This will permanently delete the ticket and all
                        associated data.
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

        <!-- Delete Attachment Modal -->
        <Modal
            :open="showDeleteAttachmentModal"
            @update:open="showDeleteAttachmentModal = $event"
            size="sm"
            title="Delete Attachment"
            description="Are you sure you want to delete this attachment?"
        >
            <div class="space-y-4">
                <div
                    class="p-3 bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 rounded-lg text-sm"
                >
                    <p class="font-medium">Warning</p>
                    <p>
                        This action cannot be undone. The file will be
                        permanently removed.
                    </p>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <Button
                        variant="ghost"
                        @click="showDeleteAttachmentModal = false"
                        :disabled="isDeletingAttachment"
                        >Cancel</Button
                    >
                    <Button
                        variant="danger"
                        @click="confirmDeleteAttachment"
                        :loading="isDeletingAttachment"
                    >
                        Delete Attachment
                    </Button>
                </div>
            </div>
        </Modal>
    </div>
</template>
