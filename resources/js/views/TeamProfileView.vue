<script setup>
import { ref, onMounted, computed, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import {
    Users,
    Folder,
    Calendar,
    LayoutTemplate,
    Briefcase,
    ArrowRight,
    Building2,
    Plus,
    Search,
    MoreVertical,
    Trash2,
    FileText,
    Image as ImageIcon,
    Upload,
    Mail,
    X,
    UploadCloud,
    Download,
    Info,
    ChevronLeft,
    ChevronRight,
    Maximize2,
    History,
    Shield,
} from "lucide-vue-next";
import AppLayout from "@/layouts/AppLayout.vue";
import Avatar from "@/components/ui/Avatar.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Input from "@/components/ui/Input.vue";
import Modal from "@/components/ui/Modal.vue";
import StatusBadge from "@/components/ui/StatusBadge.vue";
import { format } from "date-fns";
import { useToast } from "@/composables/useToast.ts";
import { useAuthStore } from "@/stores/auth";
import MediaManager from "@/components/tools/MediaManager.vue";
import TeamCalendar from "@/components/tools/TeamCalendar.vue";
import TeamEventModal from "@/components/modals/TeamEventModal.vue";
import TeamProjectsTab from "@/components/teams/TeamProjectsTab.vue";

const route = useRoute();
const router = useRouter();
const { toast } = useToast();
const authStore = useAuthStore();

const team = ref(null);
const loading = ref(true);
const activeTab = ref(route.query.tab?.toString() || "overview");

// Delete Modal State
const deleteModalOpen = ref(false);
const deleteTarget = ref({ type: "single", ids: [] });

// Activity / Audit Trail
const activityLogs = ref([]);
const activityLoading = ref(false);
const activityPage = ref(1);
const activityTotal = ref(0);
const activityPerPage = ref(20);

// Members
const members = ref([]);
const membersLoading = ref(false);
const memberSearch = ref("");
const memberPage = ref(1);

// Calendar
const calendarEvents = ref([]);
const calendarLoading = ref(false);
const showEventModal = ref(false);
const selectedEvent = ref(null);
const calendarRange = ref({ start: null, end: null });

const isTeamAdmin = computed(() => {
    if (!team.value) return false;
    if (authStore.user?.public_id === team.value.owner.public_id) return true;

    const membership = members.value.find(
        (m) => m.public_id === authStore.user?.public_id,
    );
    return membership?.pivot?.role === "admin";
});

async function fetchCalendarEvents(start, end) {
    if (!team.value) return;
    calendarLoading.value = true;
    calendarRange.value = { start, end };

    try {
        const response = await axios.get(
            `/api/teams/${team.value.public_id}/calendar`,
            {
                params: {
                    start: start.toISOString(),
                    end: end.toISOString(),
                },
            },
        );
        calendarEvents.value = response.data.data;
    } catch (error) {
        toast({
            title: "Error",
            description: "Failed to load calendar events",
            variant: "destructive",
        });
    } finally {
        calendarLoading.value = false;
    }
}

function handleCalendarDatesSet({ start, end }) {
    fetchCalendarEvents(start, end);
}

function handleCreateEventClick() {
    selectedEvent.value = null;
    showEventModal.value = true;
}

function handleEventClick(info) {
    const props = info.event.extendedProps;
    if (props.type === "event" && props.can_edit) {
        selectedEvent.value = {
            id: info.event.id,
            title: info.event.title,
            start_time: info.event.startStr,
            end_time: info.event.endStr,
            is_all_day: info.event.allDay,
            color: info.event.backgroundColor,
            location: props.location,
            description: props.description,
        };
        showEventModal.value = true;
    } else if (props.type === "project") {
        router.push({
            name: "admin-project-detail",
            params: { id: props.project_id },
        });
    }
}

const avatarInput = ref(null);
const isUploadingAvatar = ref(false);

function triggerAvatarUpload() {
    avatarInput.value.click();
}

async function handleAvatarUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
        toast.error("Avatar must be less than 2MB");
        return;
    }

    isUploadingAvatar.value = true;
    const formData = new FormData();
    formData.append("avatar", file);

    try {
        const response = await axios.post(
            `/api/teams/${team.value.public_id}/avatar`,
            formData,
            {
                headers: { "Content-Type": "multipart/form-data" },
            },
        );
        team.value = response.data;
        toast.success("Team avatar updated");
    } catch (error) {
        console.error("Failed to upload avatar:", error);
        toast.error("Failed to update avatar");
    } finally {
        isUploadingAvatar.value = false;
        event.target.value = ""; // Reset input
    }
}

async function removeAvatar() {
    if (!team.value) return;

    deleteTarget.value = { type: "avatar" };
    deleteModalOpen.value = true;
}

function handleExportEvents(range) {
    if (!range?.start || !range?.end) {
        toast.error("Please select a date range first");
        return;
    }

    const params = new URLSearchParams({
        start: format(range.start, "yyyy-MM-dd"),
        end: format(range.end, "yyyy-MM-dd"),
    });

    // Trigger download by opening URL
    window.open(
        `/api/teams/${team.value.public_id}/events/export?${params.toString()}`,
        "_blank",
    );
}

async function handleSaveEvent(eventData) {
    try {
        if (eventData.id) {
            await axios.put(
                `/api/teams/${team.value.public_id}/events/${eventData.id}`,
                eventData,
            );
            toast.success("Success", {
                description: "Event updated successfully",
            });
        } else {
            await axios.post(
                `/api/teams/${team.value.public_id}/events`,
                eventData,
            );
            toast.success("Success", {
                description: "Event created successfully",
            });
        }
        showEventModal.value = false;
        if (calendarRange.value.start) {
            fetchCalendarEvents(
                calendarRange.value.start,
                calendarRange.value.end,
            );
        }
    } catch (error) {
        toast.error("Error", {
            description: "Failed to save event",
        });
    }
}

async function handleDeleteEvent(eventId) {
    try {
        await axios.delete(
            `/api/teams/${team.value.public_id}/events/${eventId}`,
        );
        toast({ title: "Success", description: "Event deleted successfully" });
        showEventModal.value = false;
        if (calendarRange.value.start) {
            fetchCalendarEvents(
                calendarRange.value.start,
                calendarRange.value.end,
            );
        }
    } catch (error) {
        toast({
            title: "Error",
            description: "Failed to delete event",
            variant: "destructive",
        });
    }
}
const memberTotal = ref(0);
const perPage = ref(10);
const roleFilter = ref("");

const files = ref([]);
const filesLoading = ref(false);
const isUploading = ref(false);
const uploadQueue = ref([]);

// File Pagination & Filters
const filePage = ref(1);
const filePerPage = ref(10);
const fileSearch = ref("");
const fileFilters = ref({});

const filteredFiles = computed(() => {
    let result = files.value;

    if (fileSearch.value) {
        const q = fileSearch.value.toLowerCase();
        result = result.filter((f) => f.name.toLowerCase().includes(q));
    }

    if (fileFilters.value.type) {
        if (fileFilters.value.type === "image") {
            result = result.filter((f) => f.mime_type.startsWith("image/"));
        } else if (fileFilters.value.type === "video") {
            result = result.filter((f) => f.mime_type.startsWith("video/"));
        } else if (fileFilters.value.type === "document") {
            result = result.filter(
                (f) =>
                    !f.mime_type.startsWith("image/") &&
                    !f.mime_type.startsWith("video/"),
            );
        }
    }
    return result;
});

const paginatedFiles = computed(() => {
    const start = (filePage.value - 1) * filePerPage.value;
    const end = start + filePerPage.value;
    return filteredFiles.value.slice(start, end);
});

const isOwner = computed(() => {
    return (
        authStore.user?.public_id === team.value?.owner?.public_id ||
        members.value.find((m) => m.public_id === authStore.user?.public_id)
            ?.pivot?.role === "owner"
    );
});

// Invite
const showInviteModal = ref(false);
const inviteEmail = ref("");
const inviteRole = ref("member");
const inviteLoading = ref(false);

const inviteError = ref("");
const pendingInvites = ref([]);
const pendingInvitesLoading = ref(false);

// Projects
const projects = ref([]);
const projectsLoading = ref(false);

const fetchProjects = async () => {
    if (!team.value) return;
    projectsLoading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${team.value.public_id}/projects`,
        );
        projects.value = response.data.data;
    } catch (error) {
        console.error("Error fetching projects:", error);
    } finally {
        projectsLoading.value = false;
    }
};

// Clients
const clients = ref([]);
const clientsLoading = ref(false);

const fetchClients = async () => {
    clientsLoading.value = true;
    try {
        const response = await axios.get("/api/clients");
        clients.value = response.data.data;
    } catch (error) {
        console.error("Error fetching clients:", error);
    } finally {
        clientsLoading.value = false;
    }
};

// Fetch Team Data
const fetchTeam = async () => {
    loading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${route.params.public_id}`,
        );
        team.value = response.data;
        await Promise.all([
            fetchMembers(),
            fetchFiles(),
            fetchProjects(),
            fetchClients(),
        ]);
        // If activity tab is active, fetch activity
        if (activeTab.value === "activity") {
            fetchActivity();
        }
    } catch (error) {
        console.error("Error fetching team:", error);
        toast.error("Failed to load team data");
        router.push({ name: "dashboard" });
    } finally {
        loading.value = false;
    }
};

// Fetch Members
const fetchMembers = async () => {
    if (!team.value) return;
    membersLoading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${team.value.public_id}/members`,
            {
                params: {
                    page: memberPage.value,
                    search: memberSearch.value,
                    per_page: perPage.value,
                    role: roleFilter.value,
                },
            },
        );
        members.value = response.data.data;
        memberTotal.value = response.data.total;
    } catch (error) {
        console.error("Error fetching members:", error);
        toast.error("Failed to load members");
    } finally {
        membersLoading.value = false;
    }
};

// Fetch Files
const fetchFiles = async () => {
    if (!team.value) return;
    filesLoading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${team.value.public_id}/files`,
        );
        files.value = response.data;
    } catch (error) {
        console.error("Error fetching files:", error);
        toast.error("Failed to load files");
    } finally {
        filesLoading.value = false;
    }
};

const fetchPendingInvites = async () => {
    // Only if admin/owner
    if (!team.value) return;
    pendingInvitesLoading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${team.value.public_id}/invites`,
        );
        pendingInvites.value = response.data;
    } catch (error) {
        console.error("Error fetching invites:", error);
    } finally {
        pendingInvitesLoading.value = false;
    }
};

// Fetch Activity / Audit Trail
const fetchActivity = async () => {
    if (!team.value) return;
    activityLoading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${team.value.public_id}/activity`,
            {
                params: {
                    page: activityPage.value,
                    per_page: activityPerPage.value,
                },
            },
        );
        activityLogs.value = response.data.data || response.data;
        activityTotal.value = response.data.total || response.data.length;
    } catch (error) {
        console.error("Error fetching activity:", error);
        toast.error("Failed to load activity");
    } finally {
        activityLoading.value = false;
    }
};

// Cancel Invite
const handleCancelInvite = async (inviteId) => {
    if (!confirm("Are you sure you want to cancel this invitation?")) return;
    try {
        await axios.delete(
            `/api/teams/${team.value.public_id}/invites/${inviteId}`,
        );
        toast.success("Invitation cancelled");
        fetchPendingInvites();
    } catch (error) {
        console.error("Error cancelling invite:", error);
        toast.error("Failed to cancel invitation");
    }
};

// Invite Member
const handleInvite = async () => {
    inviteLoading.value = true;
    inviteError.value = "";
    try {
        await axios.post(`/api/teams/${team.value.public_id}/invite`, {
            email: inviteEmail.value,
            role: inviteRole.value,
        });
        toast.success("Member invited successfully");
        showInviteModal.value = false;
        inviteEmail.value = "";
        fetchPendingInvites();
        fetchMembers();
    } catch (error) {
        console.error("Error inviting member:", error);
        inviteError.value =
            error.response?.data?.message || "Failed to invite member";
    } finally {
        inviteLoading.value = false;
    }
};

// Remove Member
const handleRemoveMember = async (user) => {
    if (!confirm(`Are you sure you want to remove ${user.name} from the team?`))
        return;
    try {
        await axios.delete(
            `/api/teams/${team.value.public_id}/members/${user.public_id}`,
        );
        toast.success("Member removed successfully");
        fetchMembers();
    } catch (error) {
        console.error("Error removing member:", error);
        toast.error("Failed to remove member");
    }
};

// Update Member Role
const handleUpdateRole = async (member, newRole) => {
    try {
        await axios.put(
            `/api/teams/${team.value.public_id}/members/${member.public_id}/role`,
            {
                role: newRole,
            },
        );
        toast.success("Member role updated");
        // Update local state without full refetch for speed
        const mIndex = members.value.findIndex(
            (m) => m.public_id === member.public_id,
        );
        if (mIndex !== -1 && members.value[mIndex].pivot) {
            // Create a deep copy to avoid reactivity issues if needed, or just assign
            members.value[mIndex].pivot.role = newRole;
        }
    } catch (error) {
        console.error("Error updating role:", error);
        toast.error("Failed to update role");
        fetchMembers();
    }
};

// Upload File
const handleUpload = (files) => {
    addFilesToQueue(files);
};

const handleDownload = (file) => {
    const link = document.createElement("a");
    link.href = file.url;
    link.download = file.name;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
};

const handleViewMedia = ({ item, index, items }) => {
    // If the clicked item is not an image or video, do not open the viewer
    const isMedia =
        item.mime_type.startsWith("image/") ||
        item.mime_type.startsWith("video/");
    if (!isMedia) {
        // Optional: Open PDFs/other files in new tab or download
        if (item.mime_type === "application/pdf") {
            window.open(item.url, "_blank");
        }
        return;
    }

    // Filter list to only include images and videos
    const mediaItems = items.filter(
        (f) =>
            f.mime_type.startsWith("image/") ||
            f.mime_type.startsWith("video/"),
    );

    // Find the new index of the clicked item in the filtered list
    const newIndex = mediaItems.findIndex((f) => f.id === item.id);

    if (newIndex === -1) return;

    // Map to viewer format
    const viewerItems = mediaItems.map((f) => ({
        ...f,
        src: f.url,
        download: f.url,
        type: f.mime_type.startsWith("video/") ? "video" : "image",
        mimeType: f.mime_type,
        canDelete: isOwner.value,
    }));

    window.dispatchEvent(
        new CustomEvent("media-viewer:open", {
            detail: {
                media: viewerItems,
                index: newIndex,
            },
        }),
    );
};

const addFilesToQueue = (newFiles) => {
    // Constraint: Max 10 files selected at once
    if (newFiles.length > 10) {
        toast.error("You can only select up to 10 files at a time.");
        // Take first 10
        newFiles = newFiles.slice(0, 10);
    }

    const queueItems = newFiles.map((file) => ({
        id: Date.now() + Math.random().toString(36).substr(2, 9),
        file,
        progress: 0,
        status: "pending", // pending, uploading, completed, error
        error: null,
    }));

    uploadQueue.value.push(...queueItems);
};

const removeFileFromQueue = (index) => {
    uploadQueue.value.splice(index, 1);
};

const processUploadQueue = async () => {
    // Filter pending items
    const pendingItems = uploadQueue.value.filter(
        (item) => item.status === "pending",
    );

    if (pendingItems.length === 0) return;

    // Batch limit: Max 25 per upload batch
    const batch = pendingItems.slice(0, 25);

    isUploading.value = true;
    let successCount = 0;

    for (const item of batch) {
        item.status = "uploading";
        item.progress = 0;

        const formData = new FormData();
        formData.append("file", item.file);

        try {
            await axios.post(
                `/api/teams/${team.value.public_id}/files`,
                formData,
                {
                    headers: {
                        "Content-Type": "multipart/form-data",
                    },
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
            console.error(`Error uploading ${item.file.name}:`, error);
            item.status = "error";
            item.error = "Upload failed";
            toast.error(`Failed to upload ${item.file.name}`);
        }
    }

    // Refresh files if any success
    if (successCount > 0) {
        toast.success(`Uploaded ${successCount} files successfully`);
        fetchFiles();

        // Remove completed items from queue
        uploadQueue.value = uploadQueue.value.filter(
            (item) => item.status !== "completed",
        );
    }

    isUploading.value = uploadQueue.value.some((i) => i.status === "uploading");
};

// Bulk Download
const handleBulkDownload = async (mediaIds) => {
    if (mediaIds.length === 0) return;

    // Create form to submit request (for file download) or use window.open if GET?
    // POST request for download is tricky with axios if we want to trigger browser download.
    // Axios responseType: 'blob' works.

    try {
        const response = await axios.post(
            `/api/teams/${team.value.public_id}/files/bulk-download`,
            {
                media_ids: mediaIds,
            },
            {
                responseType: "blob",
            },
        );

        // Create download link
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement("a");
        link.href = url;
        link.setAttribute("download", `${team.value.slug || "team"}-files.zip`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);

        toast.success("Download started");
    } catch (error) {
        console.error("Error downloading files:", error);
        toast.error("Failed to download files");
    }
};

// Bulk Delete (Process)
const processBulkDelete = async (mediaIds) => {
    try {
        await axios.post(
            `/api/teams/${team.value.public_id}/files/bulk-delete`,
            {
                media_ids: mediaIds,
            },
        );
        toast.success("Files deleted successfully");
        fetchFiles();
    } catch (error) {
        console.error("Error deleting files:", error);
        toast.error("Failed to delete files");
    }
};

// Delete File (Process)
const processDeleteFile = async (mediaId) => {
    try {
        await axios.delete(
            `/api/teams/${team.value.public_id}/files/${mediaId}`,
        );
        toast.success("File deleted successfully");
        fetchFiles();
    } catch (error) {
        console.error("Error deleting file:", error);
        toast.error("Failed to delete file");
    }
};

// Modal Logic
const requestDeleteFile = (mediaId) => {
    deleteTarget.value = { type: "single", ids: [mediaId] };
    deleteModalOpen.value = true;
};

const requestBulkDelete = (mediaIds) => {
    deleteTarget.value = { type: "bulk", ids: mediaIds };
    deleteModalOpen.value = true;
};

const confirmDelete = async () => {
    deleteModalOpen.value = false;

    if (deleteTarget.value.type === "single") {
        if (deleteTarget.value.ids.length > 0) {
            await processDeleteFile(deleteTarget.value.ids[0]);
        }
    } else if (deleteTarget.value.type === "bulk") {
        await processBulkDelete(deleteTarget.value.ids);
    } else if (deleteTarget.value.type === "avatar") {
        try {
            const response = await axios.delete(
                `/api/teams/${team.value.public_id}/avatar`,
            );
            team.value = response.data;
            toast.success("Team avatar removed");
        } catch (error) {
            console.error("Failed to remove avatar:", error);
            toast.error("Failed to remove avatar");
        }
    }
};

// Watchers
watch([memberSearch, roleFilter, perPage], () => {
    memberPage.value = 1;
    fetchMembers();
});

watch(memberPage, () => {
    fetchMembers();
});

// Watch for activity tab
watch(activeTab, (newTab) => {
    if (newTab === "activity" && activityLogs.value.length === 0) {
        fetchActivity();
    }
});

watch(activityPage, () => {
    fetchActivity();
});

watch(activityPerPage, () => {
    activityPage.value = 1;
    fetchActivity();
});

onMounted(() => {
    fetchTeam();
});

watch(team, (newTeam) => {
    if (
        newTeam &&
        (authStore.user?.public_id === newTeam.owner?.public_id ||
            members.value.find((m) => m.public_id === authStore.user?.public_id)
                ?.pivot?.role === "admin")
    ) {
        fetchPendingInvites();
    }
});

const formatDate = (dateString) => {
    return format(new Date(dateString), "MMM d, yyyy");
};

const formatSize = (bytes) => {
    if (bytes === 0) return "0 B";
    const k = 1024;
    const sizes = ["B", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
};

const canRemoveMember = (member) => {
    // Cannot remove self
    if (member.id === authStore.user?.id) return false;

    // Cannot remove owner (use public_id comparison)
    if (member.public_id === team.value.owner.public_id) return false;

    // Owner can remove anyone (except self, handled above)
    if (authStore.user?.public_id === team.value.owner.public_id) return true;

    // Check if current user is admin
    const currentUserMember = members.value.find(
        (m) => m.id === authStore.user?.id,
    ); // ID availability depends on auth user, better use public_id if possible but authStore.user usually has id.
    // Wait, members list items have 'id' (user id) usually but strictly public_id is safer if id is hidden.
    // But members endpoint returns User models. ID is hidden.
    // So member.id is undefined. We must use public_id.

    // Check if current user is admin
    const currentUserMemberObj = members.value.find(
        (m) => m.public_id === authStore.user?.public_id,
    );
    const isCurrentUserAdmin = currentUserMemberObj?.pivot?.role === "admin";

    // Admin can remove members, but not other admins or owner
    if (
        isCurrentUserAdmin &&
        member.pivot?.role !== "admin" &&
        member.pivot?.role !== "owner"
    ) {
        return true;
    }

    return false;
};
</script>

<template>
    <div>
        <div v-if="loading" class="flex h-screen items-center justify-center">
            <div
                class="h-8 w-8 animate-spin rounded-full border-2 border-[var(--interactive-primary)] border-t-transparent"
            ></div>
        </div>

        <div v-else class="min-h-screen bg-[var(--surface-primary)] pb-12">
            <!-- Header -->
            <div
                class="bg-[var(--surface-elevated)] border-b border-[var(--border-default)]"
            >
                <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <div
                        class="flex flex-col md:flex-row md:items-center md:justify-between gap-6"
                    >
                        <div class="flex items-center gap-6">
                            <div class="relative group">
                                <Avatar
                                    :src="team.avatar_url"
                                    :alt="team.name"
                                    :fallback="team.initials"
                                    size="5xl"
                                    class="ring-4 ring-[var(--surface-primary)]"
                                />
                                <div
                                    v-if="isOwner"
                                    class="absolute inset-0 bg-black/50 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity text-white gap-3"
                                >
                                    <div
                                        class="p-2 hover:bg-white/20 rounded-full cursor-pointer transition-colors"
                                        @click="triggerAvatarUpload"
                                        title="Upload new avatar"
                                    >
                                        <div
                                            v-if="isUploadingAvatar"
                                            class="animate-spin rounded-full h-5 w-5 border-b-2 border-white"
                                        ></div>
                                        <Upload v-else class="h-5 w-5" />
                                    </div>
                                    <div
                                        v-if="team.has_avatar"
                                        class="p-2 hover:bg-red-500/80 rounded-full cursor-pointer transition-colors text-red-200 hover:text-white"
                                        @click.stop="removeAvatar"
                                        title="Remove avatar"
                                    >
                                        <Trash2 class="h-5 w-5" />
                                    </div>
                                </div>
                                <input
                                    type="file"
                                    ref="avatarInput"
                                    class="hidden"
                                    accept="image/*"
                                    @change="handleAvatarUpload"
                                />
                            </div>
                            <div class="space-y-2">
                                <h1
                                    class="text-3xl font-bold text-[var(--text-primary)]"
                                >
                                    {{ team.name }}
                                </h1>
                                <p
                                    class="text-[var(--text-secondary)] max-w-2xl"
                                >
                                    {{ team.description }}
                                </p>
                                <div
                                    class="flex items-center gap-4 text-sm text-[var(--text-muted)]"
                                >
                                    <div class="flex items-center gap-1.5">
                                        <Users class="h-4 w-4" />
                                        <span
                                            >{{
                                                team.member_count
                                            }}
                                            Members</span
                                        >
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <Calendar class="h-4 w-4" />
                                        <span
                                            >Created
                                            {{
                                                formatDate(team.created_at)
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <Button
                                v-if="isOwner"
                                @click="showInviteModal = true"
                            >
                                <Plus class="h-4 w-4" />
                                Invite Member
                            </Button>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div
                        class="flex items-center gap-8 mt-8 border-b border-[var(--border-default)]"
                    >
                        <button
                            v-for="tab in [
                                'overview',
                                'projects',
                                'templates',
                                'members',
                                'files',
                                'calendar',
                                'activity',
                            ]"
                            :key="tab"
                            @click="activeTab = tab"
                            class="pb-4 text-sm font-medium transition-colors relative"
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
            </div>

            <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <!-- Overview Tab -->
                <div v-if="activeTab === 'overview'" class="space-y-8">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Projects Widget -->
                        <Card class="md:col-span-2">
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center gap-2">
                                    <Briefcase
                                        class="h-5 w-5 text-[var(--text-secondary)]"
                                    />
                                    <h3
                                        class="font-semibold text-[var(--text-primary)]"
                                    >
                                        Active Projects
                                    </h3>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="activeTab = 'projects'"
                                    >View All</Button
                                >
                            </div>
                            <div class="space-y-4">
                                <div
                                    v-if="projectsLoading"
                                    class="text-center py-4 text-[var(--text-muted)]"
                                >
                                    Loading projects...
                                </div>
                                <div
                                    v-else-if="projects.length === 0"
                                    class="text-center py-4 text-[var(--text-muted)]"
                                >
                                    No active projects
                                </div>
                                <div
                                    v-else
                                    v-for="project in projects.slice(0, 5)"
                                    :key="project.id"
                                    class="flex items-center justify-between p-4 bg-[var(--surface-secondary)] rounded-lg"
                                >
                                    <div>
                                        <h4
                                            class="font-medium text-[var(--text-primary)]"
                                        >
                                            {{ project.name }}
                                        </h4>
                                        <p
                                            class="text-xs text-[var(--text-muted)]"
                                        >
                                            Due
                                            {{
                                                project.due_date
                                                    ? formatDate(
                                                          project.due_date,
                                                      )
                                                    : "N/A"
                                            }}
                                        </p>
                                    </div>
                                    <StatusBadge :status="project.status.value">
                                        {{ project.status.label }}
                                    </StatusBadge>
                                </div>
                            </div>
                        </Card>

                        <!-- Clients Widget -->
                        <Card>
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center gap-2">
                                    <Building2
                                        class="h-5 w-5 text-[var(--text-secondary)]"
                                    />
                                    <h3
                                        class="font-semibold text-[var(--text-primary)]"
                                    >
                                        Clients
                                    </h3>
                                </div>
                                <Button variant="ghost" size="sm"
                                    >View All</Button
                                >
                            </div>
                            <div class="space-y-4">
                                <div
                                    v-if="clientsLoading"
                                    class="text-center py-4 text-[var(--text-muted)]"
                                >
                                    Loading clients...
                                </div>
                                <div
                                    v-else-if="clients.length === 0"
                                    class="text-center py-4 text-[var(--text-muted)]"
                                >
                                    No clients found
                                </div>
                                <div
                                    v-else
                                    v-for="client in clients.slice(0, 5)"
                                    :key="client.id"
                                    class="flex items-center gap-3 p-3 hover:bg-[var(--surface-secondary)] rounded-lg transition-colors cursor-pointer"
                                >
                                    <Avatar
                                        :name="client.name"
                                        :src="client.logo_url"
                                        size="sm"
                                        variant="square"
                                        class="rounded-md"
                                    />
                                    <div>
                                        <h4
                                            class="font-medium text-[var(--text-primary)]"
                                        >
                                            {{ client.name }}
                                        </h4>
                                        <p
                                            class="text-xs text-[var(--text-muted)]"
                                        >
                                            {{ client.industry || "Client" }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </Card>
                    </div>

                    <!-- Calendar Widget -->
                    <!-- Calendar Widget -->
                    <div>
                        <div class="flex items-center gap-2 mb-4">
                            <Calendar
                                class="h-5 w-5 text-[var(--text-secondary)]"
                            />
                            <h3
                                class="font-semibold text-[var(--text-primary)]"
                            >
                                Team Calendar
                            </h3>
                        </div>
                        <TeamCalendar
                            :events="calendarEvents"
                            :loading="calendarLoading"
                            :can-create="isTeamAdmin"
                            @dates-set="handleCalendarDatesSet"
                            @create-click="handleCreateEventClick"
                            @event-click="handleEventClick"
                            @export-click="handleExportEvents"
                        />
                    </div>
                </div>

                <!-- Projects Tab -->
                <div v-if="activeTab === 'projects'" class="space-y-6">
                    <TeamProjectsTab :team-id="team.public_id" />
                </div>

                <!-- Templates Tab -->
                <div v-else-if="activeTab === 'templates'" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Task Templates Card -->
                        <Card
                            class="group hover:border-[var(--interactive-primary)] transition-colors cursor-pointer"
                            @click="
                                router.push({
                                    name: 'team-task-templates',
                                    params: { id: team.public_id },
                                })
                            "
                        >
                            <div class="flex items-start justify-between mb-4">
                                <div
                                    class="p-3 bg-[var(--surface-secondary)] rounded-lg group-hover:bg-[var(--interactive-primary-subtle)] transition-colors"
                                >
                                    <LayoutTemplate
                                        class="w-6 h-6 text-[var(--text-secondary)] group-hover:text-[var(--interactive-primary)]"
                                    />
                                </div>
                            </div>
                            <div>
                                <h3
                                    class="font-semibold text-[var(--text-primary)] mb-1"
                                >
                                    Task Templates
                                </h3>
                                <p class="text-sm text-[var(--text-secondary)]">
                                    Manage reusable templates for tasks with
                                    pre-defined checklists and settings.
                                </p>
                            </div>
                            <div
                                class="mt-4 pt-4 border-t border-[var(--border-subtle)] flex items-center text-sm font-medium text-[var(--interactive-primary)]"
                            >
                                Manage Task Templates
                                <ArrowRight
                                    class="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform"
                                />
                            </div>
                        </Card>

                        <!-- Invoice Templates Card -->
                        <Card
                            class="group hover:border-[var(--interactive-primary)] transition-colors cursor-pointer"
                            @click="
                                router.push({
                                    name: 'team-invoice-templates',
                                    params: { id: team.public_id },
                                })
                            "
                        >
                            <div class="flex items-start justify-between mb-4">
                                <div
                                    class="p-3 bg-[var(--surface-secondary)] rounded-lg group-hover:bg-[var(--interactive-primary-subtle)] transition-colors"
                                >
                                    <LayoutTemplate
                                        class="w-6 h-6 text-[var(--text-secondary)] group-hover:text-[var(--interactive-primary)]"
                                    />
                                </div>
                            </div>
                            <div>
                                <h3
                                    class="font-semibold text-[var(--text-primary)] mb-1"
                                >
                                    Invoice Templates
                                </h3>
                                <p class="text-sm text-[var(--text-secondary)]">
                                    Create and manage invoice templates with
                                    default terms, notes, and currency.
                                </p>
                            </div>
                            <div
                                class="mt-4 pt-4 border-t border-[var(--border-subtle)] flex items-center text-sm font-medium text-[var(--interactive-primary)]"
                            >
                                Manage Invoice Templates
                                <ArrowRight
                                    class="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform"
                                />
                            </div>
                        </Card>

                        <!-- Team Roles Card -->
                        <Card
                            class="group hover:border-[var(--interactive-primary)] transition-colors cursor-pointer"
                            @click="
                                router.push({
                                    name: 'team-roles',
                                    params: { team: team.public_id },
                                })
                            "
                        >
                            <div class="flex items-start justify-between mb-4">
                                <div
                                    class="p-3 bg-[var(--surface-secondary)] rounded-lg group-hover:bg-[var(--interactive-primary-subtle)] transition-colors"
                                >
                                    <Shield
                                        class="w-6 h-6 text-[var(--text-secondary)] group-hover:text-[var(--interactive-primary)]"
                                    />
                                </div>
                            </div>
                            <div>
                                <h3
                                    class="font-semibold text-[var(--text-primary)] mb-1"
                                >
                                    Team Roles
                                </h3>
                                <p class="text-sm text-[var(--text-secondary)]">
                                    Manage custom roles and permissions for your
                                    team members.
                                </p>
                            </div>
                            <div
                                class="mt-4 pt-4 border-t border-[var(--border-subtle)] flex items-center text-sm font-medium text-[var(--interactive-primary)]"
                            >
                                Manage Team Roles
                                <ArrowRight
                                    class="w-4 h-4 ml-1 group-hover:translate-x-1 transition-transform"
                                />
                            </div>
                        </Card>
                    </div>
                </div>

                <!-- Members Tab -->
                <div v-else-if="activeTab === 'members'" class="space-y-6">
                    <div
                        class="flex flex-col md:flex-row md:items-center justify-between gap-4"
                    >
                        <div class="relative w-full md:w-64">
                            <Search
                                class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-muted)]"
                            />
                            <input
                                v-model="memberSearch"
                                type="text"
                                placeholder="Search members..."
                                class="w-full pl-9 h-10 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                            />
                        </div>
                        <div class="flex items-center gap-3">
                            <select
                                v-model="roleFilter"
                                class="h-10 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-sm px-3 focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                            >
                                <option value="">All Roles</option>
                                <option value="owner">Owner</option>
                                <option value="admin">Admin</option>
                                <option value="member">Member</option>
                            </select>
                            <select
                                v-model="perPage"
                                class="h-10 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-sm px-3 focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                            >
                                <option :value="10">10 per page</option>
                                <option :value="25">25 per page</option>
                                <option :value="50">50 per page</option>
                            </select>
                        </div>
                    </div>

                    <!-- Pending Invites Section -->
                    <div v-if="pendingInvites.length > 0" class="mb-8">
                        <h3
                            class="text-sm font-medium text-[var(--text-secondary)] uppercase tracking-wider mb-3"
                        >
                            Pending Invitations
                        </h3>
                        <div
                            class="bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl overflow-hidden"
                        >
                            <table class="w-full">
                                <tbody
                                    class="divide-y divide-[var(--border-default)]"
                                >
                                    <tr
                                        v-for="invite in pendingInvites"
                                        :key="invite.id"
                                        class="hover:bg-[var(--surface-secondary)]/50 transition-colors"
                                    >
                                        <td class="py-3 px-6">
                                            <div
                                                class="flex items-center gap-3"
                                            >
                                                <div
                                                    class="h-8 w-8 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center text-[var(--text-muted)]"
                                                >
                                                    <Mail class="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <p
                                                        class="text-sm font-medium text-[var(--text-primary)]"
                                                    >
                                                        {{ invite.email }}
                                                    </p>
                                                    <p
                                                        class="text-xs text-[var(--text-muted)]"
                                                    >
                                                        Invited by
                                                        {{
                                                            invite.inviter_name
                                                        }}
                                                        •
                                                        {{
                                                            formatDate(
                                                                invite.sent_at,
                                                            )
                                                        }}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-3 px-6">
                                            <span
                                                class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 capitalize"
                                            >
                                                {{ invite.role }} (Pending)
                                            </span>
                                        </td>
                                        <td class="py-3 px-6 text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                class="text-red-500 hover:text-red-600 hover:bg-red-50 text-xs h-7"
                                                @click="
                                                    handleCancelInvite(
                                                        invite.id,
                                                    )
                                                "
                                            >
                                                Cancel
                                            </Button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div
                        class="bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl overflow-hidden"
                    >
                        <table class="w-full">
                            <thead
                                class="bg-[var(--surface-secondary)] border-b border-[var(--border-default)]"
                            >
                                <tr>
                                    <th
                                        class="text-left py-3 px-6 text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                    >
                                        Member
                                    </th>
                                    <th
                                        class="text-left py-3 px-6 text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                    >
                                        Role
                                    </th>
                                    <th
                                        class="text-left py-3 px-6 text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                    >
                                        Joined
                                    </th>
                                    <th
                                        class="text-right py-3 px-6 text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                                    >
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-[var(--border-default)]"
                            >
                                <tr v-if="membersLoading" class="animate-pulse">
                                    <td
                                        colspan="4"
                                        class="py-12 text-center text-[var(--text-muted)]"
                                    >
                                        Loading members...
                                    </td>
                                </tr>
                                <tr v-else-if="members.length === 0">
                                    <td
                                        colspan="4"
                                        class="py-12 text-center text-[var(--text-muted)]"
                                    >
                                        No members found.
                                    </td>
                                </tr>
                                <tr
                                    v-for="member in members"
                                    :key="member.id"
                                    class="hover:bg-[var(--surface-secondary)]/50 transition-colors"
                                >
                                    <td class="py-4 px-6">
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="relative cursor-pointer"
                                                @click="
                                                    router.push(
                                                        `/users/${member.public_id}/profile`,
                                                    )
                                                "
                                            >
                                                <Avatar
                                                    :src="member.avatar_url"
                                                    :fallback="member.initials"
                                                    size="sm"
                                                />
                                            </div>
                                            <div>
                                                <p
                                                    class="text-sm font-medium text-[var(--text-primary)] hover:text-brand-600 cursor-pointer"
                                                    @click="
                                                        router.push({
                                                            name: 'user-profile',
                                                            params: {
                                                                public_id:
                                                                    member.public_id,
                                                            },
                                                        })
                                                    "
                                                >
                                                    {{ member.name }}
                                                </p>
                                                <p
                                                    class="text-xs text-[var(--text-muted)]"
                                                >
                                                    {{ member.email }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-4 px-6">
                                        <div
                                            v-if="
                                                isTeamAdmin &&
                                                member.pivot?.role !==
                                                    'owner' &&
                                                member.public_id !==
                                                    authStore.user?.public_id
                                            "
                                            class="flex items-center"
                                        >
                                            <select
                                                class="text-xs border border-[var(--border-default)] rounded px-2 py-1 bg-[var(--surface-primary)] text-[var(--text-primary)] focus:ring-brand-500 focus:border-brand-500"
                                                @change="
                                                    (e) =>
                                                        handleUpdateRole(
                                                            member,
                                                            e.target.value,
                                                        )
                                                "
                                                :value="member.pivot?.role"
                                            >
                                                <option value="member">
                                                    Member
                                                </option>
                                                <option value="admin">
                                                    Admin
                                                </option>
                                            </select>
                                        </div>
                                        <span
                                            v-else
                                            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize"
                                            :class="
                                                member.pivot?.role === 'owner'
                                                    ? 'bg-purple-100 text-purple-800'
                                                    : 'bg-gray-100 text-gray-800'
                                            "
                                        >
                                            {{ member.pivot?.role || "member" }}
                                        </span>
                                    </td>
                                    <td
                                        class="py-4 px-6 text-sm text-[var(--text-secondary)]"
                                    >
                                        {{
                                            member.pivot?.joined_at
                                                ? formatDate(
                                                      member.pivot.joined_at,
                                                  )
                                                : "N/A"
                                        }}
                                    </td>
                                    <td class="py-4 px-6 text-right">
                                        <Button
                                            v-if="canRemoveMember(member)"
                                            variant="ghost"
                                            size="icon-sm"
                                            class="text-red-500 hover:text-red-600 hover:bg-red-50"
                                            @click="handleRemoveMember(member)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                        <span
                                            v-else-if="
                                                member.public_id ===
                                                team.owner.public_id
                                            "
                                            class="text-xs text-[var(--text-muted)] italic"
                                            >Owner</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination -->
                    <div
                        v-if="memberTotal > 0"
                        class="flex items-center justify-between border-t border-[var(--border-default)] pt-4"
                    >
                        <div class="text-sm text-[var(--text-muted)]">
                            Showing {{ (memberPage - 1) * perPage + 1 }} to
                            {{ Math.min(memberPage * perPage, memberTotal) }} of
                            {{ memberTotal }} members
                        </div>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="memberPage === 1"
                                @click="memberPage--"
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="memberPage * perPage >= memberTotal"
                                @click="memberPage++"
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Files Tab -->
                <div v-if="activeTab === 'files'" class="h-full">
                    <div class="h-[calc(100vh-250px)]">
                        <MediaManager
                            :items="paginatedFiles"
                            :total="filteredFiles.length"
                            :current-page="filePage"
                            :per-page="filePerPage"
                            :search="fileSearch"
                            :filters="fileFilters"
                            :loading="filesLoading"
                            :can-upload="isOwner"
                            :can-delete="isOwner"
                            :uploading="isUploading"
                            :upload-queue="uploadQueue"
                            :storage-used="team.storage_used || 0"
                            :storage-limit="team.storage_limit || 0"
                            @update:page="filePage = $event"
                            @update:per-page="filePerPage = $event"
                            @update:search="fileSearch = $event"
                            @update:filters="fileFilters = $event"
                            @upload="handleUpload"
                            @delete="requestDeleteFile"
                            @download="handleDownload"
                            @view="handleViewMedia"
                            @remove-upload="removeFileFromQueue"
                            @process-queue="processUploadQueue"
                            @bulk-delete="requestBulkDelete"
                            @bulk-download="handleBulkDownload"
                        />
                    </div>
                </div>

                <!-- Calendar Tab -->
                <div v-if="activeTab === 'calendar'" class="space-y-6">
                    <TeamCalendar
                        :events="calendarEvents"
                        :loading="calendarLoading"
                        :can-create="isTeamAdmin"
                        @dates-set="handleCalendarDatesSet"
                        @create-click="handleCreateEventClick"
                        @event-click="handleEventClick"
                        @export-click="handleExportEvents"
                    />
                </div>

                <!-- Activity Tab -->
                <div v-if="activeTab === 'activity'" class="space-y-6">
                    <Card>
                        <div
                            class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6"
                        >
                            <div class="flex items-center gap-2">
                                <History
                                    class="h-5 w-5 text-[var(--text-secondary)]"
                                />
                                <h3
                                    class="font-semibold text-[var(--text-primary)]"
                                >
                                    Activity Trail
                                </h3>
                                <span class="text-sm text-[var(--text-muted)]">
                                    ({{ activityTotal }} activities)
                                </span>
                            </div>
                            <div class="flex items-center gap-3">
                                <select
                                    v-model="activityPerPage"
                                    class="h-9 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-sm px-3 focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                                >
                                    <option :value="20">20 per page</option>
                                    <option :value="50">50 per page</option>
                                    <option :value="100">100 per page</option>
                                    <option :value="200">200 per page</option>
                                </select>
                            </div>
                        </div>

                        <!-- Loading State -->
                        <div v-if="activityLoading" class="space-y-4">
                            <div
                                v-for="i in 5"
                                :key="i"
                                class="animate-pulse flex items-start gap-3"
                            >
                                <div
                                    class="h-10 w-10 rounded-full bg-[var(--surface-tertiary)]"
                                ></div>
                                <div class="flex-1 space-y-2">
                                    <div
                                        class="h-4 bg-[var(--surface-tertiary)] rounded w-3/4"
                                    ></div>
                                    <div
                                        class="h-3 bg-[var(--surface-tertiary)] rounded w-1/4"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        <!-- Empty State -->
                        <div
                            v-else-if="activityLogs.length === 0"
                            class="py-12 text-center"
                        >
                            <History
                                class="h-12 w-12 text-[var(--text-muted)] mx-auto mb-4"
                            />
                            <p class="text-[var(--text-secondary)]">
                                No activity yet
                            </p>
                            <p class="text-sm text-[var(--text-muted)] mt-1">
                                Activity will appear here when team members take
                                actions
                            </p>
                        </div>

                        <!-- Activity List with Scroll -->
                        <div
                            v-else
                            class="max-h-[600px] overflow-y-auto space-y-1 pr-2"
                        >
                            <div
                                v-for="log in activityLogs"
                                :key="log.id"
                                class="flex items-start gap-3 p-3 rounded-lg hover:bg-[var(--surface-secondary)] transition-colors"
                            >
                                <Avatar
                                    :src="log.user?.avatar_url"
                                    :fallback="log.user?.initials || 'S'"
                                    size="sm"
                                />
                                <div class="flex-1 min-w-0">
                                    <p
                                        class="text-sm text-[var(--text-primary)]"
                                    >
                                        <span class="font-semibold">{{
                                            log.user?.name || "System"
                                        }}</span>
                                        <span
                                            class="text-[var(--text-secondary)]"
                                            >{{
                                                " " +
                                                (log.action_label ||
                                                    log.action) +
                                                " "
                                            }}</span
                                        >
                                        <span
                                            v-if="log.target"
                                            class="font-semibold"
                                            >{{ log.target }}</span
                                        >
                                    </p>
                                    <p
                                        class="text-xs text-[var(--text-muted)] mt-0.5"
                                    >
                                        {{
                                            log.time ||
                                            formatDate(log.created_at)
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div
                            v-if="activityTotal > 0"
                            class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-[var(--border-default)] pt-4"
                        >
                            <span class="text-sm text-[var(--text-muted)]">
                                Showing
                                {{ (activityPage - 1) * activityPerPage + 1 }}
                                to
                                {{
                                    Math.min(
                                        activityPage * activityPerPage,
                                        activityTotal,
                                    )
                                }}
                                of {{ activityTotal }} activities
                            </span>
                            <div class="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="activityPage <= 1"
                                    @click="activityPage--"
                                >
                                    <ChevronLeft class="h-4 w-4" />
                                    Previous
                                </Button>
                                <span
                                    class="text-sm text-[var(--text-secondary)] px-2"
                                >
                                    Page {{ activityPage }} of
                                    {{
                                        Math.ceil(
                                            activityTotal / activityPerPage,
                                        ) || 1
                                    }}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :disabled="
                                        activityPage >=
                                        Math.ceil(
                                            activityTotal / activityPerPage,
                                        )
                                    "
                                    @click="activityPage++"
                                >
                                    Next
                                    <ChevronRight class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>
        </div>

        <!-- Team Event Modal -->
        <TeamEventModal
            v-model:open="showEventModal"
            :event="selectedEvent"
            :loading="calendarLoading"
            :team-members="team?.members || []"
            :team-id="team?.public_id"
            @save="handleSaveEvent"
            @delete="handleDeleteEvent"
        />

        <!-- Invite Modal -->
        <Modal v-model:open="showInviteModal" title="Invite Team Member">
            <div class="space-y-4">
                <div
                    v-if="inviteError"
                    class="p-3 bg-red-50 text-red-600 rounded-lg text-sm"
                >
                    {{ inviteError }}
                </div>
                <div class="space-y-2">
                    <label
                        class="text-sm font-medium text-[var(--text-secondary)]"
                        >Email Address</label
                    >
                    <Input
                        v-model="inviteEmail"
                        type="email"
                        placeholder="colleague@example.com"
                    />
                </div>
                <div class="space-y-2">
                    <label
                        class="text-sm font-medium text-[var(--text-secondary)]"
                        >Role</label
                    >
                    <select
                        v-model="inviteRole"
                        class="w-full h-10 px-3 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                    >
                        <option value="member">Member</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>
            <template #footer>
                <div class="flex justify-end gap-3">
                    <Button variant="ghost" @click="showInviteModal = false"
                        >Cancel</Button
                    >
                    <Button :loading="inviteLoading" @click="handleInvite"
                        >Send Invitation</Button
                    >
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal
            v-model:open="deleteModalOpen"
            :title="
                deleteTarget.type === 'bulk'
                    ? 'Delete Files'
                    : deleteTarget.type === 'avatar'
                      ? 'Remove Avatar'
                      : 'Delete File'
            "
            :description="
                deleteTarget.type === 'bulk'
                    ? `Are you sure you want to delete ${deleteTarget.ids.length} files? This action cannot be undone.`
                    : deleteTarget.type === 'avatar'
                      ? 'Are you sure you want to remove the team avatar? This cannot be undone.'
                      : 'Are you sure you want to delete this file? This action cannot be undone.'
            "
            size="sm"
        >
            <div class="flex justify-end gap-3 mt-4">
                <Button variant="outline" @click="deleteModalOpen = false">
                    Cancel
                </Button>
                <Button variant="danger" @click="confirmDelete">
                    Delete
                </Button>
            </div>
        </Modal>
    </div>
</template>
