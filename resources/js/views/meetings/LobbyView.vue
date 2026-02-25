<template>
    <div
        class="lobby-container bg-slate-900 min-h-screen flex items-center justify-center p-4"
    >
        <div
            class="max-w-6xl w-full flex flex-col md:flex-row gap-8 items-center justify-center"
        >
            <!-- Left Side: Video Preview -->
            <div
                v-if="!(meeting?.status === 'ended' || isEndedQuery)"
                class="video-section flex-1 w-full max-w-3xl relative rounded-2xl overflow-hidden bg-black shadow-2xl aspect-video"
            >
                <div
                    v-if="loading"
                    class="absolute inset-0 flex items-center justify-center text-slate-400"
                >
                    <Icon name="loader" size="32" class="animate-spin" />
                </div>

                <video
                    ref="localVideo"
                    autoplay
                    muted
                    playsinline
                    class="w-full h-full object-cover transition-opacity duration-300"
                    :class="{
                        'opacity-0': !isCameraOn,
                        'opacity-100': isCameraOn,
                    }"
                ></video>

                <!-- Camera Off Avatar Placeholder -->
                <div
                    v-if="!isCameraOn && !loading"
                    class="absolute inset-0 flex items-center justify-center"
                >
                    <div
                        class="w-32 h-32 rounded-full bg-slate-700 flex items-center justify-center text-4xl text-slate-300 shadow-lg"
                    >
                        {{
                            (authStore.user?.name ||
                                guestName ||
                                "?")[0].toUpperCase()
                        }}
                    </div>
                </div>

                <!-- Floating Controls -->
                <div
                    class="absolute bottom-6 left-1/2 -translate-x-1/2 flex gap-4"
                >
                    <button
                        @click="toggleMic"
                        class="control-btn rounded-full flex items-center justify-center shadow-lg transition-transform hover:scale-105 active:scale-95"
                        :class="{
                            'bg-red-500 hover:bg-red-600': !isMicOn,
                            'bg-slate-700 hover:bg-slate-600': isMicOn,
                        }"
                        :title="
                            isMicOn
                                ? 'Turn off microphone'
                                : 'Turn on microphone'
                        "
                    >
                        <Icon
                            :name="isMicOn ? 'mic' : 'mic-off'"
                            size="24"
                            :class="{ 'text-white': true }"
                        />
                    </button>
                    <button
                        @click="toggleCamera"
                        class="control-btn rounded-full flex items-center justify-center shadow-lg transition-transform hover:scale-105 active:scale-95"
                        :class="{
                            'bg-red-500 hover:bg-red-600': !isCameraOn,
                            'bg-slate-700 hover:bg-slate-600': isCameraOn,
                        }"
                        :title="
                            isCameraOn ? 'Turn off camera' : 'Turn on camera'
                        "
                    >
                        <Icon
                            :name="isCameraOn ? 'video' : 'video-off'"
                            size="24"
                            :class="{ 'text-white': true }"
                        />
                    </button>
                    <!-- Settings Button -->
                    <button
                        @click="showSettings = true"
                        class="control-btn bg-slate-700 hover:bg-slate-600 rounded-full flex items-center justify-center shadow-lg transition-transform hover:scale-105 active:scale-95"
                        title="Device settings"
                    >
                        <Icon name="settings" size="24" class="text-white" />
                    </button>
                </div>
            </div>

            <!-- Right Side: Join Panel -->
            <div
                class="join-section w-full md:w-96 flex flex-col items-center text-center"
            >
                <!-- Meeting Ended State -->
                <template v-if="meeting?.status === 'ended' || isEndedQuery">
                    <div class="w-full mb-8 pt-6 pb-8 px-6 bg-slate-800/80 border border-slate-700/80 rounded-2xl flex flex-col items-center shadow-lg">
                        <div class="w-16 h-16 bg-slate-700 text-slate-300 rounded-full flex items-center justify-center mb-6 shadow-inner cursor-not-allowed">
                            <Icon name="phone-off" size="32" class="opacity-80" />
                        </div>
                        <h2 class="text-xl font-bold text-white mb-2">Meeting has Ended</h2>
                        <p class="text-slate-400 text-sm">The host has ended this meeting for everyone.</p>
                    </div>
                    <router-link to="/" class="w-full bg-slate-800 hover:bg-slate-700 text-white px-6 py-4 rounded-xl font-medium transition-colors flex items-center justify-center border border-slate-700">
                        <Icon name="home" size="20" class="mr-2" />
                        Return to Home
                    </router-link>
                </template>

                <!-- Regular Join UI -->
                <template v-else>
                    <h1
                        class="text-3xl font-semibold mb-2 tracking-tight"
                        style="color: #ffffff !important"
                    >
                        Ready to join?
                    </h1>
                    <p class="text-slate-400 mb-8">
                        {{ meeting?.title || "Loading meeting details..." }}
                    </p>

                    <div v-if="authStore.isAuthenticated" class="w-full mb-6 py-3 px-4 bg-slate-800/50 border border-slate-700/50 rounded-lg flex items-center justify-between text-left">
                        <div class="flex items-center gap-3">
                            <img v-if="authStore.user?.avatar_url" :src="authStore.user.avatar_url" class="w-10 h-10 rounded-full border border-slate-600" />
                            <div v-else class="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold">
                                {{ authStore.user?.name?.[0]?.toUpperCase() }}
                            </div>
                            <div>
                                <p class="text-sm font-medium text-white">{{ authStore.user?.name }}</p>
                                <p class="text-xs text-slate-500">Joining as yourself</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="!authStore.isAuthenticated" class="w-full space-y-3 mb-4">
                        <input
                            v-model="guestName"
                            placeholder="Enter your name"
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all"
                            @keyup.enter="joinMeeting"
                        />
                        <input
                            v-model="guestEmail"
                            type="email"
                            placeholder="Enter your email address"
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all"
                            @keyup.enter="joinMeeting"
                        />
                        <p v-if="guestEmailError" class="text-xs text-red-400 text-left">{{ guestEmailError }}</p>
                    </div>

                    <!-- Password input: shown only for non-hosts when meeting is password protected -->
                    <div
                        v-if="meeting?.has_password && !isHost"
                        class="w-full mb-6"
                    >
                        <label class="block text-xs text-slate-500 mb-1.5 text-left"
                            >Meeting password</label
                        >
                        <input
                            v-model="password"
                            type="password"
                            placeholder="Enter meeting password"
                            class="w-full px-4 py-3 bg-slate-800 border border-slate-700 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all"
                            @keyup.enter="joinMeeting"
                        />
                    </div>

                    <div class="flex flex-col sm:flex-row gap-4 w-full">
                        <button
                            @click="joinMeeting"
                            :disabled="
                                joining ||
                                (!authStore.isAuthenticated && (!guestName.trim() || !guestEmail.trim() || !!guestEmailError)) ||
                                (meeting?.has_password &&
                                    !isHost &&
                                    !password.trim())
                            "
                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-full font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center min-h-[48px]"
                        >
                            <Icon
                                v-if="joining"
                                name="loader"
                                size="20"
                                class="animate-spin mr-2"
                            />
                            {{ joining ? "Joining..." : "Join now" }}
                        </button>
                        <!-- Present Button -->
                        <button
                            @click="joinAndPresent"
                            :disabled="
                                joining ||
                                (!authStore.isAuthenticated && (!guestName.trim() || !guestEmail.trim() || !!guestEmailError)) ||
                                (meeting?.has_password &&
                                    !isHost &&
                                    !password.trim())
                            "
                            class="flex-1 bg-transparent hover:bg-slate-800 text-blue-400 px-6 py-3 rounded-full font-medium transition-colors border border-slate-700 min-h-[48px] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center"
                            title="Join meeting and start presenting"
                        >
                            <Icon name="monitor-up" size="18" class="inline mr-2" />
                            Present
                        </button>
                    </div>

                    <div class="mt-8 text-sm text-slate-500">
                        <p>Other joining options (audio only)</p>
                    </div>
                </template>
            </div>
        </div>

        <!-- Settings Modal (Bespoke for Meetings) -->
        <DeviceSettingsModal
            v-model:open="showSettings"
            @close="showSettings = false"
        />
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch, onUnmounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { meetingService, type Meeting } from "@/services/meeting.service";
import { useAuthStore } from "@/stores/auth";
import { useMeetingStore } from "@/stores/meeting";
import { useVideoCallStore } from "@/stores/videocall";
import DeviceSettingsModal from "./components/DeviceSettingsModal.vue";
import { Icon } from "@/components/ui";
import { toast } from "vue-sonner";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const meetingStore = useMeetingStore();
const videoCallStore = useVideoCallStore();

const meetingId = route.params.id as string;
const meeting = ref<Meeting | null>(null);
const loading = ref(true);
const joining = ref(false);
const isEndedQuery = computed(() => route.query.ended === '1');

const localVideo = ref<HTMLVideoElement | null>(null);
const localStream = ref<MediaStream | null>(null);
const isCameraOn = ref(false); // Default OFF
const isMicOn = ref(false); // Default OFF
const guestName = ref("");
const guestEmail = ref("");
const guestEmailError = ref("");
const password = ref("");
const showSettings = ref(false);

// Host detection: compare user public_id (string) to meeting host public_id.
// Avoids number/string type-mismatch issues with user_id comparisons.
const isHost = computed(() => {
    if (!meeting.value || !authStore.user) return false;
    // Check via host object on meeting (most reliable)
    if (meeting.value.host?.public_id && authStore.user.public_id) {
        return meeting.value.host.public_id === authStore.user.public_id;
    }
    // Fallback: loose comparison on user_id
    return meeting.value.user_id == authStore.user?.id;
});

onMounted(async () => {
    try {
        meeting.value = await meetingService.getMeeting(meetingId);
        // Do NOT acquire camera/mic here — we only do it on explicit toggle.
        // This prevents the camera indicator light from turning on immediately.
        
        const savedPwd = localStorage.getItem(`worksphere_meeting_pwd_${meetingId}`);
        if (savedPwd) {
            password.value = savedPwd;
        }
    } catch (e) {
        console.error("Failed to load meeting:", e);
        toast.error("Failed to load meeting details");
    } finally {
        loading.value = false;
    }
});

onUnmounted(() => {
    // If we leave lobby WITHOUT joining, stop tracks
    if (localStream.value && !joining.value) {
        localStream.value.getTracks().forEach((t) => t.stop());
    }
});

const requestPermissionsAndStream = async () => {
    console.info("[LOBBY-TRACE] Requesting initial media permissions...");
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: true,
        });

        // We only want permissions so the device list populates, we don't want to leave the light on!
        stream.getTracks().forEach((t) => t.stop());

        // Tell video call store we have permissions so the settings modal can list devices
        videoCallStore.hasPermissions = true;

        // Refresh device list in store manually just in case
        navigator.mediaDevices.enumerateDevices().then((devices) => {
            videoCallStore.devices = devices;
        });
    } catch (e) {
        console.warn("[LOBBY-TRACE] Could not get media permissions", e);
        toast.warning(
            "Please allow camera and microphone permissions to join the meeting.",
        );
    }
};

const toggleCamera = async () => {
    if (!videoCallStore.hasPermissions) {
        await requestPermissionsAndStream();
    }

    if (!localStream.value) {
        localStream.value = new MediaStream();
    }

    if (!isCameraOn.value) {
        // Toggling ON
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: videoCallStore.selectedVideoDeviceId
                    ? { deviceId: videoCallStore.selectedVideoDeviceId }
                    : true,
            });
            const videoTrack = stream.getVideoTracks()[0];
            localStream.value.addTrack(videoTrack);
            isCameraOn.value = true;
            console.info(
                `[LOBBY-TRACE] Started new camera track ${videoTrack.id}`,
            );
        } catch (e) {
            console.error("Failed to start camera", e);
            toast.error("Could not access camera hardware.");
        }
    } else {
        // Toggling OFF -> Must physically STOP to turn the light off!
        localStream.value.getVideoTracks().forEach((t) => {
            t.stop();
            localStream.value!.removeTrack(t);
            console.info(
                `[LOBBY-TRACE] Stopped and removed camera track ${t.id}`,
            );
        });
        isCameraOn.value = false;
    }
};

// Reliably bind the localStream to the video element
watch(
    () => [localVideo.value, localStream.value],
    ([videoEl, stream]) => {
        if (
            videoEl &&
            stream &&
            (videoEl as HTMLVideoElement).srcObject !== stream
        ) {
            console.info("[LOBBY-TRACE] Binding srcObject to video element");
            (videoEl as HTMLVideoElement).srcObject = stream as MediaStream;
        }
    },
    { immediate: true },
);

const toggleMic = async () => {
    if (!videoCallStore.hasPermissions) {
        await requestPermissionsAndStream();
    }

    if (!localStream.value) {
        localStream.value = new MediaStream();
    }

    if (!isMicOn.value) {
        // Toggling ON
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: videoCallStore.selectedAudioDeviceId
                    ? { deviceId: videoCallStore.selectedAudioDeviceId }
                    : true,
            });
            const audioTrack = stream.getAudioTracks()[0];
            localStream.value.addTrack(audioTrack);
            isMicOn.value = true;
            console.info(
                `[LOBBY-TRACE] Started new microphone track ${audioTrack.id}`,
            );
        } catch (e) {
            console.error("Failed to start mic", e);
            toast.error("Could not access microphone hardware.");
        }
    } else {
        // Toggling OFF
        localStream.value.getAudioTracks().forEach((t) => {
            t.stop();
            localStream.value!.removeTrack(t);
            console.info(`[LOBBY-TRACE] Stopped and removed mic track ${t.id}`);
        });
        isMicOn.value = false;
    }
};

// Listen for device changes from the Settings modal and apply them
watch(
    [
        () => videoCallStore.selectedAudioInput,
        () => videoCallStore.selectedVideoInput,
    ],
    async ([newAudio, newVideo], [oldAudio, oldVideo]) => {
        // If devices changed conceptually from the modal
        if (newAudio !== oldAudio || newVideo !== oldVideo) {
            console.info(
                "[LOBBY-TRACE] Device settings changed. Audio:",
                oldAudio ? "changed" : "initial",
                "Video:",
                oldVideo ? "changed" : "initial",
            );
            console.info(
                `[LOBBY-TRACE] New Audio: ${newAudio}, New Video: ${newVideo}`,
            );
            if (!localStream.value) return;

            // If mic is ON, restart it with new device
            if (isMicOn.value && newAudio !== oldAudio) {
                localStream.value.getAudioTracks().forEach((t) => {
                    t.stop();
                    localStream.value!.removeTrack(t);
                });
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        audio: newAudio ? { deviceId: newAudio } : true,
                    });
                    localStream.value.addTrack(stream.getAudioTracks()[0]);
                } catch (e) {
                    console.error(e);
                }
            }

            // If camera is ON, restart it with new device
            if (isCameraOn.value && newVideo !== oldVideo) {
                localStream.value.getVideoTracks().forEach((t) => {
                    t.stop();
                    localStream.value!.removeTrack(t);
                });
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        video: newVideo ? { deviceId: newVideo } : true,
                    });
                    localStream.value.addTrack(stream.getVideoTracks()[0]);
                } catch (e) {
                    console.error(e);
                }
            }
        }
    },
);

// Disable lobby camera feed when settings modal takes over the camera,
// to avoid device conflict with the modal's own preview.
watch(showSettings, (isOpen) => {
    if (isOpen && isCameraOn.value && localStream.value) {
        // Stop the lobby track so the modal camera works smoothly
        localStream.value.getVideoTracks().forEach((t) => {
            t.stop();
            localStream.value!.removeTrack(t);
        });
        isCameraOn.value = false;
    }
});

const joinMeeting = async () => {
    try {
        joining.value = true;
        guestEmailError.value = "";

        // Validate email for guests
        if (!authStore.isAuthenticated) {
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(guestEmail.value.trim())) {
                guestEmailError.value = "Please enter a valid email address";
                joining.value = false;
                return;
            }
        }

        // Only store the stream — do NOT call addLocalStream here.
        // addLocalStream triggers initSFU which needs localParticipant (only set after initializeMeeting in the Room).
        if (localStream.value) {
            meetingStore.setStream(localStream.value);
        }

        const res = await meetingService.joinMeeting(
            meetingId,
            guestName.value,
            password.value || undefined,
            guestEmail.value.trim() || undefined,
        );

        router.push({
            name: "meeting-room",
            params: { id: meetingId },
            query: { participant: res.participant.public_id },
        });
    } catch (e: any) {
        console.error("Failed to join meeting", e);
        if (e?.response?.status === 403) {
            const msg = e?.response?.data?.message || 'Incorrect meeting password.';
            toast.error(msg);
        } else if (e?.response?.status === 401) {
            toast.error("You are not authorized to join this meeting.");
        } else {
            toast.error("An error occurred while joining the meeting.");
        }
        joining.value = false;
    }
};

const joinAndPresent = async () => {
    try {
        joining.value = true;
        guestEmailError.value = "";

        // Validate email for guests
        if (!authStore.isAuthenticated) {
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(guestEmail.value.trim())) {
                guestEmailError.value = "Please enter a valid email address";
                joining.value = false;
                return;
            }
        }

        if (localStream.value) {
            meetingStore.setStream(localStream.value);
        }

        const res = await meetingService.joinMeeting(
            meetingId,
            guestName.value,
            password.value || undefined,
            guestEmail.value.trim() || undefined,
        );

        router.push({
            name: "meeting-room",
            params: { id: meetingId },
            query: { participant: res.participant.public_id, present: '1' },
        });
    } catch (e: any) {
        console.error("Failed to join meeting", e);
        if (e?.response?.status === 403) {
            const msg = e?.response?.data?.message || 'Access denied.';
            toast.error(msg);
        } else if (e?.response?.status === 401) {
            toast.error("You are not authorized to join this meeting.");
        } else {
            toast.error("An error occurred while joining the meeting.");
        }
        joining.value = false;
    }
};
</script>

<style scoped>
.control-btn {
    width: 56px;
    height: 56px;
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}
</style>
