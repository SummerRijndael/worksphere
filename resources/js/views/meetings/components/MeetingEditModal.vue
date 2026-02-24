<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
    >
        <div
            class="bg-(--surface-primary) border border-(--border-muted) w-full max-w-md rounded-2xl shadow-2xl overflow-hidden p-6"
        >
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold">Edit Meeting</h2>
                <button
                    @click="$emit('close')"
                    class="text-(-(--text-muted)) hover:text-(--text-primary)"
                >
                    <Icon name="x" size="20" />
                </button>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="block text-sm font-semibold mb-1"
                        >Meeting Title</label
                    >
                    <input
                        v-model="form.title"
                        type="text"
                        placeholder="e.g. Weekly Sync"
                        class="w-full bg-(--surface-tertiary) border-(--border-muted) rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-(--color-primary-500)/50 transition-all border"
                        required
                    />
                </div>

                <div>
                    <label class="block text-sm font-semibold mb-1"
                        >Description (Optional)</label
                    >
                    <textarea
                        v-model="form.description"
                        rows="3"
                        placeholder="What's this meeting about?"
                        class="w-full bg-(--surface-tertiary) border-(--border-muted) rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-(--color-primary-500)/50 transition-all border resize-none"
                    ></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold mb-1"
                            >Date</label
                        >
                        <input
                            v-model="form.date"
                            type="date"
                            class="w-full bg-(--surface-tertiary) border-(--border-muted) rounded-lg p-2.5 border"
                            required
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1"
                            >Time</label
                        >
                        <input
                            v-model="form.time"
                            type="time"
                            class="w-full bg-(--surface-tertiary) border-(--border-muted) rounded-lg p-2.5 border"
                            required
                        />
                    </div>
                </div>

                <div class="space-y-3 pt-2">
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-semibold">Security</label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input 
                                v-model="form.auto_generate_password" 
                                type="checkbox" 
                                class="w-4 h-4 rounded border-(--border-muted) text-(--color-primary-600) focus:ring-(--color-primary-500)"
                            />
                            <span class="text-xs text-(-(--text-secondary))">Regenerate password</span>
                        </label>
                    </div>
                    
                    <div v-if="!form.auto_generate_password">
                        <input
                            v-model="form.password"
                            type="text"
                            placeholder="Change meeting password (optional)"
                            class="w-full bg-(--surface-tertiary) border-(--border-muted) rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-(--color-primary-500)/50 transition-all border text-sm"
                        />
                        <p v-if="meeting.has_password && !form.password" class="text-[10px] text-(--text-muted) mt-1">Leave blank to keep existing password</p>
                    </div>
                    <div v-else class="bg-blue-500/10 border border-blue-500/20 rounded-lg p-3 flex items-center gap-3">
                        <Icon name="refresh-cw" size="16" class="text-blue-500 shrink-0" />
                        <span class="text-xs text-blue-500 font-medium">A new secure password will be generated.</span>
                    </div>
                </div>

                <!-- Access Settings -->
                <div class="space-y-3 pt-2">
                    <label class="text-sm font-semibold">Access</label>

                    <label class="flex items-center justify-between cursor-pointer">
                        <div class="flex items-center gap-2">
                            <Icon name="globe" size="16" class="text-(--text-muted)" />
                            <span class="text-sm">Allow external guests</span>
                        </div>
                        <input
                            v-model="form.guest_access"
                            type="checkbox"
                            class="w-4 h-4 rounded border-(--border-muted) text-(--color-primary-600) focus:ring-(--color-primary-500)"
                        />
                    </label>
                    <p v-if="form.guest_access" class="text-[11px] text-(--text-muted) ml-6">Anyone with the link can join without a WorkSphere account.</p>

                    <label class="flex items-center justify-between cursor-pointer">
                        <div class="flex items-center gap-2">
                            <Icon name="door-open" size="16" class="text-(--text-muted)" />
                            <span class="text-sm">Waiting lobby</span>
                        </div>
                        <input
                            v-model="form.lobby_enabled"
                            type="checkbox"
                            class="w-4 h-4 rounded border-(--border-muted) text-(--color-primary-600) focus:ring-(--color-primary-500)"
                        />
                    </label>
                    <p v-if="form.lobby_enabled" class="text-[11px] text-(--text-muted) ml-6">Participants wait until the host admits them.</p>
                </div>

                <div class="flex items-center gap-2 pt-4">
                    <button
                        type="button"
                        @click="$emit('close')"
                        class="flex-1 px-4 py-2.5 bg-(--surface-tertiary) hover:bg-(--border-muted) rounded-lg font-medium transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="loading"
                        class="flex-1 px-4 py-2.5 bg-(--color-primary-600) hover:bg-(--color-primary-700) text-white rounded-lg font-semibold transition-all disabled:opacity-50"
                    >
                        {{ loading ? "Saving..." : "Save Changes" }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref, onMounted } from "vue";
import { meetingService, type Meeting } from "@/services/meeting.service";
import { Icon } from "@/components/ui";
import { toast } from "vue-sonner";
import dayjs from "dayjs";

const props = defineProps<{
    meeting: Meeting;
}>();

const emit = defineEmits(["close", "updated"]);

const loading = ref(false);

const form = reactive({
    title: props.meeting.title,
    description: props.meeting.description || "",
    date: dayjs(props.meeting.start_time).format("YYYY-MM-DD"),
    time: dayjs(props.meeting.start_time).format("HH:mm"),
    password: "",
    auto_generate_password: false,
    guest_access: props.meeting.settings?.guest_access ?? false,
    lobby_enabled: props.meeting.settings?.lobby_enabled ?? true,
});

const submit = async () => {
    loading.value = true;
    try {
        const start_time = `${form.date}T${form.time}:00`;
        const updatedMeeting = await meetingService.updateMeeting(props.meeting.public_id, {
            title: form.title,
            description: form.description,
            start_time,
            password: form.password || undefined,
            auto_generate_password: form.auto_generate_password,
            settings: {
                ...props.meeting.settings,
                guest_access: form.guest_access,
                lobby_enabled: form.lobby_enabled,
            },
        });
        toast.success("Meeting updated successfully");
        emit("updated", updatedMeeting);
    } catch (error) {
        console.error("Failed to update meeting:", error);
        toast.error("Failed to update meeting");
    } finally {
        loading.value = false;
    }
};
</script>
