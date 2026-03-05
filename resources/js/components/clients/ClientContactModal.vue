<script setup>
import { ref, watch, onMounted } from "vue";
import { Button, Checkbox, Input } from "@/components/ui";
import api from "@/lib/api";
import { useAuthStore } from "@/stores/auth";
import { useForm } from "vee-validate";
import { toTypedSchema } from "@vee-validate/zod";
import * as z from "zod";

const props = defineProps({
    open: Boolean,
    contact: {
        type: Object,
        default: null,
    },
    clientPublicId: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(["close", "saved"]);
const authStore = useAuthStore();

const isSubmitting = ref(false);
const phoneRegex = /^([0-9\s\-\+\(\)]*)$/;

const schema = toTypedSchema(
    z.object({
        name: z.string().min(1, "Name is required").max(255),
        email: z
            .string()
            .email("Invalid email")
            .nullable()
            .optional()
            .or(z.literal("")),
        phone: z
            .string()
            .max(20, "Phone number cannot exceed 20 characters")
            .regex(phoneRegex, "Invalid phone number format")
            .nullable()
            .optional()
            .or(z.literal("")),
        role: z.string().max(255).nullable().optional(),
        is_primary: z.boolean().default(false),
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
        is_primary: false,
    },
});

const [name, nameProps] = defineField("name");
const [email, emailProps] = defineField("email");
const [phone, phoneProps] = defineField("phone");
const [role, roleProps] = defineField("role");
const [is_primary, isPrimaryProps] = defineField("is_primary");

const formData = ref({
    name: "",
    email: "",
    phone: "",
    role: "",
    is_primary: false,
});

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            if (props.contact) {
                // Edit Mode
                const initial = {
                    name: props.contact.name,
                    email: props.contact.email || "",
                    phone: props.contact.phone || "",
                    role: props.contact.role || "",
                    is_primary: !!props.contact.is_primary,
                };
                setValues(initial);
            } else {
                // Create Mode
                resetForm();
            }
        }
    },
);

const save = handleSubmit(async (values) => {
    isSubmitting.value = true;

    const data = { ...values };

    try {
        if (props.contact) {
            // Shallow resource update: /teams/{team}/contacts/{contact}
            await api.put(
                `/api/teams/${authStore.currentTeamId}/contacts/${props.contact.id}`,
                data,
            );
        } else {
            // Nested create: /teams/{team}/clients/{client}/contacts
            await api.post(
                `/api/teams/${authStore.currentTeamId}/clients/${props.clientPublicId}/contacts`,
                data,
            );
        }
        emit("saved");
        emit("close");
    } catch (error) {
        if (error.response?.data?.errors) {
            // Handle server-side errors if any (unlikely with zod but good for consistency)
            console.error(error.response.data.errors);
        } else {
            console.error(error);
        }
    } finally {
        isSubmitting.value = false;
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
            class="bg-[var(--surface-primary)] rounded-xl border border-[var(--border-muted)] shadow-xl w-full max-w-md overflow-hidden"
        >
            <div class="p-6 border-b border-[var(--border-muted)]">
                <h3 class="text-lg font-semibold text-[var(--text-primary)]">
                    {{ contact ? "Edit Contact" : "Add New Contact" }}
                </h3>
            </div>

            <div class="p-6 space-y-4">
                <div class="space-y-1">
                    <label
                        class="text-sm font-medium text-[var(--text-secondary)]"
                        >Name</label
                    >
                    <Input
                        v-model="name"
                        v-bind="nameProps"
                        placeholder="John Doe"
                        :error="vErrors.name"
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
                        placeholder="john@example.com"
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
                        >Role / Job Title</label
                    >
                    <Input
                        v-model="role"
                        v-bind="roleProps"
                        placeholder="e.g. Manager"
                        :error="vErrors.role"
                    />
                </div>

                <!-- 
                <div class="flex items-center space-x-2 pt-2">
                    <Checkbox id="is_primary" v-model:checked="formData.is_primary" />
                    <label for="is_primary" class="text-sm font-medium text-[var(--text-primary)] cursor-pointer">
                        Primary Contact
                    </label>
                </div>
                -->
            </div>
            <div
                class="px-6 py-4 bg-[var(--surface-secondary)] flex justify-end gap-3"
            >
                <button @click="$emit('close')" class="btn btn-ghost">
                    Cancel
                </button>
                <Button :loading="isSubmitting" @click="save">
                    {{ contact ? "Save Changes" : "Add Contact" }}
                </Button>
            </div>
        </div>
    </div>
</template>
