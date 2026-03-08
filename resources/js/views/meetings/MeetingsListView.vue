<template>
    <div class="p-3 sm:p-6">
        <div
            class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-8 gap-4"
        >
            <div>
                <h1 class="text-xl sm:text-2xl font-bold text-(--text-primary)">
                    Meetings
                </h1>
                <div class="flex items-center gap-3 mt-1">
                    <p class="text-sm text-(--text-muted)">
                        Schedule and manage your video conferences
                    </p>
                    <div class="flex items-center gap-2">
                        <button
                            @click="manualRefresh"
                            :disabled="isRefreshing"
                            class="p-1 rounded hover:bg-(--surface-tertiary) text-(--text-muted) transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                            :title="
                                isRefreshing ? 'Refreshing...' : 'Refresh list'
                            "
                        >
                            <Icon
                                name="refresh-cw"
                                size="14"
                                :class="{ 'animate-spin': isRefreshing }"
                            />
                        </button>
                        <label
                            class="flex items-center gap-1.5 cursor-pointer select-none"
                        >
                            <input
                                type="checkbox"
                                v-model="autoRefreshEnabled"
                                class="sr-only peer"
                            />
                            <div
                                class="w-7 h-4 bg-(--border-muted) peer-checked:bg-emerald-500 rounded-full relative transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-3 after:h-3 after:bg-white after:rounded-full after:transition-transform peer-checked:after:translate-x-3"
                            ></div>
                            <span
                                class="text-[11px] text-(--text-muted) whitespace-nowrap"
                                >Auto</span
                            >
                        </label>
                    </div>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row gap-3 items-center">
                <!-- Join by ID -->
                <div
                    class="flex items-center bg-(--surface-primary) border border-(--border-muted) rounded-lg px-2 py-1 focus-within:ring-2 focus-within:ring-(--color-primary-500) transition-all"
                >
                    <Icon
                        name="hash"
                        size="14"
                        class="text-(--text-muted) ml-1"
                    />
                    <input
                        v-model="joinId"
                        placeholder="Enter Meeting ID"
                        class="join-meeting-input bg-transparent border-none! shadow-none! outline-none! ring-0! focus:ring-0! focus:ring-transparent! focus:ring-offset-0! focus:outline-none! focus:border-none! px-2 py-1 text-sm w-40 text-(--text-primary)"
                        @keyup.enter="handleJoinById"
                    />
                    <button
                        @click="handleJoinById"
                        :disabled="!joinId.trim()"
                        class="px-3 py-1 bg-(--color-primary-600) hover:bg-(--color-primary-700) text-white rounded text-xs font-semibold disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    >
                        Join
                    </button>
                </div>

                <div class="h-6 w-px bg-(--border-muted) hidden sm:block"></div>

                <button
                    @click="startInstantMeeting"
                    :disabled="isCreatingInstant"
                    class="w-full sm:w-auto px-4 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg font-medium transition-colors flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed text-sm"
                >
                    <Icon
                        :name="isCreatingInstant ? 'loader-2' : 'zap'"
                        :class="{ 'animate-spin': isCreatingInstant }"
                        size="16"
                    />
                    {{ isCreatingInstant ? "Starting..." : "Instant Meeting" }}
                </button>
                <button
                    @click="showCreateModal = true"
                    class="w-full sm:w-auto px-4 py-2.5 bg-(--color-primary-600) hover:bg-(--color-primary-700) text-white rounded-lg font-medium transition-colors flex items-center justify-center gap-2 text-sm"
                >
                    <Icon name="calendar-plus" size="16" />
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
            class="bg-(--surface-primary) border border-(--border-muted) rounded-xl p-10 sm:p-20 text-center"
        >
            <div
                class="w-16 h-16 sm:w-20 sm:h-20 bg-(--surface-tertiary) rounded-full flex items-center justify-center mx-auto mb-6"
            >
                <Icon name="video" size="28" class="sm:text-(--text-muted)" />
            </div>
            <h3 class="text-lg sm:text-xl font-semibold mb-2">
                No meetings scheduled
            </h3>
            <p class="text-sm text-(--text-muted) mb-8 max-w-sm mx-auto">
                Host a quick catch-up or schedule a formal discussion with your
                team or external guests.
            </p>
            <button
                @click="showCreateModal = true"
                class="w-full sm:w-auto px-6 py-3 bg-(--color-primary-600) text-white rounded-lg font-medium hover:bg-(--color-primary-700) transition-all"
            >
                Create Your First Meeting
            </button>
        </div>

        <div
            v-else
            class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6"
        >
            <div
                v-for="meeting in meetings"
                :key="meeting.id"
                class="bg-(--surface-primary) border border-(--border-muted) rounded-xl overflow-hidden hover:shadow-lg transition-shadow group flex flex-col h-full"
            >
                <div class="p-5 flex flex-col h-full">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex items-center gap-3">
                            <div
                                class="w-10 h-10 rounded-lg bg-(--color-primary-500)/10 flex items-center justify-center text-(--color-primary-600)"
                            >
                                <Icon name="video" size="20" />
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3
                                        class="font-bold text-lg leading-tight truncate max-w-48 sm:max-w-56"
                                        :title="meeting.title"
                                    >
                                        {{ meeting.title }}
                                    </h3>
                                    <!-- Status Badge -->
                                    <div
                                        v-if="meeting.status === 'active'"
                                        class="flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-500 text-[10px] font-bold uppercase tracking-wider h-fit"
                                    >
                                        <span class="relative flex h-1.5 w-1.5">
                                            <span
                                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"
                                            ></span>
                                            <span
                                                class="relative inline-flex rounded-full h-1.5 w-1.5 bg-emerald-500"
                                            ></span>
                                        </span>
                                        Live
                                    </div>
                                    <div
                                        v-else-if="meeting.status === 'ended'"
                                        class="px-2 py-0.5 rounded-full bg-gray-500/10 text-gray-400 text-[10px] font-bold uppercase tracking-wider h-fit"
                                    >
                                        Ended
                                    </div>
                                    <div
                                        v-else
                                        class="px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-400 text-[10px] font-bold uppercase tracking-wider h-fit"
                                    >
                                        Scheduled
                                    </div>
                                </div>
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
                                    <span
                                        v-if="revealedPasswords.has(meeting.id)"
                                        >{{ meeting.password }}</span
                                    >
                                    <span v-else>••••••••</span>

                                    <button
                                        @click.stop="
                                            togglePasswordReveal(meeting.id)
                                        "
                                        class="p-0.5 hover:bg-blue-500/20 rounded transition-colors ml-1"
                                        :title="
                                            revealedPasswords.has(meeting.id)
                                                ? 'Hide Password'
                                                : 'Show Password'
                                        "
                                    >
                                        <Icon
                                            :name="
                                                revealedPasswords.has(
                                                    meeting.id,
                                                )
                                                    ? 'eye-off'
                                                    : 'eye'
                                            "
                                            size="10"
                                        />
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
                                    @select="
                                        router.push({
                                            name: 'meeting-details',
                                            params: { id: meeting.public_id },
                                        })
                                    "
                                >
                                    <Icon
                                        name="bar-chart-2"
                                        size="14"
                                        class="mr-2"
                                    />
                                    View Details
                                </DropdownItem>
                                <DropdownItem
                                    v-if="isOwner(meeting)"
                                    @select="editingMeeting = meeting"
                                >
                                    <Icon name="edit" size="14" class="mr-2" />
                                    Edit Meeting
                                </DropdownItem>

                                <DropdownItem
                                    v-if="
                                        isOwner(meeting) &&
                                        meeting.participants?.length > 1
                                    "
                                    @select="resendInvites(meeting)"
                                    :disabled="resendingSet.has(meeting.id)"
                                >
                                    <Icon
                                        :name="
                                            resendingSet.has(meeting.id)
                                                ? 'loader-2'
                                                : 'mail'
                                        "
                                        size="14"
                                        class="mr-2"
                                        :class="{
                                            'animate-spin': resendingSet.has(
                                                meeting.id,
                                            ),
                                        }"
                                    />
                                    {{
                                        resendingSet.has(meeting.id)
                                            ? "Sending..."
                                            : "Resend Invites"
                                    }}
                                </DropdownItem>
                                <DropdownItem
                                    v-if="isOwner(meeting)"
                                    destructive
                                    @select="confirmCancelMeeting(meeting)"
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
                        class="text-sm text-(--text-secondary) line-clamp-2 mb-4 h-10"
                    >
                        {{ meeting.description || "" }}
                    </p>

                    <div class="flex items-center gap-2 mb-6">
                        <div
                            v-if="getActiveParticipants(meeting)?.length"
                            class="flex -space-x-2"
                        >
                            <div
                                v-for="participant in getActiveParticipants(
                                    meeting,
                                ).slice(0, 3)"
                                :key="participant.id"
                                :title="participant.user?.name || 'Guest'"
                                class="w-7 h-7 rounded-full border-2 border-(--surface-primary) overflow-hidden shrink-0"
                            >
                                <Avatar
                                    :src="
                                        participant.user?.avatar_url ||
                                        participant.metadata?.avatar_url
                                    "
                                    :fallback="
                                        (participant.user?.name ||
                                            participant.metadata?.guest_name ||
                                            'G')[0]
                                    "
                                    :color="participant.user?.color"
                                    size="sm"
                                    class="w-full h-full"
                                />
                            </div>
                        </div>
                        <span
                            class="text-xs font-medium"
                            :class="
                                meeting.status === 'active'
                                    ? 'text-emerald-500'
                                    : 'text-(--text-muted)'
                            "
                        >
                            {{ getMeetingDisplayCount(meeting) }}
                            {{
                                getMeetingDisplayCount(meeting) === 1
                                    ? "participant"
                                    : "participants"
                            }}
                            {{ meeting.status === "active" ? "in call" : "" }}
                        </span>
                    </div>

                    <div class="flex gap-2 mt-auto pt-4">
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
        <div
            v-if="createdMeeting"
            class="fixed inset-0 z-60 flex items-center justify-center p-4 bg-black/60 backdrop-blur-md"
        >
            <div
                class="bg-(--surface-primary) border border-(--border-muted) w-full max-w-md rounded-2xl shadow-2xl p-8 flex flex-col items-center text-center"
            >
                <div
                    class="w-16 h-16 bg-emerald-500/10 rounded-full flex items-center justify-center text-emerald-500 mb-6"
                >
                    <Icon name="check-circle" size="32" />
                </div>
                <h2 class="text-2xl font-bold mb-2">Meeting Created!</h2>
                <p class="text-(--text-muted) mb-8">
                    Your meeting is ready. Share the details below with your
                    participants.
                </p>

                <div class="w-full space-y-4 mb-8">
                    <div class="text-left">
                        <label
                            class="text-[10px] uppercase tracking-wider font-bold text-(--text-tertiary) mb-1 block"
                            >Invitation Link</label
                        >
                        <div class="flex gap-2">
                            <input
                                readonly
                                :value="getMeetingUrl(createdMeeting)"
                                class="flex-1 bg-(--surface-tertiary) border border-(--border-muted) rounded-lg px-3 py-2 text-sm outline-none"
                            />
                            <button
                                @click="copyLink(createdMeeting)"
                                class="p-2 bg-(--surface-tertiary) border border-(--border-muted) rounded-lg hover:bg-(--border-muted) transition-colors"
                            >
                                <Icon name="copy" size="18" />
                            </button>
                        </div>
                    </div>

                    <div v-if="createdMeeting.password" class="text-left">
                        <label
                            class="text-[10px] uppercase tracking-wider font-bold text-(--text-tertiary) mb-1 block"
                            >Meeting Password</label
                        >
                        <div class="flex gap-2">
                            <input
                                readonly
                                :value="createdMeeting.password"
                                class="flex-1 bg-(--surface-tertiary) border border-(--border-muted) rounded-lg px-3 py-2 text-sm font-mono outline-none"
                            />
                            <button
                                @click="copyPassword(createdMeeting)"
                                class="p-2 bg-(--surface-tertiary) border border-(--border-muted) rounded-lg hover:bg-(--border-muted) transition-colors"
                            >
                                <Icon name="copy" size="18" />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col w-full gap-3">
                    <button
                        @click="joinMeeting(createdMeeting)"
                        class="w-full py-3 bg-(--color-primary-600) hover:bg-(--color-primary-700) text-white rounded-xl font-bold transition-all"
                    >
                        Join Meeting Now
                    </button>
                    <button
                        @click="createdMeeting = null"
                        class="w-full py-3 bg-(--surface-tertiary) hover:bg-(--border-muted) rounded-xl font-bold transition-all"
                    >
                        Dismiss
                    </button>
                </div>
            </div>
        </div>

        <!-- Cancel Confirmation Modal -->
        <ConfirmationModal
            :open="showCancelConfirm"
            @update:open="handleCancelConfirmUpdate"
            title="Cancel Meeting"
            message="Are you sure you want to cancel this meeting?"
            description="This action cannot be undone. All participants will lose access to the meeting room."
            confirm-label="Cancel Meeting"
            confirm-variant="danger"
            @confirm="executeCancelMeeting"
        />
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from "vue";
import { useRouter } from "vue-router";
import { meetingService, type Meeting } from "@/services/meeting.service";
import MeetingCreateModal from "./components/MeetingCreateModal.vue";
import MeetingEditModal from "./components/MeetingEditModal.vue";
import { Icon, Dropdown, DropdownItem, Avatar } from "@/components/ui";
import ConfirmationModal from "@/components/ui/ConfirmationModal.vue";
import { toast } from "vue-sonner";
import dayjs from "dayjs";
import { useAuthStore } from "@/stores/auth";
import { formatTimeAgo } from "@/utils/date";
import { useMeeting } from "@/composables/useMeeting";
import echo, { isEchoAvailable } from "@/echo";

const authStore = useAuthStore();
const router = useRouter();
const { openMeetingPopup } = useMeeting();
const meetings = ref<Meeting[]>([]);
const loading = ref(true);
const showCreateModal = ref(false);
const editingMeeting = ref<Meeting | null>(null);
const createdMeeting = ref<Meeting | null>(null);
const revealedPasswords = ref<Set<number>>(new Set());
const resendingSet = ref<Set<number>>(new Set());
const joinId = ref("");

// Auto-refresh state
const autoRefreshEnabled = ref(false);
const isRefreshing = ref(false);
let pollInterval: ReturnType<typeof setInterval> | null = null;
let lastFetchTime = 0;
const POLL_INTERVAL_MS = 30000; // 30 seconds
const RATE_LIMIT_MS = 5000; // 5s cooldown on manual refresh

const handleJoinById = () => {
    if (!joinId.value.trim()) return;
    openMeetingPopup(joinId.value.trim());
    joinId.value = "";
};

const fetchMeetings = async () => {
    loading.value = true;
    try {
        const response = await meetingService.listMeetings();
        meetings.value = response || [];
        lastFetchTime = Date.now();
    } catch (error) {
        console.error("Failed to fetch meetings:", error);
        meetings.value = [];
        toast.error("Failed to load meetings");
    } finally {
        loading.value = false;
    }
};

// Silent fetch — no loading spinner (polling/focus)
const silentFetchMeetings = async () => {
    // Rate limit: skip if last fetch was too recent
    if (Date.now() - lastFetchTime < RATE_LIMIT_MS) return;
    try {
        const response = await meetingService.listMeetings();
        meetings.value = response || [];
        lastFetchTime = Date.now();
    } catch (error) {
        // Silent fail for background refreshes
    }
};

// Manual refresh with rate limit + visual feedback
const manualRefresh = async () => {
    if (isRefreshing.value) return;
    if (Date.now() - lastFetchTime < RATE_LIMIT_MS) return;

    isRefreshing.value = true;
    try {
        const response = await meetingService.listMeetings();
        meetings.value = response || [];
        lastFetchTime = Date.now();
    } catch (error) {
        toast.error("Failed to refresh meetings");
    } finally {
        // Keep spinner for at least 500ms for visual feedback
        setTimeout(() => {
            isRefreshing.value = false;
        }, 500);
    }
};

const handleWindowFocus = () => {
    if (
        autoRefreshEnabled.value &&
        Date.now() - lastFetchTime > RATE_LIMIT_MS
    ) {
        silentFetchMeetings();
    }
};

// Remove manual polling logic block entirely
// Silent fetch functions are kept for the 'focus' handler
watch(autoRefreshEnabled, (enabled) => {
    if (enabled) {
        window.addEventListener("focus", handleWindowFocus);
    } else {
        window.removeEventListener("focus", handleWindowFocus);
    }
});

const isCreatingInstant = ref(false);

const startInstantMeeting = async () => {
    if (isCreatingInstant.value) return;
    isCreatingInstant.value = true;

    try {
        const meeting = await meetingService.createMeeting({
            title: `Instant Meeting - ${dayjs().format("HH:mm")}`,
            start_time: dayjs().toISOString(),
            settings: { instant: true, lobby_enabled: false },
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

const isOwner = (meeting: any) => {
    if (!authStore.user) return false;
    if (authStore.isSuperAdmin) return true;

    return meeting.host_public_id === authStore.user.public_id;
};

const showCancelConfirm = ref(false);
const meetingToCancel = ref<Meeting | null>(null);

const confirmCancelMeeting = (meeting: Meeting) => {
    meetingToCancel.value = meeting;
    showCancelConfirm.value = true;
};

const handleCancelConfirmUpdate = (val: boolean) => {
    showCancelConfirm.value = val;
    if (!val) {
        meetingToCancel.value = null;
    }
};

const executeCancelMeeting = async () => {
    if (!meetingToCancel.value) return;

    try {
        await meetingService.deleteMeeting(meetingToCancel.value.public_id);
        toast.success("Meeting cancelled");
        fetchMeetings();
    } catch (error) {
        toast.error("Failed to cancel meeting");
    } finally {
        showCancelConfirm.value = false;
        meetingToCancel.value = null;
    }
};

const resendInvites = async (meeting: Meeting) => {
    if (resendingSet.value.has(meeting.id)) return;
    resendingSet.value.add(meeting.id);

    try {
        const result = await meetingService.resendInvites(meeting.public_id);
        toast.success(result?.message || "Invitations sent successfully");
    } catch (error: any) {
        toast.error(
            error?.response?.data?.message ||
                "Failed to resend invites. Ensure there are participants to invite.",
        );
    } finally {
        resendingSet.value.delete(meeting.id);
    }
};

const formatTime = (time: string | undefined) => {
    if (!time) return "Instant";
    return dayjs(time).format("MMM D, YYYY · HH:mm");
};

const getActiveParticipants = (meeting: Meeting) => {
    if (meeting.status !== "active" || !meeting.active_participant_ids) {
        return meeting.participants || [];
    }
    return (meeting.participants || []).filter((p) =>
        meeting.active_participant_ids?.includes(p.public_id),
    );
};

const getMeetingDisplayCount = (meeting: Meeting) => {
    if (meeting.status === "active") {
        return meeting.active_participant_count ?? 0;
    }
    return meeting.participants?.length ?? 0;
};

// Start listening once echo is available
const startEchoListener = () => {
    if (authStore.user && isEchoAvailable()) {
        echo.private(`user.${authStore.user.id}`).listen(
            ".App\\Events\\Meetings\\MeetingStatusUpdated",
            (e: any) => {
                const meeting = meetings.value.find(
                    (m) => m.public_id === e.id,
                );
                if (meeting) {
                    meeting.status = e.status;
                } else {
                    silentFetchMeetings();
                }
            },
        );
    }
};

onMounted(() => {
    fetchMeetings();
    lastFetchTime = Date.now();

    // Listen for realtime meeting status updates conditionally
    if (isEchoAvailable()) {
        startEchoListener();
    } else {
        window.addEventListener("echo:connected", startEchoListener, {
            once: true,
        });
    }
});

onUnmounted(() => {
    window.removeEventListener("focus", handleWindowFocus);
    window.removeEventListener("echo:connected", startEchoListener);

    if (authStore.user && isEchoAvailable()) {
        echo.leave(`user.${authStore.user.id}`);
    }
});
</script>

<style scoped>
.join-meeting-input:focus {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
    ring: none !important;
}
</style>
