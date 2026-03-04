<template>
    <div
        class="lobby-container bg-surface-primary min-h-dvh flex items-center justify-center p-4 sm:p-6"
    >
        <div
            class="max-w-6xl w-full flex flex-col items-center justify-center gap-8 md:flex-row md:gap-12 lg:gap-16"
        >
            <!-- Left Side: Video Preview -->
            <div
                v-if="showVideoSection"
                class="video-section w-full max-w-[min(100%,720px)] relative rounded-2xl overflow-hidden bg-black shadow-2xl aspect-video border border-white/10 shrink-0"
            >
                <div
                    v-if="loading"
                    class="absolute inset-0 flex items-center justify-center text-secondary"
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

                <div
                    v-if="!isCameraOn && !loading"
                    class="absolute inset-0 flex items-center justify-center p-4"
                >
                    <div
                        class="w-24 h-24 sm:w-32 sm:h-32 rounded-full bg-white/10 flex items-center justify-center text-3xl sm:text-4xl text-white shadow-lg backdrop-blur-sm"
                    >
                        <span>{{
                            (authStore.user?.name ||
                                guestName ||
                                "?")[0].toUpperCase()
                        }}</span>
                    </div>
                </div>

                <div
                    class="absolute bottom-3 sm:bottom-6 left-1/2 -translate-x-1/2 flex gap-2 sm:gap-4"
                >
                    <button
                        @click="toggleMic"
                        class="control-btn rounded-full flex items-center justify-center shadow-lg transition-transform hover:scale-105 active:scale-95"
                        :class="
                            !isMicOn
                                ? 'bg-red-500 hover:bg-red-600'
                                : 'bg-surface-tertiary hover:bg-border-strong'
                        "
                        :title="
                            isMicOn
                                ? 'Turn off microphone'
                                : 'Turn on microphone'
                        "
                    >
                        <Icon
                            :name="isMicOn ? 'mic' : 'mic-off'"
                            size="18"
                            class="sm:size-6 text-white"
                        />
                    </button>
                    <button
                        @click="toggleCamera"
                        class="control-btn rounded-full flex items-center justify-center shadow-lg transition-transform hover:scale-105 active:scale-95"
                        :class="
                            !isCameraOn
                                ? 'bg-red-500 hover:bg-red-600'
                                : 'bg-surface-tertiary hover:bg-border-strong'
                        "
                        :title="
                            isCameraOn ? 'Turn off camera' : 'Turn on camera'
                        "
                    >
                        <Icon
                            :name="isCameraOn ? 'video' : 'video-off'"
                            size="18"
                            class="sm:size-6 text-white"
                        />
                    </button>
                    <button
                        @click="showSettings = true"
                        class="control-btn bg-surface-tertiary hover:bg-border-strong rounded-full flex items-center justify-center shadow-lg transition-transform hover:scale-105 active:scale-95"
                        title="Device settings"
                    >
                        <Icon
                            name="settings"
                            size="18"
                            class="sm:size-6 text-white"
                        />
                    </button>
                </div>
            </div>

            <!-- Right Side: Join Panel -->
            <div
                class="join-section w-full md:w-96 flex flex-col items-center text-center"
            >
                <template v-if="!showVideoSection">
                    <div
                        class="w-full mb-8 pt-6 pb-8 px-6 bg-surface-elevated border-y sm:border border-white/10 rounded-none sm:rounded-2xl flex flex-col items-center shadow-lg"
                    >
                        <div
                            class="w-16 h-16 bg-surface-tertiary text-secondary rounded-full flex items-center justify-center mb-6 shadow-inner cursor-not-allowed"
                        >
                            <Icon
                                name="phone-off"
                                size="32"
                                class="opacity-80"
                            />
                        </div>
                        <h2 class="text-xl font-bold text-white mb-2">
                            Meeting has Ended
                        </h2>
                        <p class="text-secondary text-sm">
                            The host has ended this meeting for everyone.
                        </p>
                    </div>
                    <router-link
                        to="/"
                        class="w-full bg-surface-secondary hover:bg-surface-tertiary text-primary px-6 py-4 rounded-xl font-medium transition-colors flex items-center justify-center border border-white/10"
                    >
                        <Icon name="home" size="20" class="mr-2" />
                        Return to Home
                    </router-link>
                </template>

                <!-- Regular Join UI -->
                <template v-else>
                    <h1
                        class="text-xl sm:text-3xl font-semibold mb-1 sm:mb-2 tracking-tight text-primary"
                    >
                        Ready to join?
                    </h1>
                    <p class="text-xs sm:text-base text-secondary mb-4 sm:mb-8">
                        {{ meeting?.title || "Loading meeting details..." }}
                    </p>

                    <div
                        v-if="authStore.isAuthenticated"
                        class="w-full mb-4 sm:mb-6 py-2.5 sm:py-3 px-4 bg-surface-elevated border-y sm:border border-white/10 rounded-none sm:rounded-lg flex items-center justify-between text-left"
                    >
                        <div class="flex items-center gap-3">
                            <img
                                v-if="authStore.user?.avatar_url"
                                :src="authStore.user.avatar_url"
                                class="w-8 h-8 sm:w-10 sm:h-10 rounded-full border border-white/10"
                            />
                            <div
                                v-else
                                class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-blue-600 flex items-center justify-center text-white font-bold text-xs sm:text-base"
                            >
                                {{ authStore.user?.name?.[0]?.toUpperCase() }}
                            </div>
                            <div>
                                <p
                                    class="text-xs sm:text-sm font-medium text-primary"
                                >
                                    {{ authStore.user?.name }}
                                </p>
                                <p
                                    class="text-[10px] sm:text-xs text-secondary"
                                >
                                    Joining as yourself
                                </p>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="!authStore.isAuthenticated"
                        class="w-full space-y-3 mb-4"
                    >
                        <input
                            v-model="guestName"
                            placeholder="Enter your name"
                            class="w-full px-4 py-3 bg-surface-elevated border border-white/10 rounded-lg text-primary placeholder-text-muted focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all text-sm"
                            @keyup.enter="joinMeeting"
                        />
                        <input
                            v-model="guestEmail"
                            type="email"
                            placeholder="Enter your email address"
                            class="w-full px-4 py-3 bg-surface-elevated border border-white/10 rounded-lg text-primary placeholder-text-muted focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all text-sm"
                            @keyup.enter="joinMeeting"
                        />
                        <p
                            v-if="guestEmailError"
                            class="text-xs text-red-400 text-left"
                        >
                            {{ guestEmailError }}
                        </p>
                    </div>

                    <div
                        v-if="meeting?.has_password && !isHost"
                        class="w-full mb-6"
                    >
                        <label
                            class="block text-xs text-secondary mb-1.5 text-left"
                            >Meeting password</label
                        >
                        <input
                            v-model="password"
                            type="password"
                            placeholder="Enter meeting password"
                            class="w-full px-4 py-3 bg-surface-elevated border border-white/10 rounded-lg text-primary placeholder-text-muted focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all text-sm"
                            @keyup.enter="joinMeeting"
                        />
                    </div>

                    <div
                        class="flex flex-col sm:flex-row gap-3 sm:gap-4 w-full"
                    >
                        <button
                            @click="joinMeeting"
                            :disabled="
                                joining ||
                                (!authStore.isAuthenticated &&
                                    (!guestName.trim() ||
                                        !guestEmail.trim() ||
                                        !!guestEmailError)) ||
                                (meeting?.has_password &&
                                    !isHost &&
                                    !password.trim())
                            "
                            class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-full font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center min-h-[48px] text-sm sm:text-base"
                        >
                            <Icon
                                v-if="joining"
                                name="loader"
                                size="20"
                                class="animate-spin mr-2"
                            />
                            {{
                                joining
                                    ? "Joining..."
                                    : meeting?.status === "ended"
                                      ? "Restart & Join"
                                      : "Join now"
                            }}
                        </button>
                        <button
                            @click="joinAndPresent"
                            :disabled="
                                joining ||
                                (!authStore.isAuthenticated &&
                                    (!guestName.trim() ||
                                        !guestEmail.trim() ||
                                        !!guestEmailError)) ||
                                (meeting?.has_password &&
                                    !isHost &&
                                    !password.trim())
                            "
                            class="flex-1 bg-transparent hover:bg-surface-secondary text-blue-400 px-6 py-3 rounded-full font-medium transition-colors border border-white/10 min-h-[48px] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center text-sm sm:text-base"
                            title="Join meeting and start presenting"
                        >
                            <Icon
                                name="monitor-up"
                                size="18"
                                class="inline mr-2"
                            />
                            Present
                        </button>
                    </div>
                </template>
            </div>
        </div>

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
import { useBackgroundBlur } from "@/composables/useBackgroundBlur";
import { useThemeStore } from "@/stores/theme";
import DeviceSettingsModal from "./components/DeviceSettingsModal.vue";
import { Icon } from "@/components/ui";
import { toast } from "vue-sonner";
import { logManager } from "@/utils/LogManager";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const themeStore = useThemeStore();
const meetingStore = useMeetingStore();
const videoCallStore = useVideoCallStore();

const meetingId = route.params.id as string;
const meeting = ref<Meeting | null>(null);
const loading = ref(true);
const joining = ref(false);
const isEndedQuery = computed(() => route.query.ended === "1");
const showVideoSection = computed(() => {
    return !(
        (meeting.value?.status === "ended" || isEndedQuery.value) &&
        !isHost.value
    );
});

const localVideo = ref<HTMLVideoElement | null>(null);
const localStream = ref<MediaStream | null>(null);
const isCameraOn = ref(false);
const isMicOn = ref(false);
const backgroundBlur = useBackgroundBlur();
const guestName = ref("");
const guestEmail = ref("");
const guestEmailError = ref("");
const password = ref("");
const showSettings = ref(false);

logManager.init();

const isHost = computed(() => {
    if (!meeting.value || !authStore.user) return false;
    if (meeting.value.host?.public_id && authStore.user.public_id) {
        return meeting.value.host.public_id === authStore.user.public_id;
    }
    return meeting.value.user_id == authStore.user?.id;
});

onMounted(async () => {
    try {
        meeting.value = await meetingService.getMeeting(meetingId);
        const savedPwd = localStorage.getItem(
            `worksphere_meeting_pwd_${meetingId}`,
        );
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
    backgroundBlur.stopProcessing();
    if (localStream.value && !joining.value) {
        localStream.value.getTracks().forEach((t) => t.stop());
    }
    if (meetingStore.originalVideoTrack && !joining.value) {
        meetingStore.originalVideoTrack.stop();
        meetingStore.originalVideoTrack = null;
    }
});

const requestPermissionsAndStream = async () => {
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: true,
        });
        stream.getTracks().forEach((t) => t.stop());
        videoCallStore.hasPermissions = true;
        navigator.mediaDevices.enumerateDevices().then((devices) => {
            videoCallStore.devices = devices;
        });
    } catch (e) {
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
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    deviceId: videoCallStore.selectedVideoDeviceId || undefined,
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                    frameRate: { ideal: 30 },
                },
            });
            const videoTrack = stream.getVideoTracks()[0];
            meetingStore.originalVideoTrack = videoTrack;
            let finalTrack = videoTrack;
            if (
                (videoCallStore.videoEffect === "blur" ||
                    videoCallStore.videoEffect === "image") &&
                videoTrack
            ) {
                finalTrack = await backgroundBlur.startVideoEffect(
                    videoTrack,
                    videoCallStore.videoEffect,
                    videoCallStore.backgroundImage || undefined,
                    videoCallStore.autoFraming,
                );
            }
            localStream.value.addTrack(finalTrack);
            isCameraOn.value = true;
        } catch (e) {
            toast.error("Could not access camera hardware.");
        }
    } else {
        localStream.value.getVideoTracks().forEach((t) => {
            t.stop();
            localStream.value!.removeTrack(t);
        });
        if (meetingStore.originalVideoTrack) {
            meetingStore.originalVideoTrack.stop();
            meetingStore.originalVideoTrack = null;
        }
        backgroundBlur.stopProcessing();
        isCameraOn.value = false;
    }
};

watch(
    () => [localVideo.value, localStream.value],
    ([videoEl, stream]) => {
        if (
            videoEl &&
            stream &&
            (videoEl as HTMLVideoElement).srcObject !== stream
        ) {
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
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    deviceId: videoCallStore.selectedAudioDeviceId || undefined,
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });
            const audioTrack = stream.getAudioTracks()[0];
            localStream.value.addTrack(audioTrack);
            isMicOn.value = true;
        } catch (e) {
            toast.error("Could not access microphone hardware.");
        }
    } else {
        localStream.value.getAudioTracks().forEach((t) => {
            t.stop();
            localStream.value!.removeTrack(t);
        });
        isMicOn.value = false;
    }
};

watch(
    [
        () => videoCallStore.videoEffect,
        () => videoCallStore.backgroundImage,
        () => videoCallStore.autoFraming,
        () => videoCallStore.hasPhysicalGreenScreen,
        () => videoCallStore.greenScreenColor,
        () => videoCallStore.greenScreenThreshold,
    ],
    async ([
        effect,
        bgImage,
        framing,
        hasGreenScreen,
        greenColor,
        threshold,
    ]) => {
        if (
            !isCameraOn.value ||
            !meetingStore.originalVideoTrack ||
            !localStream.value
        )
            return;
        try {
            let newTrack: MediaStreamTrack;
            if (effect === "blur" || effect === "image") {
                newTrack = await backgroundBlur.startVideoEffect(
                    meetingStore.originalVideoTrack as MediaStreamTrack,
                    effect,
                    bgImage || undefined,
                    framing,
                    hasGreenScreen,
                    greenColor,
                    threshold,
                );
            } else {
                backgroundBlur.stopProcessing();
                newTrack = meetingStore.originalVideoTrack as MediaStreamTrack;
            }
            const oldTrack = localStream.value.getVideoTracks()[0];
            if (oldTrack && oldTrack.id !== newTrack.id) {
                localStream.value.removeTrack(oldTrack);
                localStream.value.addTrack(newTrack);
            }
        } catch (e) {
            console.error("[LOBBY] Failed to swap effect track", e);
        }
    },
);

watch(
    [
        () => videoCallStore.selectedAudioInput,
        () => videoCallStore.selectedVideoInput,
    ],
    async ([newAudio, newVideo], [oldAudio, oldVideo]) => {
        if (newAudio !== oldAudio || newVideo !== oldVideo) {
            if (!localStream.value) return;
            if (isMicOn.value && newAudio !== oldAudio) {
                localStream.value.getAudioTracks().forEach((t) => {
                    t.stop();
                    localStream.value!.removeTrack(t);
                });
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        audio: {
                            deviceId: newAudio || undefined,
                            echoCancellation: true,
                            noiseSuppression: true,
                            autoGainControl: true,
                        },
                    });
                    localStream.value.addTrack(stream.getAudioTracks()[0]);
                } catch (e) {
                    console.error(e);
                }
            }
            if (isCameraOn.value && newVideo !== oldVideo) {
                localStream.value.getVideoTracks().forEach((t) => {
                    t.stop();
                    localStream.value!.removeTrack(t);
                });
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            deviceId: newVideo || undefined,
                            width: { ideal: 1280 },
                            height: { ideal: 720 },
                        },
                    });
                    localStream.value.addTrack(stream.getVideoTracks()[0]);
                } catch (e) {
                    console.error(e);
                }
            }
        }
    },
);

watch(showSettings, (isOpen) => {
    if (isOpen && isCameraOn.value && localStream.value) {
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
        if (!authStore.isAuthenticated) {
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(guestEmail.value.trim())) {
                guestEmailError.value = "Please enter a valid email address";
                joining.value = false;
                return;
            }
        }
        if (localStream.value) {
            if (!isCameraOn.value) {
                localStream.value.getVideoTracks().forEach((t) => {
                    t.stop();
                    localStream.value?.removeTrack(t);
                });
            }
            if (!isMicOn.value) {
                localStream.value.getAudioTracks().forEach((t) => {
                    t.stop();
                    localStream.value?.removeTrack(t);
                });
            }
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
        if (e?.response?.status === 403) {
            toast.error(
                e?.response?.data?.message || "Incorrect meeting password.",
            );
        } else if (e?.response?.status === 401) {
            toast.error("You are not authorized to join this meeting.");
        } else {
            toast.error(
                `Failed to join: ${e?.response?.data?.message || e.message || "Unknown error"}`,
            );
        }
        joining.value = false;
    }
};

const joinAndPresent = async () => {
    try {
        joining.value = true;
        guestEmailError.value = "";
        if (!authStore.isAuthenticated) {
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(guestEmail.value.trim())) {
                guestEmailError.value = "Please enter a valid email address";
                joining.value = false;
                return;
            }
        }
        if (localStream.value) {
            if (!isCameraOn.value) {
                localStream.value.getVideoTracks().forEach((t) => {
                    t.stop();
                    localStream.value?.removeTrack(t);
                });
            }
            if (!isMicOn.value) {
                localStream.value.getAudioTracks().forEach((t) => {
                    t.stop();
                    localStream.value?.removeTrack(t);
                });
            }
            meetingStore.setStream(localStream.value);
        }
        const res = await meetingService.joinMeeting(
            meetingId,
            guestName.value,
            password.value || undefined,
            guestEmail.value.trim() || undefined,
            true,
        );
        router.push({
            name: "meeting-room",
            params: { id: meetingId },
            query: { participant: res.participant.public_id, present: "1" },
        });
    } catch (e: any) {
        if (e?.response?.status === 403) {
            toast.error(e?.response?.data?.message || "Access denied.");
        } else if (e?.response?.status === 401) {
            toast.error("You are not authorized to join this meeting.");
        } else {
            toast.error(
                `Failed to join: ${e?.response?.data?.message || e.message || "Unknown error"}`,
            );
        }
        joining.value = false;
    }
};
</script>

<style scoped>
.control-btn {
    width: 44px;
    height: 44px;
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

@media (min-width: 640px) {
    .control-btn {
        width: 56px;
        height: 56px;
    }
}
</style>
