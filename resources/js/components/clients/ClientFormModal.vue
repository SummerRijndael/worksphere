<script setup>
import { ref, watch, onMounted, computed } from "vue";
import { Button, Input } from "@/components/ui";
import api from "@/lib/api";
import { useAuthStore } from "@/stores/auth";
import { SelectFilter } from "@/components/ui";
import { useForm } from "vee-validate";
import { toTypedSchema } from "@vee-validate/zod";
import * as z from "zod";

const props = defineProps({
    open: Boolean,
    client: {
        type: Object,
        default: null,
    },
    teamId: {
        type: [String, Number],
        default: null,
    },
});

const emit = defineEmits(["close", "saved"]);

const authStore = useAuthStore();
const isSubmitting = ref(false);
const isLoadingTeams = ref(false);
const availableTeams = ref([]);
const selectedTeamId = ref(null);
const errors = ref({});
const phoneRegex = /^([0-9\s\-\+\(\)]*)$/;

const schema = toTypedSchema(
    z.object({
        name: z.string().min(1, "Company Name is required").max(255),
        email: z
            .string()
            .email("Invalid email")
            .nullable()
            .optional()
            .or(z.literal("")),
        contact_person: z.string().max(255).nullable().optional(),
        phone: z
            .string()
            .max(20, "Phone number cannot exceed 20 characters")
            .regex(phoneRegex, "Invalid phone number format")
            .nullable()
            .optional()
            .or(z.literal("")),
        address: z.string().max(1000).nullable().optional(),
        status: z.enum(["active", "inactive"]),
        team_id: z.string().optional(),
    }),
);

const {
    handleSubmit,
    errors: vErrors,
    setValues,
    resetForm,
    defineField,
} = useForm({
    validationSchema: schema,
    initialValues: {
        status: "active",
    },
});

const [name, nameProps] = defineField("name");
const [email, emailProps] = defineField("email");
const [contact_person, contactPersonProps] = defineField("contact_person");
const [phone, phoneProps] = defineField("phone");
const [address, addressProps] = defineField("address");
const [status, statusProps] = defineField("status");

const formData = ref({
    name: "",
    email: "",
    contact_person: "",
    phone: "",
    address: "",
    status: "active",
});

const canManageAnyTeam = () => {
    return (
        authStore.user?.roles?.some((r) => r.name === "administrator") ||
        authStore.user?.permissions?.some(
            (p) => p.name === "clients.manage_any_team",
        )
    );
};

const hasMultipleTeams = () => {
    return (authStore.user?.teams?.length || 0) > 1;
};

const shouldShowTeamSelector = () => {
    if (props.teamId) return false;
    return canManageAnyTeam() || hasMultipleTeams();
};

const fetchTeams = async () => {
    if (!shouldShowTeamSelector()) return;

    if (canManageAnyTeam()) {
        isLoadingTeams.value = true;
        try {
            const response = await api.get("/api/teams?per_page=100"); // Simple fetch for now
            availableTeams.value = response.data.data.map((t) => ({
                label: t.name,
                value: t.public_id, // Use public_id
            }));
        } catch (e) {
            console.error("Failed to fetch teams", e);
        } finally {
            isLoadingTeams.value = false;
        }
    } else {
        // Use user's teams from auth store, filtered by permission
        const requiredPermission = props.client
            ? "clients.update"
            : "clients.create";

        availableTeams.value = (authStore.user?.teams || [])
            .filter((t) =>
                authStore.hasTeamPermission(t.public_id, requiredPermission),
            )
            .map((t) => ({
                label: t.name,
                value: t.public_id,
            }));

        // Auto-select if no selection (e.g. current selected team if allowed, or first allowed)
        if (!selectedTeamId.value && availableTeams.value.length > 0) {
            const currentIsAllowed = availableTeams.value.some(
                (t) => t.value === authStore.currentTeamId,
            );
            selectedTeamId.value = currentIsAllowed
                ? authStore.currentTeamId
                : availableTeams.value[0].value;
        }
    }
};

// Initialize
onMounted(() => {
    if (shouldShowTeamSelector()) {
        fetchTeams();
    }
});

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            if (props.client) {
                // Edit Mode
                const initial = {
                    name: props.client.name,
                    email: props.client.email || "",
                    contact_person: props.client.contact_person || "",
                    phone: props.client.phone || "",
                    address: props.client.address || "",
                    status: props.client.status,
                };
                setValues(initial);
                formData.value = { ...initial };
                // Use public_id if team relation is loaded, otherwise team_id (fallback)
                selectedTeamId.value =
                    props.client.team?.public_id || props.client.team_id;
            } else {
                // Create Mode
                resetForm();
                formData.value = {
                    name: "",
                    email: "",
                    contact_person: "",
                    phone: "",
                    address: "",
                    status: "active",
                };
                selectedTeamId.value = props.teamId || authStore.currentTeamId; // Default to prop or current team
            }
        }
    },
);

const save = handleSubmit(async (values) => {
    isSubmitting.value = true;

    const data = { ...values };

    // Append team_id if selection is active
    if (shouldShowTeamSelector()) {
        data.team_id = props.teamId || selectedTeamId.value;
    } else if (props.teamId) {
        data.team_id = props.teamId;
    } else if (!canManageAnyTeam() && authStore.currentTeamId) {
        // Default to current team if not selecting
        data.team_id = authStore.currentTeamId;
    }

    try {
        if (props.client) {
            await api.put(`/api/clients/${props.client.public_id}`, data);
        } else {
            await api.post("/api/clients", data);
        }
        emit("saved");
        emit("close");
    } catch (error) {
        if (error.response?.data?.errors) {
            errors.value = error.response.data.errors;
        }
    }
});
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
        @click.self="$emit('close')"
    >
        <div
            class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] shadow-xl w-full max-w-lg overflow-hidden"
        >
            <div class="p-6 border-b border-[var(--border-muted)]">
                <h3 class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ client ? "Edit Client" : "Add New Client" }}
                </h3>
            </div>

            <div class="p-6 space-y-4">
                <!-- Team Selector (Admin or Multi-Team, when not pre-selected) -->
                <div v-if="shouldShowTeamSelector()" class="space-y-1">
                    <label
                        class="text-sm font-medium text-[var(--text-secondary)]"
                        >Team</label
                    >
                    <SelectFilter
                        v-model="selectedTeamId"
                        :options="availableTeams"
                        placeholder="Select Team"
                        class="w-full"
                    />
                    <p
                        v-if="errors.team_id"
                        class="text-xs text-[var(--color-error)]"
                    >
                        {{ errors.team_id[0] }}
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-1 md:col-span-2">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Company Name</label
                        >
                        <Input
                            v-model="name"
                            v-bind="nameProps"
                            placeholder="Company Name"
                            :error="vErrors.name"
                        />
                    </div>

                    <div class="space-y-1">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Contact Person</label
                        >
                        <Input
                            v-model="contact_person"
                            v-bind="contactPersonProps"
                            placeholder="John Doe"
                            :error="vErrors.contact_person"
                        />
                    </div>

                    <div class="space-y-1">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Email</label
                        >
                        <Input
                            v-model="email"
                            v-bind="emailProps"
                            type="email"
                            placeholder="client@example.com"
                            :error="vErrors.email"
                        />
                    </div>

                    <div class="space-y-1">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Phone</label
                        >
                        <Input
                            v-model="phone"
                            v-bind="phoneProps"
                            placeholder="+1 234 567 890"
                            :error="vErrors.phone"
                        />
                    </div>

                    <div class="space-y-1">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Status</label
                        >
                        <select v-model="formData.status" class="input">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <p
                            v-if="errors.status"
                            class="text-xs text-[var(--color-error)]"
                        >
                            {{ errors.status[0] }}
                        </p>
                    </div>

                    <div class="space-y-1 md:col-span-2">
                        <label
                            class="text-sm font-medium text-[var(--text-secondary)]"
                            >Address</label
                        >
                        <textarea
                            v-model="formData.address"
                            rows="2"
                            class="input"
                        ></textarea>
                        <p
                            v-if="errors.address"
                            class="text-xs text-[var(--color-error)]"
                        >
                            {{ errors.address[0] }}
                        </p>
                    </div>
                </div>
            </div>
            <div
                class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3"
            >
                <button @click="$emit('close')" class="btn btn-ghost">
                    Cancel
                </button>
                <Button :loading="isSubmitting" @click="save">
                    {{ client ? "Save Changes" : "Create Client" }}
                </Button>
            </div>
        </div>
    </div>
</template>
