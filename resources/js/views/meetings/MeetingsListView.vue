<template>
    <div class="p-6">
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-2xl font-bold text-(--text-primary)">
                    Meetings
                </h1>
                <p class="text-(--text-muted)">
                    Schedule and manage your video conferences
                </p>
            </div>
            <div class="flex gap-3">
                <button
                    @click="startInstantMeeting"
                    :disabled="isCreatingInstant"
                    class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg font-medium transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <Icon
                        :name="isCreatingInstant ? 'loader-2' : 'zap'"
                        :class="{ 'animate-spin': isCreatingInstant }"
                        size="18"
                    />
                    {{ isCreatingInstant ? "Starting..." : "Instant Meeting" }}
                </button>
                <button
                    @click="showCreateModal = true"
                    class="px-4 py-2 bg-(--color-primary-600) hover:bg-(--color-primary-700) text-white rounded-lg font-medium transition-colors flex items-center gap-2"
                >
                    <Icon name="calendar-plus" size="18" />
                    Schedule Meeting
                </button>
            </div>
        </div>

        <!-- Meeting Stats/Overview cards could go here -->

        <div v-if="loading" class="flex justify-center py-20">
            <div
                class="animate-spin rounded-full h-12 w-12 border-b-2 border-(--color-primary-600)"
            ></div>
        </div>

        <div
            v-else-if="!meetings || meetings.length === 0"
            class="bg-(--surface-primary) border border-(--border-muted) rounded-xl p-20 text-center"
        >
            <div
                class="w-20 h-20 bg-(--surface-tertiary) rounded-full flex items-center justify-center mx-auto mb-6"
            >
                <Icon name="video" size="32" class="text-(--text-muted)" />
            </div>
            <h3 class="text-xl font-semibold mb-2">No meetings scheduled</h3>
            <p class="text-(--text-muted) mb-8 max-w-sm mx-auto">
                Host a quick catch-up or schedule a formal discussion with your
                team or external guests.
            </p>
            <button
                @click="showCreateModal = true"
                class="px-6 py-3 bg-(--color-primary-600) text-white rounded-lg font-medium hover:bg-(--color-primary-700) transition-all"
            >
                Create Your First Meeting
            </button>
        </div>

        <div
            v-else
            class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"
        >
            <div
                v-for="meeting in meetings"
                :key="meeting.id"
                class="bg-(--surface-primary) border border-(--border-muted) rounded-xl overflow-hidden hover:shadow-lg transition-shadow group"
            >
                <div class="p-5">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-10 h-10 rounded-lg bg-(--color-primary-500)/10 flex items-center justify-center text-(--color-primary-600)"
                            >
                                <Icon name="video" size="20" />
                            </div>
                            <div>
                                <h3 class="font-bold text-lg leading-tight">
                                    {{ meeting.title }}
                                </h3>
                                <div
                                    class="text-xs text-(-(--text-muted)) font-medium flex items-center gap-1 mt-1"
                                >
                                    <Icon name="clock" size="12" />
                                    {{ formatTime(meeting.start_time) }}
                                </div>
                                <div
                                    v-if="meeting.password"
                                    class="text-[10px] text-blue-500 font-mono mt-1 flex items-center gap-1 bg-blue-500/5 px-2 py-0.5 rounded-full w-fit group/pw"
                                >
                                    <Icon name="lock" size="10" />
                                    <span v-if="revealedPasswords.has(meeting.id)">{{ meeting.password }}</span>
                                    <span v-else>••••••••</span>
                                    
                                    <button 
                                        @click.stop="togglePasswordReveal(meeting.id)"
                                        class="p-0.5 hover:bg-blue-500/20 rounded transition-colors ml-1"
                                        :title="revealedPasswords.has(meeting.id) ? 'Hide Password' : 'Show Password'"
                                    >
                                        <Icon :name="revealedPasswords.has(meeting.id) ? 'eye-off' : 'eye'" size="10" />
                                    </button>
                                    
                                    <button 
                                        @click.stop="copyPassword(meeting)"
                                        class="p-0.5 hover:bg-blue-500/20 rounded transition-colors"
                                        title="Copy Password"
                                    >
                                        <Icon name="copy" size="10" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="relative">
                            <Dropdown align="end" width="48">
                                <template #trigger>
                                    <button
                                        class="p-1 hover:bg-(--surface-tertiary) rounded text-(--text-muted)"
                                    >
                                        <Icon name="more-vertical" size="18" />
                                    </button>
                                </template>

                                <DropdownItem @select="copyLink(meeting)">
                                    <Icon name="copy" size="14" class="mr-2" />
                                    Copy Link
                                </DropdownItem>
                                <DropdownItem
                                    v-if="isOwner(meeting)"
                                    @select="editingMeeting = meeting"
                                >
                                    <Icon name="edit" size="14" class="mr-2" />
                                    Edit Meeting
                                </DropdownItem>
                                <DropdownItem
                                    v-if="isOwner(meeting)"
                                    destructive
                                    @select="cancelMeeting(meeting)"
                                >
                                    <Icon
                                        name="trash-2"
                                        size="14"
                                        class="mr-2"
                                    />
                                    Cancel Meeting
                                </DropdownItem>
                            </Dropdown>
                        </div>
                    </div>

                    <p
                        v-if="meeting.description"
                        class="text-sm text-(--text-secondary) line-clamp-2 mb-4 h-10"
                    >
                        {{ meeting.description }}
                    </p>

                    <div class="flex items-center gap-2 mb-6">
                        <div
                            v-if="meeting.participants?.length"
                            class="flex -space-x-2"
                        >
                            <div
                                v-for="participant in meeting.participants.slice(
                                    0,
                                    3,
                                )"
                                :key="participant.id"
                                class="w-7 h-7 rounded-full border-2 border-(--surface-primary) bg-(--surface-tertiary) flex items-center justify-center overflow-hidden"
                            >
                                <img
                                    v-if="participant.user?.avatar_url"
                                    :src="participant.user.avatar_url"
                                    class="w-full h-full object-cover"
                                />
                                <Icon v-else name="user" size="12" />
                            </div>
                        </div>
                        <span class="text-xs text-(--text-muted)">
                            {{ meeting.participants?.length || 0 }}
                            {{
                                meeting.participants?.length === 1
                                    ? "participant"
                                    : "participants"
                            }}
                        </span>
                    </div>

                    <div class="flex gap-2">
                        <button
                            @click="joinMeeting(meeting)"
                            class="flex-1 py-2 bg-(--color-primary-600) hover:bg-(--color-primary-700) text-white rounded-lg text-sm font-semibold transition-colors"
                        >
                            Join Now
                        </button>
                        <button
                            @click="copyLink(meeting)"
                            class="px-3 py-2 bg-(--surface-tertiary) hover:bg-(--border-muted) text-(--text-primary) rounded-lg transition-colors tooltip"
                            title="Copy Invitation Link"
                        >
                            <Icon name="copy" size="18" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <MeetingCreateModal
            v-if="showCreateModal"
            @close="showCreateModal = false"
            @created="onMeetingCreated"
        />

        <MeetingEditModal
            v-if="editingMeeting"
            :meeting="editingMeeting"
            @close="editingMeeting = null"
            @updated="onMeetingUpdated"
        />

        <!-- Success Dialog -->
        <div v-if="createdMeeting" class="fixed inset-0 z-60 flex items-center justify-center p-4 bg-black/60 backdrop-blur-md">
            <div class="bg-(--surface-primary) border border-(--border-muted) w-full max-w-md rounded-2xl shadow-2xl p-8 flex flex-col items-center text-center">
                <div class="w-16 h-16 bg-emerald-500/10 rounded-full flex items-center justify-center text-emerald-500 mb-6">
                    <Icon name="check-circle" size="32" />
                </div>
                <h2 class="text-2xl font-bold mb-2">Meeting Created!</h2>
                <p class="text-(--text-muted) mb-8">Your meeting is ready. Share the details below with your participants.</p>
                
                <div class="w-full space-y-4 mb-8">
                    <div class="text-left">
                        <label class="text-[10px] uppercase tracking-wider font-bold text-(--text-tertiary) mb-1 block">Invitation Link</label>
                        <div class="flex gap-2">
                            <input 
                                readonly 
                                :value="getMeetingUrl(createdMeeting)" 
                                class="flex-1 bg-(--surface-tertiary) border border-(--border-muted) rounded-lg px-3 py-2 text-sm outline-none"
                            />
                            <button @click="copyLink(createdMeeting)" class="p-2 bg-(--surface-tertiary) border border-(--border-muted) rounded-lg hover:bg-(--border-muted) transition-colors">
                                <Icon name="copy" size="18" />
                            </button>
                        </div>
                    </div>

                    <div v-if="createdMeeting.password" class="text-left">
                        <label class="text-[10px] uppercase tracking-wider font-bold text-(--text-tertiary) mb-1 block">Meeting Password</label>
                        <div class="flex gap-2">
                            <input 
                                readonly 
                                :value="createdMeeting.password" 
                                class="flex-1 bg-(--surface-tertiary) border border-(--border-muted) rounded-lg px-3 py-2 text-sm font-mono outline-none"
                            />
                            <button @click="copyPassword(createdMeeting)" class="p-2 bg-(--surface-tertiary) border border-(--border-muted) rounded-lg hover:bg-(--border-muted) transition-colors">
                                <Icon name="copy" size="18" />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col w-full gap-3">
                    <button @click="joinMeeting(createdMeeting)" class="w-full py-3 bg-(--color-primary-600) hover:bg-(--color-primary-700) text-white rounded-xl font-bold transition-all">
                        Join Meeting Now
                    </button>
                    <button @click="createdMeeting = null" class="w-full py-3 bg-(--surface-tertiary) hover:bg-(--border-muted) rounded-xl font-bold transition-all">
                        Dismiss
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import { meetingService, type Meeting } from "@/services/meeting.service";
import MeetingCreateModal from "./components/MeetingCreateModal.vue";
import MeetingEditModal from "./components/MeetingEditModal.vue";
import { Icon, Dropdown, DropdownItem } from "@/components/ui";
import { toast } from "vue-sonner";
import dayjs from "dayjs";
import { useAuthStore } from "@/stores/auth";
import { useMeeting } from "@/composables/useMeeting";

const authStore = useAuthStore();
const router = useRouter();
const { openMeetingPopup } = useMeeting();
const meetings = ref<Meeting[]>([]);
const loading = ref(true);
const showCreateModal = ref(false);
const editingMeeting = ref<Meeting | null>(null);
const createdMeeting = ref<Meeting | null>(null);
const revealedPasswords = ref<Set<number>>(new Set());

const fetchMeetings = async () => {
    loading.value = true;
    try {
        const response = await meetingService.listMeetings();
        meetings.value = response || [];
    } catch (error) {
        console.error("Failed to fetch meetings:", error);
        meetings.value = [];
        toast.error("Failed to load meetings");
    } finally {
        loading.value = false;
    }
};

const isCreatingInstant = ref(false);

const startInstantMeeting = async () => {
    if (isCreatingInstant.value) return;
    isCreatingInstant.value = true;

    try {
        const meeting = await meetingService.createMeeting({
            title: `Instant Meeting - ${dayjs().format("HH:mm")}`,
            start_time: dayjs().toISOString(),
            settings: { instant: true },
        });

        toast.success("Instant meeting created");
        openMeetingPopup(meeting.public_id);
    } catch (error) {
        console.error("Instant meeting creation failed:", error);
        toast.error("Failed to create meeting");
        isCreatingInstant.value = false;
    } finally {
        // Enforce a strict 1-second debounce after successful API attempts to prevent rapid-fire clicking
        setTimeout(() => {
            isCreatingInstant.value = false;
        }, 1000);
    }
};

const joinMeeting = (meeting: Meeting) => {
    openMeetingPopup(meeting.public_id);
};

const copyLink = (meeting: Meeting) => {
    const url = getMeetingUrl(meeting);
    navigator.clipboard.writeText(url);
    toast.success("Invitation link copied to clipboard");
};

const copyPassword = (meeting: Meeting) => {
    if (!meeting.password) return;
    navigator.clipboard.writeText(meeting.password);
    toast.success("Password copied to clipboard");
};

const getMeetingUrl = (meeting: Meeting) => {
    return `${window.location.origin}/m/${meeting.public_id}`;
};

const onMeetingCreated = (meeting: Meeting) => {
    showCreateModal.value = false;
    createdMeeting.value = meeting;
    fetchMeetings();
};

const onMeetingUpdated = () => {
    editingMeeting.value = null;
    fetchMeetings();
};

const togglePasswordReveal = (meetingId: number) => {
    if (revealedPasswords.value.has(meetingId)) {
        revealedPasswords.value.delete(meetingId);
    } else {
        revealedPasswords.value.add(meetingId);
    }
};

const isOwner = (meeting: Meeting) => {
    if (!authStore.user) return false;
    if (authStore.isSuperAdmin) return true;
    return String(meeting.user_id) === String(authStore.user.id);
};

const cancelMeeting = async (meeting: Meeting) => {
    if (!confirm("Are you sure you want to cancel this meeting?")) return;

    try {
        await meetingService.deleteMeeting(meeting.public_id);
        toast.success("Meeting cancelled");
        fetchMeetings();
    } catch (error) {
        toast.error("Failed to cancel meeting");
    }
};

const formatTime = (time: string | undefined) => {
    if (!time) return "Instant";
    return dayjs(time).format("MMM D, YYYY · HH:mm");
};

onMounted(fetchMeetings);
</script>
