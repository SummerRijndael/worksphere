<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
    >
        <div
            class="bg-(--surface-primary) border border-(--border-muted) w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]"
        >
            <!-- Header -->
            <div class="flex justify-between items-center px-6 pt-6 pb-4 shrink-0">
                <h2 class="text-xl font-bold">Schedule Meeting</h2>
                <button
                    @click="$emit('close')"
                    class="text-(--text-muted) hover:text-(--text-primary) transition-colors"
                >
                    <Icon name="x" size="20" />
                </button>
            </div>

            <!-- Scrollable Body -->
            <div class="overflow-y-auto px-6 pb-2 space-y-4 flex-1">
                <form @submit.prevent="submit" id="create-meeting-form" class="space-y-4">
                    <!-- Title -->
                    <div>
                        <label class="block text-sm font-semibold mb-1">Meeting Title</label>
                        <input
                            v-model="form.title"
                            type="text"
                            placeholder="e.g. Weekly Sync"
                            class="w-full bg-(--surface-tertiary) border-(--border-muted) rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-(--color-primary-500)/50 transition-all border"
                            required
                        />
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-semibold mb-1">Description (Optional)</label>
                        <textarea
                            v-model="form.description"
                            rows="2"
                            placeholder="What's this meeting about?"
                            class="w-full bg-(--surface-tertiary) border-(--border-muted) rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-(--color-primary-500)/50 transition-all border resize-none"
                        ></textarea>
                    </div>

                    <!-- Date & Time -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold mb-1">Date</label>
                            <input
                                v-model="form.date"
                                type="date"
                                class="w-full bg-(--surface-tertiary) border-(--border-muted) rounded-lg p-2.5 border"
                                required
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1">Time</label>
                            <input
                                v-model="form.time"
                                type="time"
                                class="w-full bg-(--surface-tertiary) border-(--border-muted) rounded-lg p-2.5 border"
                                required
                            />
                        </div>
                    </div>

                    <!-- Security -->
                    <div class="space-y-3 pt-1">
                        <div class="flex items-center justify-between">
                            <label class="text-sm font-semibold">Security</label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input
                                    v-model="form.auto_generate_password"
                                    type="checkbox"
                                    class="w-4 h-4 rounded border-(--border-muted) text-(--color-primary-600) focus:ring-(--color-primary-500)"
                                />
                                <span class="text-xs text-(--text-secondary)">Auto-generate password</span>
                            </label>
                        </div>

                        <div v-if="!form.auto_generate_password" class="space-y-2">
                            <input
                                v-model="form.password"
                                type="text"
                                placeholder="Set meeting password (optional)"
                                class="w-full bg-(--surface-tertiary) border-(--border-muted) rounded-lg p-2.5 outline-none focus:ring-2 focus:ring-(--color-primary-500)/50 transition-all border text-sm"
                            />
                            <!-- Password Strength Meter -->
                            <div v-if="form.password" class="space-y-1">
                                <div class="flex gap-1 h-1.5 w-full rounded-full overflow-hidden bg-(--surface-tertiary)">
                                    <div :class="passwordStrength.score >= 1 ? passwordStrength.color : 'bg-transparent'" class="h-full flex-1 transition-colors"></div>
                                    <div :class="passwordStrength.score >= 2 ? passwordStrength.color : 'bg-transparent'" class="h-full flex-1 transition-colors"></div>
                                    <div :class="passwordStrength.score >= 3 ? passwordStrength.color : 'bg-transparent'" class="h-full flex-1 transition-colors"></div>
                                    <div :class="passwordStrength.score >= 4 ? passwordStrength.color : 'bg-transparent'" class="h-full flex-1 transition-colors"></div>
                                </div>
                                <div class="flex justify-between items-center text-[10px]">
                                    <span class="text-(--text-muted)">Must contain 8+ characters, mixed case, and numbers</span>
                                    <span :class="[passwordStrength.color.replace('bg-', 'text-'), passwordStrength.color.replace('bg-', 'bg-').replace('500', '500/10')]" class="font-medium px-1.5 py-0.5 rounded-sm">{{ passwordStrength.label }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="bg-blue-500/10 border border-blue-500/20 rounded-lg p-3 flex items-center gap-3">
                            <Icon name="lock" size="16" class="text-blue-500 shrink-0" />
                            <span class="text-xs text-blue-500 font-medium">A secure password will be generated automatically.</span>
                        </div>
                    </div>

                    <!-- Access Settings -->
                    <div class="space-y-3 pt-1">
                        <label class="text-sm font-semibold">Access</label>

                        <!-- Allow External Guests toggle -->
                        <label class="flex items-center justify-between cursor-pointer group">
                            <div class="flex items-center gap-2">
                                <Icon name="globe" size="16" class="text-(--text-muted)" />
                                <span class="text-sm">Allow external guests</span>
                            </div>
                            <div class="relative inline-flex items-center">
                                <input
                                    v-model="form.guest_access"
                                    type="checkbox"
                                    class="sr-only peer"
                                    @change="onGuestAccessChange"
                                />
                                <div class="w-9 h-5 bg-(--surface-tertiary) peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-(--color-primary-600) border border-(--border-muted)"></div>
                            </div>
                        </label>
                        <p v-if="form.guest_access" class="text-[11px] text-(--text-muted) ml-6">
                            Anyone with the link can join without a WorkSphere account.
                        </p>

                        <!-- Waiting Lobby toggle -->
                        <label class="flex items-center justify-between cursor-pointer group">
                            <div class="flex items-center gap-2">
                                <Icon name="door-open" size="16" class="text-(--text-muted)" />
                                <span class="text-sm">Waiting lobby</span>
                            </div>
                            <div class="relative inline-flex items-center">
                                <input
                                    v-model="form.lobby_enabled"
                                    type="checkbox"
                                    class="sr-only peer"
                                />
                                <div class="w-9 h-5 bg-(--surface-tertiary) peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-(--color-primary-600) border border-(--border-muted)"></div>
                            </div>
                        </label>
                        <p v-if="form.lobby_enabled" class="text-[11px] text-(--text-muted) ml-6">
                            Participants wait until the host admits them.
                        </p>

                        <!-- Require host/co-host before entry -->
                        <label class="flex items-center justify-between cursor-pointer group">
                            <div class="flex items-center gap-2">
                                <Icon name="shield" size="16" class="text-(--text-muted)" />
                                <span class="text-sm">Only allow join after host/co-host enters</span>
                            </div>
                            <div class="relative inline-flex items-center">
                                <input
                                    v-model="form.require_host_or_cohost_present"
                                    type="checkbox"
                                    class="sr-only peer"
                                />
                                <div class="w-9 h-5 bg-(--surface-tertiary) peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-(--color-primary-600) border border-(--border-muted)"></div>
                            </div>
                        </label>
                        <p v-if="form.require_host_or_cohost_present" class="text-[11px] text-(--text-muted) ml-6">
                            Participants can join only after a host or co-host is already inside the room.
                        </p>
                    </div>

                    <!-- Save to Calendar toggle -->
                    <div class="rounded-xl border border-(--border-muted) bg-(--surface-secondary)/50 p-4 space-y-3">
                        <label class="flex items-center justify-between cursor-pointer">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-(--color-primary-500)/10 flex items-center justify-center shrink-0">
                                    <Icon name="calendar" size="16" class="text-(--color-primary-500)" />
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-(--text-primary)">Save to Calendar</p>
                                    <p class="text-xs text-(--text-muted)">Add this meeting to your calendar</p>
                                </div>
                            </div>
                            <div class="relative inline-flex items-center">
                                <input
                                    v-model="form.save_to_calendar"
                                    type="checkbox"
                                    class="sr-only peer"
                                />
                                <div class="w-9 h-5 bg-(--surface-tertiary) peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-(--color-primary-600) border border-(--border-muted)"></div>
                            </div>
                        </label>

                        <!-- Notification Reminder (Shown when Save to Calendar is ON) -->
                        <transition name="slide-down">
                            <div v-if="form.save_to_calendar" class="pt-3 border-t border-(--border-muted) flex items-center justify-between">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input
                                        v-model="form.enable_reminder"
                                        type="checkbox"
                                        class="w-4 h-4 rounded border-(--border-muted) text-(--color-primary-600) focus:ring-(--color-primary-500)"
                                    />
                                    <Icon name="bell" size="14" class="text-(--text-muted)" />
                                    <span class="text-sm text-(--text-secondary)">Add reminder notification</span>
                                </label>
                                <select
                                    v-if="form.enable_reminder"
                                    v-model="form.reminder_minutes_before"
                                    class="bg-(--surface-tertiary) border border-(--border-muted) rounded px-2 py-1 text-xs outline-none focus:ring-1 focus:ring-(--color-primary-500)"
                                >
                                    <option :value="5">5 mins before</option>
                                    <option :value="10">10 mins before</option>
                                    <option :value="15">15 mins before</option>
                                    <option :value="30">30 mins before</option>
                                    <option :value="60">1 hour before</option>
                                    <option :value="1440">1 day before</option>
                                </select>
                            </div>
                        </transition>
                    </div>

                    <!-- Invite Participants toggle + selector -->
                    <div class="rounded-xl border border-(--border-muted) bg-(--surface-secondary)/50 p-4 space-y-3">
                        <label class="flex items-center justify-between cursor-pointer">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-(--color-primary-500)/10 flex items-center justify-center shrink-0">
                                    <Icon name="users" size="16" class="text-(--color-primary-500)" />
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-(--text-primary)">Invite Participants</p>
                                    <p class="text-xs text-(--text-muted)">Add people or email addresses</p>
                                </div>
                            </div>
                            <div class="relative inline-flex items-center">
                                <input
                                    v-model="form.invite_participants"
                                    type="checkbox"
                                    class="sr-only peer"
                                />
                                <div class="w-9 h-5 bg-(--surface-tertiary) peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-(--color-primary-600) border border-(--border-muted)"></div>
                            </div>
                        </label>

                        <!-- Participant combobox (shown when toggle is on) -->
                        <transition name="slide-down">
                            <div v-if="form.invite_participants" class="space-y-3">
                                <ParticipantSelector
                                    v-model="form.participants"
                                    fetch-url="/api/directory/search"
                                    :allow-external="form.guest_access"
                                    placeholder="Search people or enter email..."
                                    :max="25"
                                />

                                <!-- External email rejection warning -->
                                <div
                                    v-if="hasExternalParticipants && !form.guest_access"
                                    class="flex items-start gap-2 bg-red-500/10 border border-red-500/20 rounded-lg p-3"
                                >
                                    <Icon name="alert-triangle" size="14" class="text-red-500 shrink-0 mt-0.5" />
                                    <p class="text-xs text-red-500 font-medium">
                                        External email guests are not allowed when "Allow external guests" is off. 
                                        Enable it or remove the external addresses.
                                    </p>
                                </div>

                                <!-- External guest info -->
                                <p v-else-if="form.guest_access && hasExternalParticipants" class="text-xs text-(--text-muted)">
                                    <Icon name="info" size="12" class="inline-block text-amber-500 mr-1" />
                                    External guests will receive an invitation email and join via a public link.
                                </p>

                                <!-- Send invite toggle -->
                                <label
                                    v-if="form.participants.length > 0 && form.save_to_calendar"
                                    class="flex items-center gap-2 cursor-pointer"
                                >
                                    <input
                                        v-model="form.send_invite"
                                        type="checkbox"
                                        class="w-4 h-4 rounded border-(--border-muted) text-(--color-primary-600) focus:ring-(--color-primary-500)"
                                    />
                                    <Icon name="mail" size="14" class="text-(--text-muted)" />
                                    <span class="text-sm text-(--text-secondary)">Send email invitations</span>
                                </label>
                                <p v-else-if="form.participants.length > 0 && !form.save_to_calendar" class="text-[11px] text-(--text-muted)">
                                    <Icon name="info" size="12" class="inline-block mr-1" />
                                    Enable "Save to Calendar" to also send email invitations to participants.
                                </p>
                            </div>
                        </transition>
                    </div>
                </form>
            </div>

            <!-- Footer buttons -->
            <div class="flex items-center gap-2 px-6 py-4 shrink-0 border-t border-(--border-muted)">
                <button
                    type="button"
                    @click="$emit('close')"
                    class="flex-1 px-4 py-2.5 bg-(--surface-tertiary) hover:bg-(--border-muted) rounded-lg font-medium transition-colors"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    form="create-meeting-form"
                    :disabled="loading || hasBlockingError"
                    class="flex-1 px-4 py-2.5 bg-(--color-primary-600) hover:bg-(--color-primary-700) text-white rounded-lg font-semibold transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {{ loading ? "Creating..." : "Schedule" }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref, computed, watch } from "vue";
import { meetingService } from "@/services/meeting.service";
import { Icon } from "@/components/ui";
import ParticipantSelector from "@/components/ui/ParticipantSelector.vue";
import { toast } from "vue-sonner";
import dayjs from "dayjs";

const emit = defineEmits(["close", "created"]);

const loading = ref(false);

const form = reactive({
    title: "",
    description: "",
    date: dayjs().format("YYYY-MM-DD"),
    time: dayjs().add(1, "hour").startOf("hour").format("HH:mm"),
    password: "",
    auto_generate_password: true,
    guest_access: false,
    lobby_enabled: true,
    require_host_or_cohost_present: false,
    // New fields
    save_to_calendar: false,
    enable_reminder: false,
    reminder_minutes_before: 15,
    invite_participants: false,
    participants: [] as Array<{ type: 'user' | 'email'; id?: string; email?: string; name?: string; avatar?: string }>,
    send_invite: false,
});

const passwordStrength = computed(() => {
    const pw = form.password;
    if (!pw) return { score: 0, label: 'Weak', color: 'bg-red-500' };
    
    let score = 0;
    if (pw.length >= 8) score++;
    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
    if (/\d/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    
    if (score <= 1) return { score, label: 'Weak', color: 'bg-red-500' };
    if (score === 2) return { score, label: 'Fair', color: 'bg-amber-500' };
    if (score === 3) return { score, label: 'Good', color: 'bg-blue-500' };
    return { score, label: 'Strong', color: 'bg-green-500' };
});

/** True if there are external (email-type) participants */
const hasExternalParticipants = computed(() =>
    form.participants.some((p) => p.type === "email")
);

/** Blocks submission if external guests added but guest_access is off */
const hasBlockingError = computed(
    () => form.invite_participants && hasExternalParticipants.value && !form.guest_access
);

/** When guest_access is turned off, strip any external email participants */
function onGuestAccessChange() {
    if (!form.guest_access) {
        form.participants = form.participants.filter((p) => p.type !== "email");
    }
}

/** When invite_participants is turned off, clear the list */
watch(
    () => form.invite_participants,
    (enabled) => {
        if (!enabled) {
            form.participants = [];
            form.send_invite = false;
        }
    }
);

const submit = async () => {
    if (hasBlockingError.value) return;

    loading.value = true;
    try {
        const start_time = `${form.date}T${form.time}:00`;
        const response = await meetingService.createMeeting({
            title: form.title,
            description: form.description,
            start_time,
            password: form.auto_generate_password ? undefined : form.password,
            auto_generate_password: form.auto_generate_password,
            settings: {
                host_only_cam: false,
                guest_access: form.guest_access,
                lobby_enabled: form.lobby_enabled,
                require_host_or_cohost_present: form.require_host_or_cohost_present,
            },
            save_to_calendar: form.save_to_calendar,
            reminder_minutes_before: form.save_to_calendar && form.enable_reminder ? form.reminder_minutes_before : null,
            send_invite: form.send_invite,
            participants: form.invite_participants ? form.participants : [],
        } as any);

        const msgs = [];
        if (form.save_to_calendar) msgs.push("added to your calendar");
        if (form.send_invite && form.participants.length > 0) msgs.push("invitations sent");

        const suffix = msgs.length > 0 ? ` — ${msgs.join(", ")}` : "";
        toast.success(`Meeting scheduled successfully${suffix}`);
        emit("created", response);
    } catch (error: any) {
        console.error("Failed to create meeting:", error);
        const msg =
            error?.response?.data?.message ||
            error?.response?.data?.errors?.participants?.[0] ||
            "Failed to schedule meeting";
        toast.error(msg);
    } finally {
        loading.value = false;
    }
};
</script>

<style scoped>
.slide-down-enter-active,
.slide-down-leave-active {
    transition: all 0.25s ease;
    overflow: hidden;
}
.slide-down-enter-from,
.slide-down-leave-to {
    max-height: 0;
    opacity: 0;
    transform: translateY(-6px);
}
.slide-down-enter-to,
.slide-down-leave-from {
    max-height: 400px;
    opacity: 1;
    transform: translateY(0);
}
</style>
