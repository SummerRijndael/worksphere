<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import {
    DialogRoot, DialogPortal, DialogOverlay, DialogContent,
    DialogTitle, DialogDescription, DialogClose
} from 'reka-ui';
import { Button, Avatar, Textarea, Dropdown } from '@/components/ui';
import {
    Calendar, Clock, User, MessageSquare, History, MoreHorizontal,
    Paperclip, X, ChevronRight, Circle, CheckCircle2,
    Play, Send, Eye, RotateCcw, Archive, UserPlus, Edit3,
    ListChecks, GripVertical, Plus, Square, CheckSquare, Loader2
} from 'lucide-vue-next';
import axios from 'axios';
import { useAuthStore } from '@/stores/auth';
import { toast } from 'vue-sonner';

interface Props {
    open: boolean;
    task: any;
    teamId: string;
    projectId: string;
    projectMembers?: any[];
}

const props = withDefaults(defineProps<Props>(), {
    projectMembers: () => []
});
const emit = defineEmits(['update:open', 'task-updated', 'task-deleted', 'edit-task', 'close']);

const authStore = useAuthStore();
const isLoading = ref(false);
const localTask = ref<any>(null);
const activeTab = ref<'comments' | 'history' | 'checklist'>('checklist');
const newComment = ref('');
const isSubmittingComment = ref(false);
const isAssigning = ref(false);
const showAssignDropdown = ref(false);
const isUpdatingStatus = ref(false);

const comments = ref<any[]>([]);
const statusHistory = ref<any[]>([]);
const localMembers = ref<any[]>([]);
const checklistItems = ref<any[]>([]);
const newChecklistText = ref('');
const isAddingItem = ref(false);
const canSubmitForReview = ref(false);

const isOpen = computed({
    get: () => props.open,
    set: (val) => {
        emit('update:open', val);
        if (!val) emit('close');
    },
});

// Priority configuration
const priorityConfig: Record<number | string, { label: string; color: string; bg: string }> = {
    1: { label: 'Low', color: 'text-slate-600 dark:text-slate-400', bg: 'bg-slate-100 dark:bg-slate-800' },
    2: { label: 'Medium', color: 'text-blue-600 dark:text-blue-400', bg: 'bg-blue-50 dark:bg-blue-900/30' },
    3: { label: 'High', color: 'text-amber-600 dark:text-amber-400', bg: 'bg-amber-50 dark:bg-amber-900/30' },
    4: { label: 'Urgent', color: 'text-red-600 dark:text-red-400', bg: 'bg-red-50 dark:bg-red-900/30' },
};

// Status configuration with all backend statuses - synced with TaskStatus enum
const statusConfig: Record<string, { label: string; color: string; bg: string; icon: any }> = {
    draft: { label: 'Draft', color: 'text-slate-500', bg: 'bg-slate-100 dark:bg-slate-800', icon: Circle },
    open: { label: 'Open', color: 'text-slate-600', bg: 'bg-slate-100 dark:bg-slate-800', icon: Circle },
    in_progress: { label: 'In Progress', color: 'text-blue-600', bg: 'bg-blue-50 dark:bg-blue-900/30', icon: Play },
    submitted: { label: 'Submitted', color: 'text-amber-600', bg: 'bg-amber-50 dark:bg-amber-900/30', icon: Send },
    in_qa: { label: 'In QA Review', color: 'text-orange-600', bg: 'bg-orange-50 dark:bg-orange-900/30', icon: Eye },
    approved: { label: 'Approved', color: 'text-emerald-600', bg: 'bg-emerald-50 dark:bg-emerald-900/30', icon: CheckCircle2 },
    rejected: { label: 'Rejected', color: 'text-red-600', bg: 'bg-red-50 dark:bg-red-900/30', icon: RotateCcw },
    sent_to_client: { label: 'Sent to Client', color: 'text-purple-600', bg: 'bg-purple-50 dark:bg-purple-900/30', icon: Send },
    client_approved: { label: 'Client Approved', color: 'text-teal-600', bg: 'bg-teal-50 dark:bg-teal-900/30', icon: CheckCircle2 },
    client_rejected: { label: 'Client Rejected', color: 'text-rose-600', bg: 'bg-rose-50 dark:bg-rose-900/30', icon: RotateCcw },
    completed: { label: 'Completed', color: 'text-emerald-600', bg: 'bg-emerald-50 dark:bg-emerald-900/30', icon: CheckCircle2 },
    archived: { label: 'Archived', color: 'text-slate-500', bg: 'bg-slate-100 dark:bg-slate-800', icon: Archive },
};

// Simplified workflow for quick status changes
const workflowStatuses = ['open', 'in_progress', 'completed'];

const getPriority = (priority: number | string) => priorityConfig[priority] || priorityConfig[2];
const getStatus = (status: string) => statusConfig[status] || statusConfig['open'];
const getStatusValue = (task: any) => task?.status?.value || task?.status || 'open';

// Member options for assign dropdown
const memberOptions = computed(() => {
    const members = props.projectMembers?.length > 0 ? props.projectMembers : localMembers.value;
    return members.map(m => ({ value: m.public_id || m.id, label: m.name, avatar: m.avatar_url }));
});

// API calls
const fetchTaskDetails = async () => {
    const taskId = props.task?.public_id || props.task?.id;
    if (!taskId || !props.teamId || !props.projectId) return;

    try {
        isLoading.value = true;
        const response = await axios.get(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}`);
        localTask.value = response.data.data || response.data;
        await Promise.all([fetchComments(), fetchStatusHistory(), fetchChecklistItems()]);
    } catch (err) {
        toast.error('Failed to load task details');
    } finally {
        isLoading.value = false;
    }
};

const fetchMembers = async () => {
    if (props.projectMembers?.length > 0 || !props.teamId || !props.projectId) return;
    try {
        const response = await axios.get(`/api/teams/${props.teamId}/projects/${props.projectId}`);
        localMembers.value = response.data.data?.members || [];
    } catch (err) {
        console.error('Failed to fetch members', err);
    }
};

const fetchComments = async () => {
    if (!localTask.value) return;
    try {
        const taskId = localTask.value.public_id || localTask.value.id;
        const response = await axios.get(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}/comments`);
        comments.value = response.data.data || [];
    } catch (err) {
        console.error('Failed to fetch comments', err);
    }
};

const fetchStatusHistory = async () => {
    if (!localTask.value) return;
    try {
        const taskId = localTask.value.public_id || localTask.value.id;
        const response = await axios.get(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}/status-history`);
        statusHistory.value = response.data.data || [];
    } catch (err) {
        console.error('Failed to fetch history', err);
    }
};

// Checklist API methods
const fetchChecklistItems = async () => {
    if (!localTask.value) return;
    try {
        const taskId = localTask.value.public_id || localTask.value.id;
        const response = await axios.get(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}/checklist`);
        checklistItems.value = response.data.data || [];
        canSubmitForReview.value = response.data.meta?.can_submit_for_review || false;
    } catch (err) {
        console.error('Failed to fetch checklist', err);
    }
};

const addChecklistItem = async () => {
    if (!newChecklistText.value.trim() || !localTask.value) return;
    try {
        isAddingItem.value = true;
        const taskId = localTask.value.public_id || localTask.value.id;
        await axios.post(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}/checklist`, {
            text: newChecklistText.value,
        });
        newChecklistText.value = '';
        await fetchChecklistItems();
        toast.success('Item added');
    } catch (err: any) {
        toast.error(err.response?.data?.message || 'Failed to add item');
    } finally {
        isAddingItem.value = false;
    }
};

const updateChecklistItemStatus = async (item: any, newStatus: string) => {
    if (!localTask.value) return;
    // Check if current user is assignee
    const isAssignee = localTask.value.assignee?.public_id === authStore.user?.public_id
        || localTask.value.assignee?.id === authStore.user?.id;
    if (!isAssignee) {
        toast.error('Only the assignee can change item status');
        return;
    }
    try {
        const taskId = localTask.value.public_id || localTask.value.id;
        await axios.put(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}/checklist/${item.public_id}`, {
            status: newStatus,
        });
        await fetchChecklistItems();
    } catch (err: any) {
        toast.error(err.response?.data?.message || 'Failed to update item');
    }
};

const deleteChecklistItem = async (item: any) => {
    if (!localTask.value) return;
    try {
        const taskId = localTask.value.public_id || localTask.value.id;
        await axios.delete(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}/checklist/${item.public_id}`);
        await fetchChecklistItems();
        toast.success('Item removed');
    } catch (err: any) {
        toast.error(err.response?.data?.message || 'Failed to remove item');
    }
};

const isAssignee = computed(() => {
    return localTask.value?.assignee?.public_id === authStore.user?.public_id
        || localTask.value?.assignee?.id === authStore.user?.id;
});

const completedItemsCount = computed(() => {
    return checklistItems.value.filter((i: any) => i.status === 'done').length;
});

const submitComment = async () => {
    if (!newComment.value.trim() || !localTask.value) return;
    try {
        isSubmittingComment.value = true;
        const taskId = localTask.value.public_id || localTask.value.id;
        await axios.post(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}/comments`, {
            content: newComment.value,
        });
        newComment.value = '';
        await fetchComments();
        toast.success('Comment added');
    } catch (err) {
        toast.error('Failed to add comment');
    } finally {
        isSubmittingComment.value = false;
    }
};

const assignTask = async (assigneeId: string) => {
    if (!localTask.value) return;
    try {
        isAssigning.value = true;
        const taskId = localTask.value.public_id || localTask.value.id;
        const response = await axios.post(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}/assign`, {
            assigned_to: assigneeId || null,
        });
        localTask.value = response.data.data || response.data;
        emit('task-updated', localTask.value);
        toast.success(assigneeId ? 'Task assigned' : 'Task unassigned');
        showAssignDropdown.value = false;
    } catch (err: any) {
        toast.error(err.response?.data?.message || 'Failed to assign task');
    } finally {
        isAssigning.value = false;
    }
};

const updateStatus = async (status: string) => {
    if (!localTask.value) return;
    const currentStatus = getStatusValue(localTask.value);
    if (currentStatus === status) return;

    try {
        isUpdatingStatus.value = true;
        const taskId = localTask.value.public_id || localTask.value.id;
        const response = await axios.put(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}`, { status });
        localTask.value = response.data.data || response.data;
        emit('task-updated', localTask.value);
        toast.success(`Status updated to ${getStatus(status).label}`);
        await fetchStatusHistory();
    } catch (err: any) {
        toast.error(err.response?.data?.message || 'Failed to update status');
    } finally {
        isUpdatingStatus.value = false;
    }
};

const deleteTask = async () => {
    if (!confirm('Are you sure you want to delete this task?')) return;
    try {
        const taskId = localTask.value.public_id || localTask.value.id;
        await axios.delete(`/api/teams/${props.teamId}/projects/${props.projectId}/tasks/${taskId}`);
        toast.success('Task deleted');
        emit('task-deleted', localTask.value);
        isOpen.value = false;
    } catch (err) {
        toast.error('Failed to delete task');
    }
};

// Formatting helpers
const formatDate = (date?: string) => {
    if (!date) return '';
    return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

const formatTime = (date?: string) => {
    if (!date) return '';
    return new Date(date).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};

const timeAgo = (date?: string) => {
    if (!date) return '';
    const now = new Date();
    const past = new Date(date);
    const diffMs = now.getTime() - past.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return formatDate(date);
};

// Watchers
watch(() => props.open, async (val) => {
    if (val && props.task) {
        localTask.value = props.task;
        await Promise.all([fetchTaskDetails(), fetchMembers()]);
    } else {
        localTask.value = null;
        comments.value = [];
        statusHistory.value = [];
        activeTab.value = 'comments';
    }
}, { immediate: true });
</script>

<template>
    <DialogRoot v-model:open="isOpen">
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-[1040] bg-black/60 backdrop-blur-sm data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
            <DialogContent class="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 z-[1050] w-full max-w-4xl max-h-[92vh] bg-[var(--surface-primary)] rounded-xl shadow-2xl overflow-hidden focus:outline-none data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 duration-200">

                <div v-if="localTask" class="flex flex-col h-full max-h-[92vh]">
                    <!-- Compact Header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b border-[var(--border-subtle)] bg-[var(--surface-primary)]">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="text-xs font-mono text-[var(--text-muted)] bg-[var(--surface-secondary)] px-2 py-1 rounded">
                                #{{ (localTask.public_id || localTask.id)?.substring(0, 8) }}
                            </span>
                            <span :class="[getStatus(getStatusValue(localTask)).bg, getStatus(getStatusValue(localTask)).color, 'px-2.5 py-1 rounded-full text-xs font-medium']">
                                {{ getStatus(getStatusValue(localTask)).label }}
                            </span>
                            <span :class="[getPriority(localTask.priority).bg, getPriority(localTask.priority).color, 'px-2.5 py-1 rounded-full text-xs font-medium']">
                                {{ getPriority(localTask.priority).label }}
                            </span>
                        </div>
                        <div class="flex items-center gap-1">
                            <Dropdown
                                align="end"
                                :items="[
                                    { label: 'Edit Task', icon: Edit3, action: () => emit('edit-task', localTask) },
                                    { label: 'Delete Task', icon: X, variant: 'danger', action: deleteTask }
                                ]"
                            >
                                <Button variant="ghost" size="icon" class="h-8 w-8">
                                    <MoreHorizontal class="w-4 h-4" />
                                </Button>
                            </Dropdown>
                            <DialogClose as-child>
                                <Button variant="ghost" size="icon" class="h-8 w-8">
                                    <X class="w-4 h-4" />
                                </Button>
                            </DialogClose>
                        </div>
                    </div>

                    <!-- Main Content Area -->
                    <div class="flex flex-1 overflow-hidden">
                        <!-- Left Panel - Main Content -->
                        <div class="flex-1 overflow-y-auto">
                            <div class="p-6 space-y-6">
                                <!-- Title & Description -->
                                <div>
                                    <DialogTitle class="text-xl font-semibold text-[var(--text-primary)] mb-3">
                                        {{ localTask.title }}
                                    </DialogTitle>
                                    <DialogDescription class="sr-only">Task details and management</DialogDescription>
                                    <div v-if="localTask.description" class="text-sm text-[var(--text-secondary)] leading-relaxed whitespace-pre-wrap">
                                        {{ localTask.description }}
                                    </div>
                                    <p v-else class="text-sm text-[var(--text-muted)] italic">No description provided</p>
                                </div>

                                <!-- Details Grid -->
                                <div class="grid grid-cols-2 gap-4">
                                    <!-- Assignee -->
                                    <div class="relative">
                                        <label class="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wide mb-2 block">Assignee</label>
                                        <button
                                            @click="showAssignDropdown = !showAssignDropdown"
                                            class="flex items-center gap-3 w-full p-3 rounded-lg border border-[var(--border-default)] hover:border-[var(--border-strong)] hover:bg-[var(--surface-secondary)] transition-all text-left"
                                            :disabled="isAssigning"
                                        >
                                            <Avatar
                                                v-if="localTask.assignee"
                                                :name="localTask.assignee.name"
                                                :src="localTask.assignee.avatar_url"
                                                size="sm"
                                            />
                                            <div v-else class="w-8 h-8 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center border-2 border-dashed border-[var(--border-default)]">
                                                <UserPlus class="w-3.5 h-3.5 text-[var(--text-muted)]" />
                                            </div>
                                            <span class="text-sm" :class="localTask.assignee ? 'text-[var(--text-primary)] font-medium' : 'text-[var(--text-muted)]'">
                                                {{ localTask.assignee?.name || 'Unassigned' }}
                                            </span>
                                            <ChevronRight class="w-4 h-4 text-[var(--text-muted)] ml-auto" />
                                        </button>

                                        <!-- Assign Dropdown -->
                                        <Transition
                                            enter-active-class="transition ease-out duration-100"
                                            enter-from-class="opacity-0 scale-95"
                                            enter-to-class="opacity-100 scale-100"
                                            leave-active-class="transition ease-in duration-75"
                                            leave-from-class="opacity-100 scale-100"
                                            leave-to-class="opacity-0 scale-95"
                                        >
                                            <div
                                                v-if="showAssignDropdown"
                                                class="absolute top-full left-0 right-0 mt-1 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg shadow-xl z-20 py-1 max-h-64 overflow-y-auto"
                                            >
                                                <button
                                                    @click="assignTask('')"
                                                    class="w-full px-3 py-2.5 text-left text-sm hover:bg-[var(--surface-secondary)] flex items-center gap-3 transition-colors"
                                                    :class="{ 'bg-[var(--surface-secondary)]': !localTask.assignee }"
                                                >
                                                    <div class="w-8 h-8 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center">
                                                        <User class="w-4 h-4 text-[var(--text-muted)]" />
                                                    </div>
                                                    <span class="text-[var(--text-secondary)]">Unassigned</span>
                                                </button>
                                                <div class="h-px bg-[var(--border-subtle)] my-1"></div>
                                                <button
                                                    v-for="member in memberOptions"
                                                    :key="member.value"
                                                    @click="assignTask(member.value)"
                                                    class="w-full px-3 py-2.5 text-left text-sm hover:bg-[var(--surface-secondary)] flex items-center gap-3 transition-colors"
                                                    :class="{ 'bg-[var(--interactive-primary)]/10': localTask.assignee?.public_id === member.value }"
                                                >
                                                    <Avatar :name="member.label" :src="member.avatar" size="sm" />
                                                    <span class="text-[var(--text-primary)]">{{ member.label }}</span>
                                                    <CheckCircle2 v-if="localTask.assignee?.public_id === member.value" class="w-4 h-4 text-[var(--interactive-primary)] ml-auto" />
                                                </button>
                                            </div>
                                        </Transition>
                                    </div>

                                    <!-- Due Date -->
                                    <div>
                                        <label class="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wide mb-2 block">Due Date</label>
                                        <div class="flex items-center gap-3 p-3 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]/30">
                                            <div class="w-8 h-8 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center">
                                                <Calendar class="w-4 h-4 text-[var(--text-muted)]" />
                                            </div>
                                            <span class="text-sm" :class="localTask.due_date ? 'text-[var(--text-primary)] font-medium' : 'text-[var(--text-muted)]'">
                                                {{ localTask.due_date ? formatDate(localTask.due_date) : 'No due date' }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Reporter -->
                                    <div>
                                        <label class="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wide mb-2 block">Reporter</label>
                                        <div class="flex items-center gap-3 p-3 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]/30">
                                            <Avatar
                                                v-if="localTask.creator"
                                                :name="localTask.creator.name"
                                                :src="localTask.creator.avatar_url"
                                                size="sm"
                                            />
                                            <span class="text-sm text-[var(--text-primary)] font-medium">
                                                {{ localTask.creator?.name || 'Unknown' }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Estimated Hours -->
                                    <div>
                                        <label class="text-xs font-medium text-[var(--text-muted)] uppercase tracking-wide mb-2 block">Time Estimate</label>
                                        <div class="flex items-center gap-3 p-3 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)]/30">
                                            <div class="w-8 h-8 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center">
                                                <Clock class="w-4 h-4 text-[var(--text-muted)]" />
                                            </div>
                                            <span class="text-sm" :class="localTask.estimated_hours ? 'text-[var(--text-primary)] font-medium' : 'text-[var(--text-muted)]'">
                                                {{ localTask.estimated_hours ? `${localTask.estimated_hours} hours` : 'Not estimated' }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Activity Section -->
                                <div class="border-t border-[var(--border-subtle)] pt-6">
                                    <!-- Tabs -->
                                    <div class="flex items-center gap-1 mb-4 bg-[var(--surface-secondary)] p-1 rounded-lg w-fit">
                                        <button
                                            @click="activeTab = 'comments'"
                                            class="flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-all"
                                            :class="activeTab === 'comments'
                                                ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                                : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                                        >
                                            <MessageSquare class="w-4 h-4" />
                                            Comments
                                            <span v-if="comments.length" class="bg-[var(--surface-tertiary)] text-[var(--text-muted)] text-xs px-1.5 py-0.5 rounded-full">
                                                {{ comments.length }}
                                            </span>
                                        </button>
                                        <button
                                            @click="activeTab = 'history'"
                                            class="flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-all"
                                            :class="activeTab === 'history'
                                                ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                                : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'"
                                        >
                                            <History class="w-4 h-4" />
                                            Activity
                                        </button>
                                    </div>

                                    <!-- Comments Tab -->
                                    <div v-if="activeTab === 'comments'" class="space-y-4">
                                        <!-- Comment Input -->
                                        <div class="flex gap-3">
                                            <Avatar :name="authStore.user?.name" :src="authStore.avatarUrl" size="sm" class="shrink-0 mt-1" />
                                            <div class="flex-1 space-y-2">
                                                <Textarea
                                                    v-model="newComment"
                                                    placeholder="Add a comment..."
                                                    class="min-h-[80px] resize-none"
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

                                        <!-- Comments List -->
                                        <div class="space-y-3 mt-4">
                                            <div v-for="comment in comments" :key="comment.id" class="flex gap-3">
                                                <Avatar :name="comment.user?.name" :src="comment.user?.avatar_url" size="sm" class="shrink-0 mt-0.5" />
                                                <div class="flex-1 min-w-0">
                                                    <div class="bg-[var(--surface-secondary)] rounded-lg px-4 py-3">
                                                        <div class="flex items-center justify-between gap-2 mb-1">
                                                            <span class="text-sm font-medium text-[var(--text-primary)]">{{ comment.user?.name }}</span>
                                                            <span class="text-xs text-[var(--text-muted)]">{{ timeAgo(comment.created_at) }}</span>
                                                        </div>
                                                        <p class="text-sm text-[var(--text-secondary)] whitespace-pre-wrap break-words">{{ comment.content }}</p>
                                                    </div>
                                                </div>
                                            </div>
                                            <p v-if="comments.length === 0" class="text-center text-sm text-[var(--text-muted)] py-8">
                                                No comments yet. Start the conversation!
                                            </p>
                                        </div>
                                    </div>

                                    <!-- History Tab -->
                                    <div v-if="activeTab === 'history'" class="space-y-1">
                                        <div v-for="(entry, index) in statusHistory" :key="entry.id" class="flex gap-3 py-3">
                                            <div class="flex flex-col items-center">
                                                <div class="w-2 h-2 rounded-full bg-[var(--interactive-primary)]"></div>
                                                <div v-if="index < statusHistory.length - 1" class="w-0.5 flex-1 bg-[var(--border-default)] mt-1"></div>
                                            </div>
                                            <div class="flex-1 pb-2">
                                                <p class="text-sm text-[var(--text-secondary)]">
                                                    <span class="font-medium text-[var(--text-primary)]">{{ entry.user?.name || 'System' }}</span>
                                                    changed status from
                                                    <span :class="[getStatus(entry.from_status || 'open').bg, getStatus(entry.from_status || 'open').color, 'px-1.5 py-0.5 rounded text-xs font-medium mx-1']">
                                                        {{ getStatus(entry.from_status || 'open').label }}
                                                    </span>
                                                    to
                                                    <span :class="[getStatus(entry.to_status).bg, getStatus(entry.to_status).color, 'px-1.5 py-0.5 rounded text-xs font-medium ml-1']">
                                                        {{ getStatus(entry.to_status).label }}
                                                    </span>
                                                </p>
                                                <p class="text-xs text-[var(--text-muted)] mt-1">{{ formatTime(entry.created_at) }}</p>
                                            </div>
                                        </div>
                                        <p v-if="statusHistory.length === 0" class="text-center text-sm text-[var(--text-muted)] py-8">
                                            No activity recorded yet
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Right Panel - Status & Actions -->
                        <div class="w-64 border-l border-[var(--border-subtle)] bg-[var(--surface-secondary)]/30 overflow-y-auto shrink-0">
                            <div class="p-4 space-y-6">
                                <!-- Status Workflow -->
                                <div>
                                    <label class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wider mb-3 block">Status</label>
                                    <div class="space-y-1.5">
                                        <button
                                            v-for="status in workflowStatuses"
                                            :key="status"
                                            @click="updateStatus(status)"
                                            :disabled="isUpdatingStatus"
                                            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all text-left"
                                            :class="getStatusValue(localTask) === status
                                                ? 'bg-[var(--interactive-primary)] text-white shadow-sm'
                                                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)]'"
                                        >
                                            <component
                                                :is="statusConfig[status]?.icon || Circle"
                                                class="w-4 h-4"
                                                :class="getStatusValue(localTask) === status ? 'text-white' : 'text-[var(--text-muted)]'"
                                            />
                                            {{ statusConfig[status]?.label || status }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Divider -->
                                <div class="h-px bg-[var(--border-subtle)]"></div>

                                <!-- Quick Actions -->
                                <div>
                                    <label class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wider mb-3 block">Actions</label>
                                    <div class="space-y-1.5">
                                        <button
                                            class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] transition-all text-left"
                                            @click="emit('edit-task', localTask)"
                                        >
                                            <Edit3 class="w-4 h-4 text-[var(--text-muted)]" />
                                            Edit Task
                                        </button>
                                        <button class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] transition-all text-left opacity-50 cursor-not-allowed" disabled>
                                            <Paperclip class="w-4 h-4 text-[var(--text-muted)]" />
                                            Attach Files
                                        </button>
                                        <button class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] transition-all text-left opacity-50 cursor-not-allowed" disabled>
                                            <RotateCcw class="w-4 h-4 text-[var(--text-muted)]" />
                                            Create Subtask
                                        </button>
                                    </div>
                                </div>

                                <!-- Task Meta -->
                                <div class="pt-4 border-t border-[var(--border-subtle)]">
                                    <div class="text-xs text-[var(--text-muted)] space-y-1.5">
                                        <p v-if="localTask.created_at">Created {{ timeAgo(localTask.created_at) }}</p>
                                        <p v-if="localTask.updated_at">Updated {{ timeAgo(localTask.updated_at) }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-else class="flex flex-col items-center justify-center py-20">
                    <DialogTitle class="sr-only">Loading Task</DialogTitle>
                    <DialogDescription class="sr-only">Loading task details</DialogDescription>
                    <div class="w-8 h-8 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin mb-4"></div>
                    <p class="text-sm text-[var(--text-muted)]">Loading task...</p>
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>

    <!-- Backdrop for assign dropdown -->
    <Teleport to="body">
        <div v-if="showAssignDropdown" class="fixed inset-0 z-[1045]" @click="showAssignDropdown = false"></div>
    </Teleport>
</template>

<style scoped>
/* Smooth scrollbar */
::-webkit-scrollbar {
    width: 6px;
}
::-webkit-scrollbar-track {
    background: transparent;
}
::-webkit-scrollbar-thumb {
    background: var(--border-default);
    border-radius: 3px;
}
::-webkit-scrollbar-thumb:hover {
    background: var(--border-strong);
}
</style>
