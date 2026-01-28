<script setup lang="ts">
import { ref, computed, watch, nextTick } from "vue";
import { Modal, Button, Input, Textarea, SelectFilter } from "@/components/ui";
import { useForm } from "vee-validate";
import { toTypedSchema } from "@vee-validate/zod";
import * as z from "zod";
import axios from "axios";
import { toast } from "vue-sonner";

interface Props {
    open: boolean;
    task?: any; // If editing
    teamId?: string;
    projectId?: string;
    projectMembers?: any[];
}

import { useAuthStore } from "@/stores/auth";
const authStore = useAuthStore();

const props = withDefaults(defineProps<Props>(), {
    projectMembers: () => [],
});
const emit = defineEmits(["update:open", "task-saved", "close"]);

const isEditing = computed(() => !!props.task);
const isLoading = ref(false);
const isFetchingMembers = ref(false);

const isOpen = computed({
    get: () => props.open,
    set: (val) => {
        emit("update:open", val);
        if (!val) emit("close");
    },
});

const schema = toTypedSchema(
    z.object({
        title: z.string().min(1, "Title is required").max(255),
        description: z.string().optional(),
        status: z.string().min(1, "Status is required"),
        priority: z.number().min(1, "Priority is required"),
        due_date: z.string().optional(),
        assigned_to: z.string().optional(),
        qa_user_id: z.string().optional(),
        estimated_hours: z.number().min(0).optional(),
    }),
);

const { setValues, resetForm } = useForm({
    validationSchema: schema,
    initialValues: {
        status: "open",
        priority: 2,
    },
});

const formValues = ref({
    title: "",
    description: "",
    status: "open",
    priority: 2,
    due_date: "",
    assigned_to: "",
    qa_user_id: "",
    estimated_hours: 0,
    checklist: [] as any[],
    save_as_template: false,
});

// Checklist state
const newChecklistItem = ref("");

const addChecklistItem = () => {
    if (!newChecklistItem.value.trim()) return;
    formValues.value.checklist.push({
        title: newChecklistItem.value.trim(),
        is_completed: false,
    });
    newChecklistItem.value = "";
};

const removeChecklistItem = (index: number) => {
    formValues.value.checklist.splice(index, 1);
};

const resetFormValues = () => {
    setValues({
        status: "open",
        priority: 2,
    });
    formValues.value = {
        title: "",
        description: "",
        status: "open",
        priority: 2,
        due_date: "",
        assigned_to: "",
        qa_user_id: "",
        estimated_hours: 0,
        checklist: [],
        save_as_template: false,
    };
    newChecklistItem.value = "";
    selectedTemplateId.value = "";
};
// ...

const statusOptions = [
    { value: "open", label: "To Do" },
    { value: "in_progress", label: "In Progress" },
    { value: "on_hold", label: "On Hold" },
    { value: "submitted", label: "Submitted" },
    { value: "in_qa", label: "In QA" },
    { value: "approved", label: "QA Approved" },
    { value: "rejected", label: "QA Rejected" },
    { value: "pm_review", label: "PM Review" },
    { value: "sent_to_client", label: "Sent to Client" },
    { value: "client_approved", label: "Client Approved" },
    { value: "client_rejected", label: "Client Rejected" },
    { value: "completed", label: "Done" },
];

const priorityOptions = [
    { value: 1, label: "Low" },
    { value: 2, label: "Medium" },
    { value: 3, label: "High" },
    { value: 4, label: "Urgent" },
];

const localMembers = ref<any[]>([]);

const QA_ROLES = ["subject_matter_expert", "quality_assessor", "team_lead", "manager", "owner", "admin", "administrator"];

const operatorMemberOptions = computed(() => {
    const list = props.projectMembers && props.projectMembers.length > 0
        ? props.projectMembers
        : localMembers.value;

    return list.map((m: any) => ({
        value: m.public_id || m.id,
        label: m.name,
        avatar: m.avatar_url,
        subtitle: (m.team_role || m.role)?.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()) || m.email
    }));
});

const qaMemberOptions = computed(() => {
    const list = props.projectMembers && props.projectMembers.length > 0
        ? props.projectMembers
        : localMembers.value;

    return list
        .filter((m: any) => {
            return QA_ROLES.includes(m.role) || QA_ROLES.includes(m.team_role);
        })
        .map((m: any) => ({
            value: m.public_id || m.id,
            label: m.name,
            avatar: m.avatar_url,
            subtitle: (m.team_role || m.role)?.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()) || m.email
        }));
});

// For backward compatibility or internal usage if needed
const memberOptions = operatorMemberOptions;

// Dynamic State for Selectors
const selectedTeamId = ref("");
const selectedProjectId = ref("");
const projectOptions = ref<any[]>([]);

const teamOptions = computed(() => {
    return (
        authStore.user?.teams?.map((team) => ({
            value: team.public_id,
            label: team.name,
        })) || []
    );
});

// Fetch projects when team changes
const fetchProjects = async () => {
    if (!selectedTeamId.value) return;
    try {
        const response = await axios.get(
            `/api/teams/${selectedTeamId.value}/projects`,
        );
        projectOptions.value = response.data.data.map((p: any) => ({
            value: p.public_id,
            label: p.name,
        }));
    } catch (error) {
        console.error("Failed to fetch projects", error);
    }
};

const fetchMembers = async () => {
    // If members are already provided via props, don't fetch
    if (props.projectMembers && props.projectMembers.length > 0) {
        return;
    }

    // Need both team and project to fetch
    if (!selectedTeamId.value || !selectedProjectId.value) return;

    try {
        isFetchingMembers.value = true;
        
        const response = await axios.get(
            `/api/teams/${selectedTeamId.value}/projects/${selectedProjectId.value}`,
        );
        
        localMembers.value = response.data.data?.members || [];
        console.log("TaskFormModal: Fetched project members:", localMembers.value);
    } catch (error) {
        console.error("Failed to fetch project members", error);
    } finally {
        isFetchingMembers.value = false;
    }
};

// Watch team changes - only when user manually changes team (not from props)
watch(
    () => selectedTeamId.value,
    (newVal, oldVal) => {
        // Only reset project and fetch if this is a manual team change (not initial prop sync)
        if (oldVal && newVal !== oldVal) {
            projectOptions.value = [];
            selectedProjectId.value = "";
            localMembers.value = [];
        }
        if (newVal) {
            fetchProjects();
        }
    },
);

// Watch project changes for fetching members
watch(
    () => selectedProjectId.value,
    (newVal, oldVal) => {
        if (newVal && newVal !== oldVal) {
            localMembers.value = [];
            fetchMembers();
        }
    },
);

// Templates Logic
import {
    taskTemplateService,
    type TaskTemplate,
} from "@/services/task-template.service";

const templates = ref<TaskTemplate[]>([]);
const selectedTemplateId = ref("");

const templateOptions = computed(() => {
    return templates.value.map((t) => ({
        value: t.public_id,
        label: t.name,
    }));
});

const fetchTemplates = async () => {
    if (!selectedTeamId.value) return;
    try {
        const data = await taskTemplateService.getAll(selectedTeamId.value);
        templates.value = data;
    } catch (error) {
        console.error("Failed to fetch templates", error);
    }
};

watch(
    () => selectedTeamId.value,
    (newVal) => {
        if (newVal) {
            fetchTemplates();
        } else {
            templates.value = [];
        }
    },
);

// Apply template
watch(
    () => selectedTemplateId.value,
    (newVal) => {
        const template = templates.value.find((t) => t.public_id === newVal);
        if (template) {
            setValues({
                status: "open",
                priority:
                    template.default_priority === "low"
                        ? 1
                        : template.default_priority === "medium"
                          ? 2
                          : template.default_priority === "high"
                            ? 3
                            : template.default_priority === "urgent"
                              ? 4
                              : 2,
            });

            // Update formValues
            formValues.value.title = template.name.replace(" (Template)", "");
            formValues.value.description =
                template.description || formValues.value.description;
            formValues.value.priority =
                template.default_priority === "low"
                    ? 1
                    : template.default_priority === "medium"
                      ? 2
                      : template.default_priority === "high"
                        ? 3
                        : template.default_priority === "urgent"
                          ? 4
                          : 2;
            formValues.value.estimated_hours =
                template.default_estimated_hours || 0;

            // Clone checklist
            if (template.checklist_template) {
                formValues.value.checklist = JSON.parse(
                    JSON.stringify(template.checklist_template),
                );
            }

            toast.success("Template loaded");
        }
    },
);

// Initialize modal state when opened
const initializeModal = async () => {
    // Set team and project from props synchronously
    if (props.teamId) {
        selectedTeamId.value = props.teamId;
    } else if (!selectedTeamId.value && authStore.user?.teams?.length === 1) {
        // Auto-select if user has only one team
        selectedTeamId.value = authStore.user.teams[0].public_id;
    }

    if (props.projectId) {
        selectedProjectId.value = props.projectId;
    }

    // Fetch projects list if we have a team selected but no projects yet
    if (selectedTeamId.value && projectOptions.value.length === 0) {
        await fetchProjects();
    }

    // Fetch templates if we have a team
    if (selectedTeamId.value && templates.value.length === 0) {
        await fetchTemplates();
    }

    // Fetch members if we have both IDs and no members provided via props
    if (
        selectedTeamId.value &&
        selectedProjectId.value &&
        (!props.projectMembers || props.projectMembers.length === 0)
    ) {
        await fetchMembers();
    }
};

watch(
    () => props.open,
    async (isOpenVal) => {
        if (isOpenVal) {
            await nextTick();
            await initializeModal();
        }
    },
    { immediate: true },
);

watch(
    () => props.task,
    (newTask) => {
        if (newTask) {
            // Extract status and priority values - they can be objects or strings
            const statusValue =
                typeof newTask.status === "object"
                    ? newTask.status?.value
                    : newTask.status;
            const priorityValue =
                typeof newTask.priority === "object"
                    ? newTask.priority?.value
                    : newTask.priority;

            setValues({
                title: newTask.title,
                description: newTask.description,
                status: statusValue || "open",
                priority: priorityValue || 2,
                due_date: newTask.due_date
                    ? new Date(newTask.due_date).toISOString()
                    : "",
                assigned_to: newTask.assignee?.id || newTask.assigned_to || "",
                estimated_hours: Number(newTask.estimated_hours) || 0,
            });

            formValues.value = {
                title: newTask.title,
                description: newTask.description || "",
                status: statusValue || "open",
                priority: priorityValue || 2,
                due_date: newTask.due_date
                    ? new Date(newTask.due_date).toISOString().split("T")[0]
                    : "",
                assigned_to: newTask.assignee?.id || newTask.assigned_to || "",
                qa_user_id:
                    newTask.qa_user?.id || newTask.qa_user_id || "",
                estimated_hours: Number(newTask.estimated_hours) || 0,
                checklist:
                    newTask.checklist?.map((item: any) => ({
                        title: typeof item === "string" ? item : item.title || item.text,
                        is_completed:
                            typeof item === "string"
                                ? false
                                : item.is_completed ||
                                  item.status === "done" ||
                                  false,
                    })) || [],
                save_as_template: false,
            };
        } else {
            resetFormValues();
            formValues.value = {
                title: "",
                description: "",
                status: "open",
                priority: 2,
                due_date: "",
                assigned_to: "",
                estimated_hours: 0,
                checklist: [],
                save_as_template: false,
            };
        }
    },
    { immediate: true },
);

const onSubmit = async () => {
    // Manual validation
    if (!formValues.value.title) {
        toast.error("Title is required");
        return;
    }

    try {
        isLoading.value = true;

        const payload = {
            ...formValues.value,
            checklist: formValues.value.checklist.map((item) => {
                if (typeof item === "string") {
                    return { title: item, is_completed: false };
                }
                return item;
            }),
        };

        // Resolve team and project IDs with fallbacks
        // Task from API has nested project.team_id and project.id
        const teamId =
            selectedTeamId.value ||
            props.teamId ||
            props.task?.project?.team_id ||
            props.task?.team_id ||
            "";
        const projectId =
            selectedProjectId.value ||
            props.projectId ||
            props.task?.project?.id ||
            props.task?.project?.public_id ||
            props.task?.project_id ||
            "";

        if (!teamId || !projectId) {
            toast.error("Please select a team and project");
            isLoading.value = false;
            return;
        }

        if (isEditing.value && props.task) {
            const taskId = props.task.public_id || props.task.id;
            const response = await axios.put(
                `/api/teams/${teamId}/projects/${projectId}/tasks/${taskId}`,
                payload,
            );
            emit("task-saved", response.data.data);
            toast.success("Task updated successfully");
        } else {
            const response = await axios.post(
                `/api/teams/${teamId}/projects/${projectId}/tasks`,
                payload,
            );
            emit("task-saved", response.data.data);
            toast.success("Task created successfully");
        }

        isOpen.value = false;
    } catch (err: any) {
        console.error("Failed to save task", err);
        toast.error(err.response?.data?.message || "Failed to save task");
    } finally {
        isLoading.value = false;
    }
};
</script>

<template>
    <Modal
        v-model:open="isOpen"
        :title="isEditing ? 'Edit Task' : 'Create New Task'"
        size="xl"
    >
        <template #default>
            <form
                id="task-form"
                @submit.prevent="onSubmit"
                class="flex flex-col md:flex-row gap-6 h-full p-1"
            >
                <!-- Left Column: Main Content -->
                <div class="flex-1 space-y-6 min-w-0">
                    <!-- Template Selector -->
                    <div
                        v-if="!isEditing && templates.length > 0"
                        class="p-3 bg-[var(--surface-secondary)]/50 backdrop-blur-sm rounded-xl border border-[var(--border-subtle)]"
                    >
                        <label
                            class="block text-xs font-semibold uppercase tracking-wider text-[var(--text-secondary)] mb-2"
                            >Load from Template</label
                        >
                        <SelectFilter
                            v-model="selectedTemplateId"
                            :options="templateOptions"
                            placeholder="Select a template..."
                            class="w-full"
                        />
                    </div>

                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label
                                class="block text-sm font-semibold text-[var(--text-primary)]"
                                >Title <span class="text-red-500">*</span></label
                            >
                            <Input
                                v-model="formValues.title"
                                placeholder="Task title"
                                required
                                class="text-lg font-medium"
                            />
                        </div>

                        <div class="space-y-2">
                            <label
                                class="block text-sm font-semibold text-[var(--text-primary)]"
                                >Description</label
                            >
                            <Textarea
                                v-model="formValues.description"
                                placeholder="Describe the task..."
                                rows="6"
                                class="resize-y min-h-[120px]"
                            />
                        </div>
                    </div>

                    <!-- Checklist Section -->
                    <div class="space-y-3 pt-4 border-t border-[var(--border-subtle)]">
                        <div class="flex items-center justify-between">
                            <label
                                class="block text-sm font-semibold text-[var(--text-primary)]"
                                >Checklist</label
                            >
                            <span class="text-xs text-[var(--text-muted)]">{{ formValues.checklist.filter(i => typeof i === 'string' ? false : i.is_completed).length }}/{{ formValues.checklist.length }}</span>
                        </div>

                        <div class="space-y-2">
                             <div class="flex gap-2">
                                <Input
                                    v-model="newChecklistItem"
                                    placeholder="Add sub-item..."
                                    @keydown.enter.prevent="addChecklistItem"
                                    class="flex-1"
                                />
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    @click="addChecklistItem"
                                    >Add</Button
                                >
                            </div>

                            <div
                                v-if="formValues.checklist.length > 0"
                                class="space-y-1 mt-2 max-h-60 overflow-y-auto pr-1"
                            >
                                <div
                                    v-for="(item, index) in formValues.checklist"
                                    :key="index"
                                    class="flex items-center gap-3 p-2 rounded-lg bg-[var(--surface-secondary)]/30 border border-[var(--border-subtle)] hover:border-[var(--border-default)] transition-colors group"
                                >
                                    <!-- Simple checkbox visual for list usage (logic handled in details view usually, but good for visualization here) -->
                                    <div class="w-4 h-4 rounded border border-[var(--border-default)] flex items-center justify-center">
                                        <div v-if="typeof item !== 'string' && item.is_completed" class="w-2 h-2 bg-[var(--text-primary)] rounded-sm"></div>
                                    </div>

                                    <span class="text-sm flex-1 text-[var(--text-secondary)]">{{
                                        typeof item === "string" ? item : item.title
                                    }}</span>
                                    
                                    <button
                                        type="button"
                                        @click="removeChecklistItem(index)"
                                        class="text-[var(--text-muted)] hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity p-1"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            width="14"
                                            height="14"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                        >
                                            <path d="M3 6h18" />
                                            <path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6" />
                                            <path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Sidebar Meta -->
                <div class="w-full md:w-80 space-y-6">
                    <div class="bg-[var(--surface-secondary)]/50 p-5 rounded-2xl border border-[var(--border-subtle)] space-y-5">
                        
                        <!-- Project Context (if creating new) -->
                         <div
                            v-if="!props.projectId && !isEditing"
                            class="space-y-4 pb-4 border-b border-[var(--border-subtle)]"
                        >
                            <div class="space-y-1.5">
                                <label
                                    class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]"
                                    >Team <span class="text-red-500">*</span></label
                                >
                                <SelectFilter
                                    v-model="selectedTeamId"
                                    :options="teamOptions"
                                    placeholder="Select Team"
                                    class="w-full"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]"
                                    >Project <span class="text-red-500">*</span></label
                                >
                                <SelectFilter
                                    v-model="selectedProjectId"
                                    :options="projectOptions"
                                    placeholder="Select Project"
                                    :disabled="!selectedTeamId"
                                    class="w-full"
                                />
                            </div>
                        </div>

                        <!-- Status & Priority -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <label
                                    class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]"
                                    >Status</label
                                >
                                <SelectFilter
                                    v-model="formValues.status"
                                    :options="statusOptions"
                                    placeholder="Status"
                                    class="w-full"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]"
                                    >Priority</label
                                >
                                <SelectFilter
                                    v-model="formValues.priority"
                                    :options="priorityOptions"
                                    placeholder="Priority"
                                    class="w-full"
                                />
                            </div>
                        </div>

                        <!-- People -->
                        <div class="space-y-4">
                            <div class="space-y-1.5">
                                <label
                                    class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]"
                                    >Assignee</label
                                >
                                <div
                                    v-if="isFetchingMembers"
                                    class="h-9 flex items-center justify-center bg-[var(--surface-primary)] rounded-lg border border-[var(--border-default)]"
                                >
                                    <span class="text-xs text-[var(--text-muted)] animate-pulse"
                                        >Loading...</span
                                    >
                                </div>
                                <SelectFilter
                                    v-else
                                    v-model="formValues.assigned_to"
                                    :options="operatorMemberOptions"
                                    :placeholder="operatorMemberOptions.length === 0 ? 'No members' : 'Unassigned'"
                                    :disabled="operatorMemberOptions.length === 0"
                                    class="w-full"
                                    searchable
                                />
                            </div>

                            <div class="space-y-1.5">
                                <label
                                    class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]"
                                    >QA Owner</label
                                >
                                <SelectFilter
                                    v-model="formValues.qa_user_id"
                                    :options="qaMemberOptions"
                                    :placeholder="qaMemberOptions.length === 0 ? 'No members' : 'Unassigned'"
                                    :disabled="qaMemberOptions.length === 0 || isFetchingMembers"
                                    class="w-full"
                                    searchable
                                />
                            </div>
                        </div>

                        <!-- Dates & Estimation -->
                        <div class="space-y-4 pt-4 border-t border-[var(--border-subtle)]">
                            <div class="space-y-1.5">
                                <label
                                    class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]"
                                    >Due Date</label
                                >
                                <Input type="date" v-model="formValues.due_date" class="w-full" />
                            </div>

                            <div class="space-y-1.5">
                                <label
                                    class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]"
                                    >Est. Hours</label
                                >
                                <Input
                                    type="number"
                                    step="0.5"
                                    v-model="formValues.estimated_hours"
                                    placeholder="0"
                                    class="w-full"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Template Save Option -->
                     <div
                        v-if="!isEditing"
                        class="p-3 bg-[var(--surface-secondary)]/30 rounded-lg"
                    >
                        <label
                            class="flex items-start gap-3 cursor-pointer select-none"
                        >
                            <input
                                type="checkbox"
                                v-model="formValues.save_as_template"
                                class="mt-1 rounded border-[var(--border-default)] text-[var(--brand-primary)] focus:ring-[var(--brand-ring)]"
                            />
                            <div class="text-sm">
                                <span class="font-medium text-[var(--text-primary)]">Save as Template</span>
                                <p class="text-xs text-[var(--text-muted)] mt-0.5">Reuse this structure later</p>
                            </div>
                        </label>
                    </div>
                </div>
            </form>
        </template>

        <template #footer>
            <div class="flex justify-end gap-3 w-full">
                <Button variant="ghost" @click="isOpen = false">Cancel</Button>
                <Button :loading="isLoading" @click="onSubmit" class="min-w-[120px]">
                    {{ isEditing ? "Save Changes" : "Create Task" }}
                </Button>
            </div>
        </template>
    </Modal>
</template>
