<template>
    <div class="p-3 sm:p-6">
        <!-- Header Section -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
            <div>
                <button
                    @click="router.push({ name: 'meetings' })"
                    class="text-sm text-(--text-muted) hover:text-(--color-primary-600) flex items-center gap-1 mb-2 transition-colors"
                >
                    <Icon name="arrow-left" size="14" />
                    Back to Meetings
                </button>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl sm:text-3xl font-bold text-(--text-primary)">
                        {{ meeting?.title || "Meeting Details" }}
                    </h1>
                    
                    <div v-if="meeting" class="shrink-0 flex items-center">
                        <!-- Status Badge -->
                        <div
                            v-if="meeting.status === 'active'"
                            class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 text-emerald-500 text-xs font-bold uppercase tracking-wider h-fit"
                        >
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                            </span>
                            Live
                        </div>
                        <div
                            v-else-if="meeting.status === 'ended'"
                            class="px-2.5 py-1 rounded-full bg-gray-500/10 text-gray-400 text-xs font-bold uppercase tracking-wider h-fit"
                        >
                            Ended
                        </div>
                        <div
                            v-else
                            class="px-2.5 py-1 rounded-full bg-blue-500/10 text-blue-400 text-xs font-bold uppercase tracking-wider h-fit"
                        >
                            Scheduled
                        </div>
                    </div>
                </div>
                
                <div v-if="meeting" class="mt-2 text-sm text-(--text-muted) flex items-center gap-2">
                    <Icon name="hash" size="14" />
                    <span>ID: {{ meeting.public_id }}</span>
                </div>
            </div>
            
            <div v-if="meeting" class="flex gap-2">
                <button
                    @click="copyLink"
                    class="px-4 py-2 bg-(--surface-tertiary) hover:bg-(--surface-primary) border border-(--border-muted) text-(--text-primary) rounded-lg font-medium transition-colors flex items-center gap-2 text-sm"
                >
                    <Icon name="copy" size="16" />
                    Copy Link
                </button>
                <button
                    v-if="meeting.status !== 'ended'"
                    @click="joinMeeting"
                    class="px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg font-medium transition-colors flex items-center gap-2 text-sm"
                >
                    <Icon name="video" size="16" />
                    Join
                </button>
            </div>
        </div>

        <div v-if="loading" class="flex justify-center py-20">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-(--color-primary-600)"></div>
        </div>

        <template v-else-if="meeting">
            <!-- Statistics Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
                <!-- Scheduled Start -->
                <div class="bg-(--surface-primary) border border-(--border-muted) rounded-xl p-5">
                    <div class="flex items-center gap-3 text-(--text-muted) mb-2">
                        <div class="p-2 bg-blue-500/10 text-blue-500 rounded-lg">
                            <Icon name="calendar" size="18" />
                        </div>
                        <h3 class="text-sm font-medium">Scheduled Time</h3>
                    </div>
                    <div class="text-lg font-semibold text-(--text-primary)">
                        {{ meeting.start_time ? formatDateTime(meeting.start_time) : 'Instant Meeting' }}
                    </div>
                </div>
                
                <!-- Actual End Time -->
                <div class="bg-(--surface-primary) border border-(--border-muted) rounded-xl p-5">
                    <div class="flex items-center gap-3 text-(--text-muted) mb-2">
                        <div class="p-2 bg-gray-500/10 text-gray-500 rounded-lg">
                            <Icon name="clock" size="18" />
                        </div>
                        <h3 class="text-sm font-medium">Actual Duration</h3>
                    </div>
                    <div class="text-lg font-semibold text-(--text-primary)">
                        <template v-if="meeting.actual_start_time">
                            {{ meeting.actual_end_time ? calculateDuration(meeting.actual_start_time, meeting.actual_end_time) : 'Ongoing' }}
                        </template>
                        <template v-else>
                            Not started
                        </template>
                    </div>
                    <div v-if="meeting.actual_start_time" class="text-xs text-(--text-muted) mt-1">
                        Started: {{ formatTimeOnly(meeting.actual_start_time) }}
                    </div>
                    <div v-if="meeting.actual_end_time" class="text-xs text-(--text-muted) mt-0.5">
                        Ended: {{ formatTimeOnly(meeting.actual_end_time) }}
                    </div>
                </div>

                <!-- Unique Participants -->
                <div class="bg-(--surface-primary) border border-(--border-muted) rounded-xl p-5">
                    <div class="flex items-center gap-3 text-(--text-muted) mb-2">
                        <div class="p-2 bg-purple-500/10 text-purple-500 rounded-lg">
                            <Icon name="users" size="18" />
                        </div>
                        <h3 class="text-sm font-medium">Unique Participants</h3>
                    </div>
                    <div class="text-2xl font-bold text-(--text-primary)">
                        {{ meeting.unique_participant_count ?? 0 }}
                    </div>
                    <div class="text-xs text-(--text-muted) mt-1">
                        Total people who joined
                    </div>
                </div>

                <!-- Peak Participants -->
                <div class="bg-(--surface-primary) border border-(--border-muted) rounded-xl p-5">
                    <div class="flex items-center gap-3 text-(--text-muted) mb-2">
                        <div class="p-2 bg-rose-500/10 text-rose-500 rounded-lg">
                            <Icon name="activity" size="18" />
                        </div>
                        <h3 class="text-sm font-medium">Max Concurrent</h3>
                    </div>
                    <div class="text-2xl font-bold text-(--text-primary)">
                        {{ meeting.peak_participant_count ?? 0 }}
                    </div>
                    <div class="text-xs text-(--text-muted) mt-1">
                        Highest participants at once
                    </div>
                </div>
            </div>

            <!-- Recordings Section -->
            <div class="mb-6">
                <h2 class="text-xl font-bold text-(--text-primary) mb-4 flex items-center gap-2">
                    <Icon name="video" size="20" class="text-(--color-primary-500)" />
                    Recordings
                </h2>
                
                <div v-if="loadingRecordings" class="bg-(--surface-primary) border border-(--border-muted) rounded-xl p-10 flex justify-center">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-(--color-primary-600)"></div>
                </div>
                
                <div v-else-if="recordings.length === 0" class="bg-(--surface-primary) border border-(--border-muted) rounded-xl p-8 text-center">
                    <div class="w-12 h-12 bg-(--surface-tertiary) rounded-full flex items-center justify-center mx-auto mb-4">
                        <Icon name="film" size="20" class="text-(--text-muted)" />
                    </div>
                    <h3 class="text-base font-medium text-(--text-primary) mb-1">No recordings found</h3>
                    <p class="text-sm text-(--text-muted)">There are no saved recordings for this meeting.</p>
                </div>
                
                <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div
                        v-for="recording in recordings"
                        :key="recording.id"
                        class="bg-(--surface-primary) border border-(--border-muted) rounded-xl p-5 flex flex-col justify-between"
                    >
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <Icon v-if="recording.status === 'completed'" name="check-circle" size="16" class="text-emerald-500" />
                                    <Icon v-else-if="recording.status === 'failed'" name="x-circle" size="16" class="text-red-500" />
                                    <Icon v-else name="loader" size="16" class="text-blue-500 animate-spin" />
                                    <span class="text-sm font-semibold capitalize">{{ recording.status }}</span>
                                </div>
                                <div class="text-xs text-(--text-muted)">
                                    {{ formatSize(recording.size_bytes) }}
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-2 text-sm text-(--text-muted) mb-1">
                                <Icon name="clock" size="14" />
                                Started: {{ formatDateTime(recording.started_at) }}
                            </div>
                            <div v-if="recording.duration_seconds" class="flex items-center gap-2 text-sm text-(--text-muted) mb-4">
                                <Icon name="play-circle" size="14" />
                                Duration: {{ formatDuration(recording.duration_seconds) }}
                            </div>
                        </div>
                        
                        <div class="flex gap-2 mt-4 pt-4 border-t border-(--border-muted)">
                            <a
                                v-if="recording.download_url"
                                :href="recording.download_url"
                                download
                                target="_blank"
                                class="flex-1 px-3 py-2 bg-(--surface-tertiary) hover:bg-(--border-muted) text-(--text-primary) text-xs font-medium rounded-lg transition-colors flex items-center justify-center gap-2"
                            >
                                <Icon name="download" size="14" />
                                Download MP4
                            </a>
                            <button
                                v-else
                                disabled
                                class="flex-1 px-3 py-2 bg-(--surface-tertiary) text-(--text-muted) text-xs font-medium rounded-lg opacity-50 cursor-not-allowed flex items-center justify-center gap-2"
                            >
                                <Icon name="download" size="14" />
                                Processing...
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
        
        <div v-else class="bg-(--surface-primary) border border-(--border-muted) rounded-xl p-10 text-center">
            <Icon name="alert-circle" size="32" class="text-red-500 mx-auto mb-4" />
            <h3 class="text-lg font-bold text-(--text-primary) mb-2">Meeting Not Found</h3>
            <p class="text-(--text-muted) mb-6">The meeting you are looking for does not exist or you do not have permission to view it.</p>
            <button
                @click="router.push({ name: 'meetings' })"
                class="px-5 py-2.5 bg-(--color-primary-600) text-white rounded-lg font-medium hover:bg-(--color-primary-700) transition-colors"
            >
                Return to Meetings
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Icon } from "@/components/ui";
import { meetingService, type Meeting } from "@/services/meeting.service";
import { useMeeting } from "@/composables/useMeeting";
import { toast } from "vue-sonner";
import dayjs from "dayjs";
import duration from "dayjs/plugin/duration";
import relativeTime from "dayjs/plugin/relativeTime";

dayjs.extend(duration);
dayjs.extend(relativeTime);

const route = useRoute();
const router = useRouter();
const { openMeetingPopup } = useMeeting();

const meetingId = route.params.id as string;
const meeting = ref<Meeting | null>(null);
const loading = ref(true);

const recordings = ref<any[]>([]);
const loadingRecordings = ref(false);

const fetchMeetingDetails = async () => {
    loading.value = true;
    try {
        // Find by public_id or database ID
        const response = await meetingService.getMeeting(meetingId);
        meeting.value = response;
    } catch (error) {
        toast.error("Failed to load meeting details");
    } finally {
        loading.value = false;
    }
};

const fetchRecordings = async () => {
    if (!meeting.value) return;
    loadingRecordings.value = true;
    try {
        const response = await meetingService.listRecordings(meeting.value.public_id);
        recordings.value = response || [];
    } catch (error) {
        console.error("Failed to load recordings", error);
        toast.error("Could not load recordings");
    } finally {
        loadingRecordings.value = false;
    }
};

const copyLink = () => {
    if (!meeting.value) return;
    const publicId = String(meeting.value.public_id || meetingId || "").trim();
    if (!publicId || publicId.toLowerCase() === "undefined") {
        toast.error("Meeting link is unavailable");
        return;
    }
    const url = `${window.location.origin}/m/${publicId}`;
    navigator.clipboard.writeText(url);
    toast.success("Meeting link copied to clipboard");
};

const joinMeeting = () => {
    if (!meeting.value) return;
    openMeetingPopup(meeting.value.public_id);
};

const formatDateTime = (date: string) => dayjs(date).format("MMM D, YYYY · h:mm A");
const formatTimeOnly = (date: string) => dayjs(date).format("h:mm:ss A");

const calculateDuration = (start: string, end: string) => {
    const diff = dayjs(end).diff(dayjs(start));
    if (diff < 60000) return "Less than a minute";
    
    // Format as "X hours Y mins" or "Y mins"
    const hours = dayjs.duration(diff).hours();
    const minutes = dayjs.duration(diff).minutes();
    
    if (hours > 0) return `${hours} hrs ${minutes} mins`;
    return `${minutes} mins`;
};

const formatDuration = (seconds: number) => {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    
    if (hours > 0) return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
};

const formatSize = (bytes: number | null) => {
    if (!bytes) return "Unknown size";
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

let pollInterval: any = null;

const startPolling = () => {
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(async () => {
        const needsSync = recordings.value.filter(r => r.status === 'processing' || r.status === 'recording');
        if (needsSync.length === 0) {
            clearInterval(pollInterval);
            pollInterval = null;
            return;
        }

        for (const recording of needsSync) {
            try {
                await meetingService.syncRecording(meeting.value!.public_id, recording.id);
            } catch (e) {
                console.error("Auto-sync failed", e);
            }
        }
        
        // Refresh list to show updated statuses
        fetchRecordings();
    }, 15000); // Poll every 15 seconds
};

onMounted(async () => {
    await fetchMeetingDetails();
    await fetchRecordings();
    
    // If we have processing recordings, start polling
    if (recordings.value.some(r => r.status === 'processing' || r.status === 'recording')) {
        startPolling();
    }
});

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
});
</script>
