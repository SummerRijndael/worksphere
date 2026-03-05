<script setup lang="ts">
import { ref, computed, watch, onMounted } from "vue";
import { Modal, Button, Input, Textarea, SelectFilter } from "@/components/ui";
import { useForm } from "vee-validate";
import { toTypedSchema } from "@vee-validate/zod";
import * as z from "zod";
import axios from "axios";
import { toast } from "vue-sonner";
import { useAuthStore } from "@/stores/auth";

interface Props {
    open: boolean;
    project?: any; // If editing
}

const props = defineProps<Props>();
const emit = defineEmits(["update:open", "saved"]);

const authStore = useAuthStore();
// const currentTeamId removed as it is no longer used
const isEditing = computed(() => !!props.project);
const isLoading = ref(false);
const clients = ref<any[]>([]);

const isOpen = computed({
    get: () => props.open,
    set: (val) => emit("update:open", val),
});

const schema = toTypedSchema(
    z.object({
        name: z.string().min(1, "Name is required").max(255),
        description: z.string().optional(),
        team_id: z.string().min(1, "Team is required"),
        client_id: z.string().nullable().optional(),
        status: z.string().min(1, "Status is required"),
        priority: z.string().min(1, "Priority is required"),
        start_date: z.string().optional(),
        due_date: z.string().optional(),
        budget: z
            .number()
            .min(0, "Budget must be at least 0")
            .max(9999999999.99, "Budget cannot exceed 9,999,999,999.99")
            .optional(),
    }),
);

const { setValues, resetForm, errors, handleSubmit, defineField } = useForm({
    validationSchema: schema,
    initialValues: {
        status: "active",
        priority: "medium",
        budget: 0,
    },
});

const [name, nameProps] = defineField("name");
const [description, descriptionProps] = defineField("description");
const [team_id, team_idProps] = defineField("team_id");
const [client_id, client_idProps] = defineField("client_id");
const [status, statusProps] = defineField("status");
const [priority, priorityProps] = defineField("priority");
const [start_date, start_dateProps] = defineField("start_date");
const [due_date, due_dateProps] = defineField("due_date");
const [budget, budgetProps] = defineField("budget");

const statusOptions = [
    { value: "draft", label: "Draft" },
    { value: "active", label: "Active" },
    { value: "on_hold", label: "On Hold" },
    { value: "completed", label: "Completed" },
    { value: "cancelled", label: "Cancelled" },
    { value: "archived", label: "Archived" },
];

const priorityOptions = [
    { value: "low", label: "Low" },
    { value: "medium", label: "Medium" },
    { value: "high", label: "High" },
    { value: "urgent", label: "Urgent" },
];

// Fetch clients
const fetchClients = async () => {
    try {
        const teamId = team_id.value;
        if (!teamId) {
            clients.value = [];
            return;
        }

        const response = await axios.get(`/api/teams/${teamId}/clients`);
        clients.value = response.data.data || [];
    } catch (e) {
        console.error("Failed to fetch clients", e);
        clients.value = [];
    }
};

watch(
    () => team_id.value,
    (newVal) => {
        if (newVal) {
            // Clear client selection if team changes and client doesn't belong to new team (simple check: just clear it)
            // Unless we are in edit mode and initializing
            if (
                !isEditing.value ||
                (isEditing.value && newVal !== props.project?.team_id)
            ) {
                client_id.value = "";
            }
            fetchClients();
        } else {
            clients.value = [];
        }
    },
);

const fetchTeams = async () => {
    if ((authStore.user?.teams?.length ?? 0) > 0) return; // Already have teams
    try {
        await authStore.fetchUser(); // Refresh user to get latest teams
    } catch (e) {
        console.error("Failed to fetch teams", e);
    }
};

const clientOptions = computed(() => {
    return clients.value.map((c) => ({ value: c.public_id, label: c.name }));
});

const teamOptions = computed(() => {
    if (!authStore.user?.teams) return [];

    return authStore.user.teams
        .filter((t) => {
            // Super admin check
            if (authStore.isSuperAdmin) return true; // Assuming isSuperAdmin is exposed

            const perms = authStore.user?.team_permissions?.[t.public_id] || [];
            return perms.includes("projects.create");
        })
        .map((t) => ({ value: t.public_id, label: t.name }));
});

watch(
    () => props.project,
    (newProject) => {
        if (newProject) {
            setValues({
                name: newProject.name,
                description: newProject.description || "",
                team_id: newProject.team_id,
                client_id:
                    newProject.client?.id || newProject.client?.public_id || "",
                status:
                    newProject.status?.value || newProject.status || "active",
                priority:
                    newProject.priority?.value ||
                    newProject.priority ||
                    "medium",
                start_date: newProject.start_date
                    ? newProject.start_date.split("T")[0]
                    : "",
                due_date: newProject.due_date
                    ? newProject.due_date.split("T")[0]
                    : "",
                budget: Number(newProject.budget) || 0,
            });
        } else {
            resetForm();
            const defaultTeamId =
                authStore.currentTeam?.public_id ||
                (authStore.user?.teams?.length === 1
                    ? authStore.user.teams[0].public_id
                    : "");
            setValues({
                name: "",
                description: "",
                team_id: defaultTeamId,
                client_id: "",
                status: "active",
                priority: "medium",
                start_date: "",
                due_date: "",
                budget: 0,
            });
        }
    },
    { immediate: true },
);

watch(
    () => props.open,
    (val) => {
        if (val) {
            fetchClients();
            fetchTeams();
        }
    },
    { immediate: true },
);

onMounted(() => {
    fetchClients();
    fetchTeams();
});

const onSubmit = handleSubmit(async (values) => {
    const selectedTeamId = values.team_id;
    if (!selectedTeamId) {
        toast.error("Please select a team.");
        return;
    }

    try {
        isLoading.value = true;

        const payload = {
            ...values,
        };

        if (isEditing.value && props.project) {
            const response = await axios.put(
                `/api/teams/${selectedTeamId}/projects/${props.project.public_id}`,
                payload,
            );
            emit("saved", response.data.data);
            toast.success("Project updated successfully");
        } else {
            const response = await axios.post(
                `/api/teams/${selectedTeamId}/projects`,
                payload,
            );
            emit("saved", response.data.data);
            toast.success("Project created successfully");
        }

        isOpen.value = false;
    } catch (err: any) {
        console.error("Failed to save project", err);
        // Handle validation errors from backend if any
        if (err.response?.status === 422 && err.response?.data?.errors) {
            const backendErrors = err.response.data.errors;
            Object.keys(backendErrors).forEach((key) => {
                toast.error(backendErrors[key][0]);
            });
        } else {
            toast.error(
                err.response?.data?.message || "Failed to save project",
            );
        }
    } finally {
        isLoading.value = false;
    }
});
</script>

<template>
    <Modal
        v-model:open="isOpen"
        :title="isEditing ? 'Edit Project' : 'Create New Project'"
        size="lg"
    >
        <template #default>
            <form
                id="project-form"
                @submit.prevent="onSubmit"
                class="space-y-4 py-2"
            >
                <div class="space-y-2">
                    <label
                        class="block text-sm font-medium text-[var(--text-primary)]"
                        >Project Name <span class="text-red-500">*</span></label
                    >
                    <Input
                        v-bind="nameProps"
                        v-model="name"
                        placeholder="Enter project name"
                        :error="errors.name"
                    />
                </div>

                <div class="space-y-2">
                    <label
                        class="block text-sm font-medium text-[var(--text-primary)]"
                        >Description</label
                    >
                    <Textarea
                        v-bind="descriptionProps"
                        v-model="description"
                        placeholder="Describe the project..."
                        rows="3"
                    />
                </div>

                <div
                    v-if="!isEditing && teamOptions.length > 0"
                    class="space-y-2"
                >
                    <label
                        class="block text-sm font-medium text-[var(--text-primary)]"
                        >Team <span class="text-red-500">*</span></label
                    >
                    <SelectFilter
                        v-bind="team_idProps"
                        v-model="team_id"
                        :options="teamOptions"
                        placeholder="Select team"
                    />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)]"
                            >Client</label
                        >
                        <SelectFilter
                            v-bind="client_idProps"
                            v-model="client_id"
                            :options="clientOptions"
                            placeholder="Select client"
                            searchable
                        />
                    </div>
                    <div class="space-y-2">
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)]"
                            >Budget</label
                        >
                        <Input
                            v-bind="budgetProps"
                            type="number"
                            v-model="budget"
                            placeholder="0.00"
                            :error="errors.budget"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)]"
                            >Status</label
                        >
                        <SelectFilter
                            v-bind="statusProps"
                            v-model="status"
                            :options="statusOptions"
                            placeholder="Select status"
                        />
                    </div>
                    <div class="space-y-2">
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)]"
                            >Priority</label
                        >
                        <SelectFilter
                            v-bind="priorityProps"
                            v-model="priority"
                            :options="priorityOptions"
                            placeholder="Select priority"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)]"
                            >Start Date</label
                        >
                        <Input
                            v-bind="start_dateProps"
                            type="date"
                            v-model="start_date"
                        />
                    </div>
                    <div class="space-y-2">
                        <label
                            class="block text-sm font-medium text-[var(--text-primary)]"
                            >Due Date</label
                        >
                        <Input
                            v-bind="due_dateProps"
                            type="date"
                            v-model="due_date"
                        />
                    </div>
                </div>
            </form>
        </template>

        <template #footer>
            <Button variant="outline" @click="isOpen = false">Cancel</Button>
            <Button :loading="isLoading" @click="onSubmit">
                {{ isEditing ? "Save Changes" : "Create Project" }}
            </Button>
        </template>
    </Modal>
</template>
