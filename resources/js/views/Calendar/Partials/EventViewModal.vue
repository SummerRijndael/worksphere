<script setup>
import { computed, ref } from "vue";
import { useDate } from "@/composables/useDate";

const {
    formatDate: formatDateComposable,
    formatDateTime: formatDateTimeComposable,
} = useDate();
import {
    MapPin,
    Clock,
    AlignLeft,
    Calendar as CalendarIcon,
    Users,
    SquarePen as Edit,
    X,
    Trash2,
    Mail,
    Video,
    Copy,
    Check,
} from "lucide-vue-next";
import { toast } from "vue-sonner";
import Modal from "@/components/ui/Modal.vue";
import Button from "@/components/ui/Button.vue";

const props = defineProps({
    open: Boolean,
    event: Object,
});

const emit = defineEmits(["update:open", "edit", "delete"]);

const showFullDescription = ref(false);

const isDescriptionLong = computed(() => {
    return (props.event?.description?.length || 0) > 200;
});

const displayDescription = computed(() => {
    if (!props.event?.description) return "";
    if (!isDescriptionLong.value || showFullDescription.value) {
        return props.event.description;
    }
    return props.event.description.substring(0, 200) + "...";
});

const formattedDate = computed(() => {
    if (!props.event) return "";
    return formatDateComposable(props.event.start_time, "EEEE, MMMM d, yyyy");
});

const formattedTime = computed(() => {
    if (!props.event) return "";
    if (props.event.is_all_day) return "All Day";

    return `${formatDateTimeComposable(props.event.start_time, "h:mm a")} - ${formatDateTimeComposable(props.event.end_time, "h:mm a")}`;
});

const attendees = computed(() => {
    if (!props.event) return [];
    const users = props.event.attendees || [];
    const emails = props.event.external_attendees || [];
    return [...users, ...emails];
});

// Helper for status badge
const getStatusColor = (status) => {
    switch (status) {
        case "accepted":
            return "success";
        case "declined":
            return "danger";
        case "tentative":
            return "warning";
        case "pending":
            return "warning"; // Changed pending to warning/yellow for visibility but distinct from success
        default:
            return "secondary";
    }
};

const getStatusLabel = (status) => {
    switch (status) {
        case "pending":
            return "Invited";
        default:
            return status;
    }
};

const meetingId = computed(() => {
    return (
        props.event?.meeting_id ||
        props.event?.meeting?.id ||
        props.event?.extendedProps?.meeting_id ||
        props.event?.extendedProps?.meeting?.id
    );
});

const meetingPublicId = computed(() => {
    return (
        props.event?.meeting?.public_id ||
        props.event?.extendedProps?.meeting?.public_id ||
        meetingId.value
    );
});

const meetingHasPassword = computed(() => {
    return !!(
        props.event?.meeting?.has_password ||
        props.event?.extendedProps?.meeting?.has_password
    );
});

const meetingPasscode = computed(() => {
    return (
        props.event?.meeting?.password ||
        props.event?.extendedProps?.meeting?.password ||
        null
    );
});

const hasMeeting = computed(() => !!(meetingId.value || props.event?.meeting));

const joinMeeting = () => {
    if (!meetingPublicId.value) return;
    window.open(`/m/${meetingPublicId.value}`, "_blank");
};

const copiedId = ref(false);
const copiedPasscode = ref(false);
const copiedLink = ref(false);

const copyToClipboard = async (text, type) => {
    try {
        await navigator.clipboard.writeText(text);
        if (type === "id") {
            copiedId.value = true;
            setTimeout(() => (copiedId.value = false), 2000);
        } else if (type === "passcode") {
            copiedPasscode.value = true;
            setTimeout(() => (copiedPasscode.value = false), 2000);
        } else if (type === "link") {
            copiedLink.value = true;
            setTimeout(() => (copiedLink.value = false), 2000);
        }
        toast.success(
            `Copied ${type === "id" ? "Meeting ID" : type === "passcode" ? "Passcode" : "Join Link"} to clipboard`,
        );
    } catch (err) {
        toast.error("Failed to copy to clipboard");
    }
};

const isAttendeeJoined = (attendee) => {
    if (!props.event?.meeting?.participants) return false;
    const email = typeof attendee === "string" ? attendee : attendee.email;
    return props.event.meeting.participants.some(
        (p) => p.email?.toLowerCase() === email?.toLowerCase(),
    );
};
</script>

<template>
    <Modal
        :open="open"
        title="Event Details"
        size="lg"
        @update:open="$emit('update:open', $event)"
    >
        <div v-if="event" class="space-y-6">
            <!-- Header Info -->
            <div class="flex items-start gap-5">
                <div
                    class="h-14 w-14 rounded-2xl bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center shrink-0 shadow-sm border border-blue-100 dark:border-blue-500/20"
                >
                    <CalendarIcon class="w-7 h-7" />
                </div>
                <div class="flex-1 min-w-0 pt-0.5">
                    <h2
                        class="text-2xl font-bold text-gray-900 dark:text-white leading-tight mb-2"
                    >
                        {{ event.title }}
                    </h2>
                    <div class="space-y-1.5">
                        <div
                            class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300"
                        >
                            <Clock
                                class="w-3.5 h-3.5 text-(--text-tertiary)"
                            />
                            <span class="font-medium">{{ formattedDate }}</span>
                            <span class="text-gray-300 dark:text-gray-600"
                                >•</span
                            >
                            <span>{{ formattedTime }}</span>
                        </div>
                        <div
                            v-if="event.location"
                            class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300"
                        >
                            <MapPin
                                class="w-3.5 h-3.5 text-(--text-tertiary)"
                            />
                            <span>{{ event.location }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Join Meeting Button -->
            <div
                v-if="hasMeeting"
                class="p-5 rounded-2xl border border-blue-100 dark:border-blue-500/20 bg-linear-to-br from-blue-50/50 to-indigo-50/50 dark:from-blue-500/10 dark:to-indigo-500/10 shadow-sm"
            >
                <div
                    class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6"
                >
                    <div class="flex items-center gap-4">
                        <div
                            class="w-12 h-12 rounded-xl bg-blue-600 dark:bg-blue-500 flex items-center justify-center shrink-0 shadow-lg shadow-blue-200 dark:shadow-none"
                        >
                            <Video class="w-6 h-6 text-white" />
                        </div>
                        <div>
                            <p
                                class="text-base font-bold text-gray-900 dark:text-white"
                            >
                                Meeting
                            </p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Connect with your team instantly
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 w-full sm:w-auto">
                        <Button
                            variant="primary"
                            size="md"
                            @click="joinMeeting"
                            class="flex-1 sm:flex-none shadow-md"
                        >
                            <Video class="w-4 h-4 mr-2" />
                            Join Now
                        </Button>
                        <Button
                            variant="secondary"
                            size="md"
                            @click="
                                copyToClipboard(
                                    window.location.origin + '/m/' + meetingPublicId,
                                    'link',
                                )
                            "
                            class="px-3"
                            title="Copy Join Link"
                        >
                            <Check v-if="copiedLink" class="w-4 h-4" />
                            <Copy v-else class="w-4 h-4" />
                        </Button>
                    </div>
                </div>

                <div
                    class="mt-6 pt-5 border-t border-blue-100 dark:border-blue-500/20 grid grid-cols-1 sm:grid-cols-2 gap-4"
                >
                    <div v-if="meetingPublicId" class="space-y-1.5">
                        <p
                            class="text-[10px] uppercase font-bold tracking-widest text-gray-400 dark:text-gray-500 ml-1"
                        >
                            Meeting ID
                        </p>
                        <div
                            class="group flex items-center justify-between bg-white/60 dark:bg-gray-900/40 border border-gray-100 dark:border-gray-800 p-2.5 rounded-xl hover:border-blue-200 dark:hover:border-blue-500/30 transition-all"
                        >
                            <span
                                class="text-xs font-mono text-gray-700 dark:text-gray-200 truncate"
                            >
                                {{ meetingPublicId }}
                            </span>
                            <button
                                @click="copyToClipboard(meetingPublicId, 'id')"
                                class="p-1.5 hover:bg-blue-50 dark:hover:bg-blue-500/20 rounded-lg transition-colors text-blue-600 dark:text-blue-400 shrink-0"
                            >
                                <Check v-if="copiedId" class="w-3.5 h-3.5" />
                                <Copy v-else class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>
                    <div v-if="meetingHasPassword" class="space-y-1.5">
                        <p
                            class="text-[10px] uppercase font-bold tracking-widest text-gray-400 dark:text-gray-500 ml-1"
                        >
                            Meeting Passcode
                        </p>
                        <div
                            class="group flex items-center justify-between bg-white/60 dark:bg-gray-900/40 border border-gray-100 dark:border-gray-800 p-2.5 rounded-xl hover:border-blue-200 dark:hover:border-blue-500/30 transition-all"
                        >
                            <span v-if="meetingPasscode" class="text-xs font-mono text-gray-700 dark:text-gray-200 truncate">
                                {{ meetingPasscode }}
                            </span>
                            <span v-else class="text-xs text-gray-700 dark:text-gray-200">
                                This meeting is passcode protected. Ask the host for the current passcode.
                            </span>
                            <button
                                v-if="meetingPasscode"
                                @click="copyToClipboard(meetingPasscode, 'passcode')"
                                class="p-1.5 hover:bg-blue-50 dark:hover:bg-blue-500/20 rounded-lg transition-colors text-blue-600 dark:text-blue-400 shrink-0"
                            >
                                <Check v-if="copiedPasscode" class="w-3.5 h-3.5" />
                                <Copy v-else class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div
                v-if="event.description"
                class="p-4 rounded-xl bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-800"
            >
                <div class="flex items-center justify-between mb-2">
                    <h3
                        class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-2"
                    >
                        Description
                    </h3>
                    <button
                        v-if="isDescriptionLong"
                        @click="showFullDescription = !showFullDescription"
                        class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1"
                    >
                        {{ showFullDescription ? "Show Less" : "Show More" }}
                        <!-- {{ showFullDescription ? "Show Less" : "Show More" }}
                        <ChevronUp v-if="showFullDescription" class="w-3 h-3" />
                        <ChevronDown v-else class="w-3 h-3" /> -->
                    </button>
                </div>
                <p
                    class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap wrap-break-word leading-relaxed transition-all duration-300"
                >
                    {{ displayDescription }}
                </p>
            </div>

            <!-- Participants -->
            <div v-if="attendees.length > 0">
                <h3
                    class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2"
                >
                    Participants
                    <span
                        class="px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-xs text-gray-600 dark:text-gray-400"
                        >{{ attendees.length }}</span
                    >
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <template v-for="(p, i) in attendees" :key="i">
                        <!-- User -->
                        <div
                            v-if="!p.includes && p.name"
                            class="group flex items-center gap-3 p-2.5 rounded-xl border border-gray-100 dark:border-gray-800 hover:border-gray-200 dark:hover:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-all"
                        >
                            <img
                                v-if="p.avatar_url"
                                :src="p.avatar_url"
                                class="w-9 h-9 rounded-full ring-2 ring-white dark:ring-gray-900"
                            />
                            <div
                                v-else
                                class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-xs font-bold text-gray-600 dark:text-gray-400 ring-2 ring-white dark:ring-gray-900"
                            >
                                {{ p.initials }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate"
                                    >
                                        {{ p.name }}
                                    </div>
                                    <span
                                        v-if="p.status"
                                        :class="[
                                            'px-1.5 py-0.5 rounded text-[10px] font-medium capitalize',
                                            p.status === 'accepted'
                                                ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                : p.status === 'declined'
                                                  ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                                  : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                        ]"
                                    >
                                        {{ getStatusLabel(p.status) }}
                                    </span>
                                </div>
                                <div class="text-xs text-gray-500 truncate">
                                    {{ p.email }}
                                </div>
                            </div>
                            <!-- Attendance status -->
                            <div
                                v-if="isAttendeeJoined(p)"
                                class="shrink-0 mr-1"
                            >
                                <div
                                    class="flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-100 dark:border-blue-500/20 shadow-xs"
                                    title="Attended the meeting"
                                >
                                    <!-- <CheckCircle class="w-3 h-3" /> -->
                                    <span
                                        class="text-[10px] font-bold uppercase tracking-wider"
                                        >Joined</span
                                    >
                                </div>
                            </div>
                        </div>
                        <!-- External Email -->
                        <div
                            v-else
                            class="flex items-center gap-3 p-2.5 rounded-xl border border-gray-100 dark:border-gray-800 hover:border-gray-200 dark:hover:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-all"
                        >
                            <div
                                class="w-9 h-9 rounded-full bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400 flex items-center justify-center ring-2 ring-white dark:ring-gray-900"
                            >
                                <Mail class="w-4 h-4" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div
                                    class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate"
                                >
                                    {{ p }}
                                </div>
                                <div
                                    class="text-xs text-orange-600 dark:text-orange-400 font-medium"
                                >
                                    External Guest
                                </div>
                            </div>
                            <!-- Attendance status -->
                            <div
                                v-if="isAttendeeJoined(p)"
                                class="shrink-0 mr-1"
                            >
                                <div
                                    class="flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-100 dark:border-blue-500/20 shadow-xs"
                                    title="Attended the meeting"
                                >
                                    <!-- <CheckCircle class="w-3 h-3" /> -->
                                    <span
                                        class="text-[10px] font-bold uppercase tracking-wider"
                                        >Joined</span
                                    >
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-between w-full pt-2">
                <Button
                    variant="danger"
                    variant-type="ghost"
                    size="sm"
                    class="text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20"
                    @click="$emit('delete', event)"
                >
                    <Trash2 class="w-4 h-4 mr-2" />
                    Delete Event
                </Button>
                <div class="flex gap-3">
                    <Button variant="ghost" @click="$emit('update:open', false)"
                        >Close</Button
                    >
                    <Button
                        variant="primary"
                        @click="$emit('edit', event)"
                        class="min-w-[100px]"
                    >
                        <Edit class="w-4 h-4 mr-2" />
                        Edit
                    </Button>
                </div>
            </div>
        </template>
    </Modal>
</template>
