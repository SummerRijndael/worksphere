<script setup lang="ts">
import {
    ref,
    onMounted,
    onBeforeUnmount,
    computed,
    watch,
    nextTick,
} from "vue";
import { Icon, Button, SelectFilter } from "@/components/ui";
import { ProgressRoot, ProgressIndicator } from "reka-ui";
import { useVideoCallStore } from "@/stores/videocall";
import { useBackgroundBlur } from "@/composables/useBackgroundBlur";

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits(["update:open", "close"]);

const store = useVideoCallStore();
const activeTab = ref<"audio" | "video">("audio");

// Device Lists
const audioInputs = ref<MediaDeviceInfo[]>([]);
const audioOutputs = ref<MediaDeviceInfo[]>([]);
const videoInputs = ref<MediaDeviceInfo[]>([]);

// Mic Visualizer
const audioContext = ref<AudioContext | null>(null);
const analyser = ref<AnalyserNode | null>(null);
const microphoneStream = ref<MediaStream | null>(null);
const volumeLevel = ref(0);
let animationFrame: number;

// Mic Volume (Gain)
const micGain = ref(1.0);
let gainNode: GainNode | null = null;

// Speaker Test
const speakerTestPlaying = ref(false);
const speakerLevel = ref(0);
let speakerTestAudio: HTMLAudioElement | null = null;
let speakerAudioContext: AudioContext | null = null;
let speakerAnalyser: AnalyserNode | null = null;
let speakerAnimationFrame: number | null = null;

// Camera Preview
const previewVideo = ref<HTMLVideoElement | null>(null);
const cameraStream = ref<MediaStream | null>(null);
const previewProcessedStream = ref<MediaStream | null>(null);
const cameraError = ref<string | null>(null);
const backgroundBlur = useBackgroundBlur();
let originalPreviewTrack: MediaStreamTrack | null = null;

const tabs = [
    { id: "audio", label: "Audio", icon: "Mic" },
    { id: "video", label: "Video", icon: "Video" },
] as const;

const audioInputOptions = computed(() =>
    audioInputs.value.map((d, i) => ({
        value: d.deviceId,
        label: d.label || `Microphone ${i + 1}`,
    })),
);

const audioOutputOptions = computed(() =>
    audioOutputs.value.map((d, i) => ({
        value: d.deviceId,
        label: d.label || `Speaker ${i + 1}`,
    })),
);

const videoInputOptions = computed(() =>
    videoInputs.value.map((d, i) => ({
        value: d.deviceId,
        label: d.label || `Camera ${i + 1}`,
    })),
);

async function loadDevices() {
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        audioInputs.value = devices.filter((d) => d.kind === "audioinput");
        audioOutputs.value = devices.filter((d) => d.kind === "audiooutput");
        videoInputs.value = devices.filter((d) => d.kind === "videoinput");

        if (!store.selectedAudioDeviceId && audioInputs.value.length > 0) {
            store.setSelectedAudioDevice(audioInputs.value[0].deviceId);
        }
        if (!store.selectedVideoDeviceId && videoInputs.value.length > 0) {
            store.setSelectedVideoDevice(videoInputs.value[0].deviceId);
        }
        if (!store.selectedOutputDeviceId && audioOutputs.value.length > 0) {
            store.setSelectedOutputDevice(audioOutputs.value[0].deviceId);
        }
    } catch (e) {
        console.error("Failed to load devices", e);
    }
}

async function startVisualizer(deviceId: string) {
    stopVisualizer();
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: {
                deviceId: { exact: deviceId },
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
        });
        microphoneStream.value = stream;

        audioContext.value = new AudioContext();
        if (audioContext.value.state === "suspended") {
            await audioContext.value.resume();
        }

        analyser.value = audioContext.value.createAnalyser();
        analyser.value.fftSize = 512;
        analyser.value.smoothingTimeConstant = 0.4;
        analyser.value.minDecibels = -90;
        analyser.value.maxDecibels = -10;

        const source = audioContext.value.createMediaStreamSource(stream);

        gainNode = audioContext.value.createGain();
        gainNode.gain.value = micGain.value;

        source.connect(gainNode);
        gainNode.connect(analyser.value);

        drawVisualizer();
    } catch (e) {
        console.error("Failed to start mic visualizer", e);
    }
}

function stopVisualizer() {
    if (animationFrame) cancelAnimationFrame(animationFrame);
    if (microphoneStream.value) {
        microphoneStream.value.getTracks().forEach((t) => t.stop());
        microphoneStream.value = null;
    }
    if (audioContext.value) {
        audioContext.value.close();
        audioContext.value = null;
    }
    gainNode = null;
    volumeLevel.value = 0;
}

function drawVisualizer() {
    if (!analyser.value) return;

    const bufferLength = analyser.value.fftSize;
    const dataArray = new Float32Array(bufferLength);
    analyser.value.getFloatTimeDomainData(dataArray);

    let sumSquares = 0;
    for (let i = 0; i < bufferLength; i++) {
        sumSquares += dataArray[i] * dataArray[i];
    }
    const rms = Math.sqrt(sumSquares / bufferLength);
    const target = Math.min(100, Math.max(0, rms * 400));

    volumeLevel.value = volumeLevel.value * 0.2 + target * 0.8;

    animationFrame = requestAnimationFrame(drawVisualizer);
}

function setMicGain(value: number) {
    micGain.value = value;
    if (gainNode) {
        gainNode.gain.value = value;
    }
}

async function toggleSpeakerTest() {
    if (speakerTestPlaying.value) {
        stopSpeakerTest();
        return;
    }

    try {
        speakerTestAudio = new Audio("/static/sounds/inbound-call.mp3");
        speakerTestAudio.volume = store.globalVolume;
        speakerTestAudio.loop = false;

        if (
            store.selectedOutputDeviceId &&
            (speakerTestAudio as any).setSinkId
        ) {
            try {
                await (speakerTestAudio as any).setSinkId(
                    store.selectedOutputDeviceId,
                );
            } catch (e) {
                console.warn(
                    "[DeviceSettings] Could not set output device:",
                    e,
                );
            }
        }

        speakerAudioContext = new AudioContext();
        if (speakerAudioContext.state === "suspended") {
            await speakerAudioContext.resume();
        }

        speakerAnalyser = speakerAudioContext.createAnalyser();
        speakerAnalyser.fftSize = 512;
        speakerAnalyser.smoothingTimeConstant = 0.4;
        speakerAnalyser.minDecibels = -90;
        speakerAnalyser.maxDecibels = -10;

        const source =
            speakerAudioContext.createMediaElementSource(speakerTestAudio);
        source.connect(speakerAnalyser);
        speakerAnalyser.connect(speakerAudioContext.destination);

        speakerTestAudio.addEventListener("ended", () => {
            stopSpeakerTest();
        });

        await speakerTestAudio.play();
        speakerTestPlaying.value = true;
        drawSpeakerMeter();
    } catch (e) {
        console.error("[DeviceSettings] Speaker test failed:", e);
    }
}

function drawSpeakerMeter() {
    if (!speakerAnalyser || !speakerTestPlaying.value) return;

    const bufferLength = speakerAnalyser.fftSize;
    const dataArray = new Float32Array(bufferLength);
    speakerAnalyser.getFloatTimeDomainData(dataArray);

    let sumSquares = 0;
    for (let i = 0; i < bufferLength; i++) {
        sumSquares += dataArray[i] * dataArray[i];
    }
    const rms = Math.sqrt(sumSquares / bufferLength);
    const target = Math.min(100, Math.max(0, rms * 250));

    speakerLevel.value = speakerLevel.value * 0.2 + target * 0.8;

    speakerAnimationFrame = requestAnimationFrame(drawSpeakerMeter);
}

function stopSpeakerTest() {
    if (speakerTestAudio) {
        speakerTestAudio.pause();
        speakerTestAudio.currentTime = 0;
        speakerTestAudio = null;
    }
    if (speakerAnimationFrame) {
        cancelAnimationFrame(speakerAnimationFrame);
        speakerAnimationFrame = null;
    }
    if (speakerAudioContext) {
        speakerAudioContext.close();
        speakerAudioContext = null;
    }
    speakerAnalyser = null;
    speakerTestPlaying.value = false;
    speakerLevel.value = 0;
}

async function startCameraPreview(deviceId?: string) {
    console.info(
        "[MODAL-TRACE] startCameraPreview called with deviceId:",
        deviceId,
    );
    stopCameraPreview();
    cameraError.value = null;
    try {
        const constraints: MediaStreamConstraints = {
            video: deviceId
                ? {
                      deviceId: { exact: deviceId },
                      width: { ideal: 1280 },
                      height: { ideal: 720 },
                  }
                : {
                      width: { ideal: 1280 },
                      height: { ideal: 720 },
                      facingMode: "user",
                  },
            audio: false,
        };
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        cameraStream.value = stream;
        console.info(
            "[MODAL-TRACE] Got camera stream. Tracks:",
            stream.getTracks().length,
        );
        originalPreviewTrack = stream.getVideoTracks()[0];

        await new Promise((r) => setTimeout(r, 50));

        if (store.videoEffect === "blur" && originalPreviewTrack) {
            console.info("[MODAL-TRACE] Applying blur background effect...");
            const processedTrack = await backgroundBlur.startVideoEffect(
                originalPreviewTrack,
                "blur",
            );
            previewProcessedStream.value = new MediaStream([processedTrack]);
        }
    } catch (e: any) {
        console.error("[MODAL-TRACE] Camera preview error:", e);
        cameraError.value =
            e.name === "NotAllowedError"
                ? "Camera access denied"
                : "Could not access camera";
    }
}

function stopCameraPreview() {
    console.info("[MODAL-TRACE] stopCameraPreview called");
    backgroundBlur.stopProcessing();
    if (cameraStream.value) {
        console.info("[MODAL-TRACE] Stopping base camera stream tracks");
        cameraStream.value.getTracks().forEach((t) => t.stop());
        cameraStream.value = null;
    }
    if (previewProcessedStream.value) {
        console.info("[MODAL-TRACE] Stopping processed stream tracks");
        previewProcessedStream.value.getTracks().forEach((t) => t.stop());
        previewProcessedStream.value = null;
    }
    originalPreviewTrack = null;
    // Removed srcObject reset here, handled by watcher
}

// Reliably bind the selected stream to the modal preview video element
watch(
    () => [
        previewVideo.value,
        cameraStream.value,
        previewProcessedStream.value,
    ],
    ([videoEl, rawStream, processedStream]) => {
        const stream = processedStream || rawStream;
        if (
            videoEl &&
            stream &&
            (videoEl as HTMLVideoElement).srcObject !== stream
        ) {
            console.info(
                "[MODAL-TRACE] Binding srcObject to modal video element",
            );
            (videoEl as HTMLVideoElement).srcObject = stream as MediaStream;
        } else if (videoEl && !stream) {
            (videoEl as HTMLVideoElement).srcObject = null;
        }
    },
    { immediate: true },
);

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            await loadDevices();
            await nextTick();
            if (activeTab.value === "audio" && store.selectedAudioDeviceId) {
                startVisualizer(store.selectedAudioDeviceId);
            }
            if (activeTab.value === "video") {
                startCameraPreview(store.selectedVideoDeviceId || undefined);
            }
        } else {
            stopVisualizer();
            stopCameraPreview();
            stopSpeakerTest();
        }
    },
);

watch(
    () => store.selectedAudioDeviceId,
    (newId) => {
        if (newId && props.open && activeTab.value === "audio") {
            startVisualizer(newId);
        }
    },
);

watch(activeTab, (tab) => {
    if (!props.open) return;
    if (tab === "audio") {
        stopCameraPreview();
        if (store.selectedAudioDeviceId) {
            startVisualizer(store.selectedAudioDeviceId);
        }
    } else if (tab === "video") {
        stopVisualizer();
        stopSpeakerTest();
        startCameraPreview(store.selectedVideoDeviceId || undefined);
    }
});

watch(
    [
        () => store.videoEffect,
        () => store.backgroundImage,
        () => store.autoFraming,
        () => store.hasPhysicalGreenScreen,
        () => store.greenScreenColor,
        () => store.greenScreenThreshold,
    ],
    async ([
        effect,
        bgImage,
        framing,
        hasGreenScreen,
        greenColor,
        threshold,
    ]) => {
        if (!props.open || activeTab.value !== "video" || !originalPreviewTrack)
            return;

        try {
            if (effect === "blur" || effect === "image") {
                const processedTrack = await backgroundBlur.startVideoEffect(
                    originalPreviewTrack,
                    effect,
                    bgImage || undefined,
                    framing,
                    hasGreenScreen,
                    greenColor,
                    threshold,
                );
                previewProcessedStream.value = new MediaStream([
                    processedTrack,
                ]);
            } else {
                backgroundBlur.stopProcessing();
                if (previewProcessedStream.value) {
                    previewProcessedStream.value
                        .getTracks()
                        .forEach((t) => t.stop());
                    previewProcessedStream.value = null;
                }
            }
        } catch (e) {
            console.error("[DeviceSettings] Error switching video effect:", e);
        }
    },
);

const presetImages = [
    {
        id: "office",
        url: "https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=1000",
    },
    {
        id: "living-room",
        url: "https://images.unsplash.com/photo-1583847268964-b28dc8f51f92?q=80&w=1000",
    },
    {
        id: "beach",
        url: "https://images.unsplash.com/photo-1507525428034-b723cf961d3e?q=80&w=1000",
    },
    {
        id: "mountain",
        url: "https://images.unsplash.com/photo-1464822759023-fed622ff2c3b?q=80&w=1000",
    },
];

const handleImageUpload = (event: Event) => {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = (e) => {
            const result = e.target?.result as string;
            store.setBackgroundImage(result);
            if (store.videoEffect === "image") {
                store.setVideoEffect("image");
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
};

const selectPreset = (url: string) => {
    store.setBackgroundImage(url);
    store.setVideoEffect("image");
};

const handleAutoDetect = async () => {
    if (originalPreviewTrack) {
        const detectedColor =
            await backgroundBlur.autoDetectGreenScreenColor(
                originalPreviewTrack,
            );
        store.setGreenScreenColor(detectedColor);
        if (!store.hasPhysicalGreenScreen) {
            store.setHasPhysicalGreenScreen(true);
        }
    }
};

watch(
    () => store.selectedVideoDeviceId,
    (newId) => {
        if (newId && props.open && activeTab.value === "video") {
            startCameraPreview(newId);
        }
    },
);

onMounted(() => {
    if (props.open) loadDevices();
});

onBeforeUnmount(() => {
    stopVisualizer();
    stopCameraPreview();
    stopSpeakerTest();
});
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-1040 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm"
        @click.self="$emit('close')"
    >
        <div
            class="bg-(--surface-primary) rounded-xl border border-(--border-subtle) ring-1 ring-black/5 dark:ring-white/5 shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col h-auto max-h-[90vh] sm:h-[540px]"
        >
            <div class="flex-1 flex flex-col sm:flex-row overflow-hidden">
                <!-- Sidebar / Mobile Tabs -->
                <div
                    class="w-full sm:w-44 bg-(--surface-secondary)/30 border-b sm:border-b-0 sm:border-r border-(--border-subtle) flex flex-col pt-2 sm:pt-4"
                >
                    <div
                        class="px-3 pb-2 sm:pb-4 flex-1 overflow-x-auto sm:overflow-y-auto custom-scrollbar"
                    >
                        <div class="flex sm:flex-col gap-1">
                            <button
                                v-for="tab in tabs"
                                :key="tab.id"
                                class="flex-1 sm:w-full flex items-center justify-center sm:justify-start gap-2.5 px-3 py-2 text-sm font-medium rounded-lg transition-all group whitespace-nowrap"
                                :class="[
                                    activeTab === tab.id
                                        ? 'bg-blue-600/10 text-blue-600 dark:text-blue-400'
                                        : 'text-(--text-secondary) hover:text-(--text-primary) hover:bg-(--surface-tertiary)/50',
                                ]"
                                @click="activeTab = tab.id"
                            >
                                <Icon
                                    :name="tab.icon"
                                    size="16"
                                    :class="
                                        activeTab === tab.id
                                            ? 'text-blue-600 dark:text-blue-400'
                                            : 'text-(--text-tertiary) group-hover:text-(--text-secondary)'
                                    "
                                />
                                {{ tab.label }}
                            </button>
                        </div>
                    </div>

                    <div
                        class="hidden sm:block p-4 border-t border-(--border-subtle)"
                    >
                        <div class="flex items-center gap-2 px-1">
                            <div
                                class="h-1.5 w-1.5 rounded-full bg-success"
                            ></div>
                            <span
                                class="text-[10px] font-bold uppercase tracking-tight text-(--text-muted)"
                                >Live Status</span
                            >
                        </div>
                    </div>
                </div>

                <!-- Content Area -->
                <div
                    class="flex-1 overflow-y-auto custom-scrollbar bg-(--surface-primary)"
                >
                    <div class="p-4 sm:p-8 space-y-6 sm:space-y-8">
                        <!-- Header -->
                        <div class="space-y-1">
                            <h2 class="text-xl font-bold text-(--text-primary)">
                                Device Settings
                            </h2>
                            <p class="text-xs text-(--text-secondary)">
                                Manage your audio and video devices before
                                joining.
                            </p>
                        </div>

                        <!-- Audio Settings -->
                        <div v-if="activeTab === 'audio'" class="space-y-8">
                            <!-- Microphone Section -->
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <label
                                        class="text-sm font-semibold text-(--text-primary)"
                                        >Input Device</label
                                    >
                                    <span
                                        class="text-[10px] font-mono text-success tabular-nums font-bold"
                                        >{{ Math.round(volumeLevel) }}%</span
                                    >
                                </div>

                                <div class="space-y-4">
                                    <SelectFilter
                                        v-model="store.selectedAudioDeviceId"
                                        :options="audioInputOptions"
                                        placeholder="Select Microphone"
                                        class="w-full"
                                    />

                                    <!-- LED Mic Meter -->
                                    <ProgressRoot
                                        :model-value="volumeLevel"
                                        :max="100"
                                        class="relative h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                                        style="transform: translateZ(0)"
                                    >
                                        <ProgressIndicator
                                            class="h-full w-full transition-transform duration-100 ease-out will-change-transform"
                                            :style="{
                                                transform: `translateX(-${100 - volumeLevel}%)`,
                                                backgroundColor:
                                                    volumeLevel > 85
                                                        ? '#ef4444'
                                                        : volumeLevel > 70
                                                          ? '#eab308'
                                                          : '#22c55e',
                                            }"
                                        />
                                    </ProgressRoot>

                                    <!-- Mic Gain Slider -->
                                    <div class="flex items-center gap-4">
                                        <Icon
                                            :name="
                                                micGain === 0 ? 'MicOff' : 'Mic'
                                            "
                                            size="16"
                                            class="text-(--text-muted)"
                                        />
                                        <input
                                            type="range"
                                            min="0"
                                            max="2"
                                            step="0.01"
                                            :value="micGain"
                                            @input="
                                                (e) =>
                                                    setMicGain(
                                                        parseFloat(
                                                            (
                                                                e.target as HTMLInputElement
                                                            ).value,
                                                        ),
                                                    )
                                            "
                                            class="flex-1 h-1 bg-(--surface-tertiary) rounded-full appearance-none cursor-pointer [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-3.5 [&::-webkit-slider-thumb]:h-3.5 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-blue-600 [&::-webkit-slider-thumb]:shadow-md [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-white dark:[&::-webkit-slider-thumb]:border-(--surface-primary) hover:[&::-webkit-slider-thumb]:scale-110 transition-all"
                                        />
                                        <span
                                            class="text-[10px] font-mono text-(--text-secondary) w-8 tabular-nums font-bold"
                                            >{{
                                                Math.round(micGain * 100)
                                            }}%</span
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Speakers Section -->
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <label
                                        class="text-sm font-semibold text-(--text-primary)"
                                        >Output Device</label
                                    >
                                    <button
                                        class="text-[10px] font-bold uppercase tracking-tight px-2 py-1 rounded-md transition-all"
                                        :class="
                                            speakerTestPlaying
                                                ? 'bg-blue-600 text-white'
                                                : 'bg-(--surface-tertiary) text-(--text-secondary) hover:bg-(--surface-tertiary)/80'
                                        "
                                        @click="toggleSpeakerTest"
                                    >
                                        {{
                                            speakerTestPlaying
                                                ? "■ Stop"
                                                : "▶ Test"
                                        }}
                                    </button>
                                </div>

                                <div class="space-y-4">
                                    <SelectFilter
                                        v-if="audioOutputs.length > 0"
                                        v-model="store.selectedOutputDeviceId"
                                        :options="audioOutputOptions"
                                        placeholder="Select Speaker"
                                        class="w-full"
                                    />
                                    <div
                                        v-else
                                        class="text-xs text-(--text-tertiary) italic"
                                    >
                                        Using system default output.
                                    </div>

                                    <!-- Speaker LED Meter -->
                                    <ProgressRoot
                                        :model-value="speakerLevel"
                                        :max="100"
                                        class="relative h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                                        style="transform: translateZ(0)"
                                    >
                                        <ProgressIndicator
                                            class="h-full w-full transition-transform duration-100 ease-out will-change-transform"
                                            :style="{
                                                transform: `translateX(-${100 - speakerLevel}%)`,
                                                backgroundColor:
                                                    speakerLevel > 85
                                                        ? '#ef4444'
                                                        : speakerLevel > 70
                                                          ? '#eab308'
                                                          : '#22c55e',
                                            }"
                                        />
                                    </ProgressRoot>

                                    <!-- Speaker Volume Slider -->
                                    <div class="flex items-center gap-4">
                                        <Icon
                                            :name="
                                                store.globalVolume === 0
                                                    ? 'VolumeX'
                                                    : 'Volume1'
                                            "
                                            size="16"
                                            class="text-(--text-muted)"
                                        />
                                        <input
                                            type="range"
                                            min="0"
                                            max="1"
                                            step="0.01"
                                            :value="store.globalVolume"
                                            @input="
                                                (e) =>
                                                    store.setGlobalVolume(
                                                        parseFloat(
                                                            (
                                                                e.target as HTMLInputElement
                                                            ).value,
                                                        ),
                                                    )
                                            "
                                            class="flex-1 h-1 bg-(--surface-tertiary) rounded-full appearance-none cursor-pointer [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-3.5 [&::-webkit-slider-thumb]:h-3.5 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-blue-600 [&::-webkit-slider-thumb]:shadow-md [&::-webkit-slider-thumb]:border-2 [&::-webkit-slider-thumb]:border-white dark:[&::-webkit-slider-thumb]:border-(--surface-primary) hover:[&::-webkit-slider-thumb]:scale-110 transition-all"
                                        />
                                        <span
                                            class="text-[10px] font-mono text-(--text-secondary) w-8 tabular-nums font-bold"
                                            >{{
                                                Math.round(
                                                    store.globalVolume * 100,
                                                )
                                            }}%</span
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Video Settings -->
                        <div v-if="activeTab === 'video'" class="space-y-8">
                            <div class="space-y-3">
                                <label
                                    class="text-sm font-semibold text-(--text-primary)"
                                    >Active Camera</label
                                >

                                <div class="space-y-4">
                                    <SelectFilter
                                        v-model="store.selectedVideoDeviceId"
                                        :options="videoInputOptions"
                                        placeholder="Select Camera"
                                        class="w-full"
                                    />

                                    <!-- Camera Preview -->
                                    <div
                                        class="aspect-video bg-black rounded-xl border border-(--border-muted) flex items-center justify-center relative overflow-hidden shadow-inner"
                                    >
                                        <video
                                            v-show="
                                                cameraStream && !cameraError
                                            "
                                            ref="previewVideo"
                                            autoplay
                                            playsinline
                                            muted
                                            class="w-full h-full object-cover rounded-xl mirror"
                                        ></video>
                                        <div
                                            v-if="cameraError"
                                            class="flex flex-col items-center gap-2"
                                        >
                                            <Icon
                                                name="CameraOff"
                                                size="24"
                                                class="text-red-400 opacity-60"
                                            />
                                            <span
                                                class="text-xs text-red-400"
                                                >{{ cameraError }}</span
                                            >
                                        </div>
                                        <div
                                            v-else-if="!cameraStream"
                                            class="flex flex-col items-center gap-2"
                                        >
                                            <Icon
                                                name="Camera"
                                                size="24"
                                                class="text-(--text-muted) opacity-30"
                                            />
                                            <span
                                                class="text-[10px] text-(--text-muted)"
                                                >Loading preview...</span
                                            >
                                        </div>
                                        <div
                                            v-if="cameraStream && !cameraError"
                                            class="absolute bottom-2 left-2 flex items-center gap-1.5 bg-black/50 px-2 py-1 rounded-md"
                                        >
                                            <div
                                                class="h-1.5 w-1.5 rounded-full bg-green-400 animate-pulse"
                                            ></div>
                                            <span
                                                class="text-[9px] font-bold text-white uppercase tracking-wider"
                                                >Live</span
                                            >
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div
                                class="space-y-4 pt-4 border-t border-(--border-subtle)"
                            >
                                <div class="flex items-center justify-between">
                                    <label
                                        class="text-xs font-semibold uppercase tracking-wider text-(--text-tertiary)"
                                        >Background Effects</label
                                    >
                                    <span v-if="!backgroundBlur.isSupported.value" class="text-[10px] font-medium text-red-500 bg-red-500/10 px-2 py-0.5 rounded">Device Unsupported</span>
                                </div>
                                <div
                                    class="grid grid-cols-2 xs:grid-cols-3 sm:grid-cols-4 gap-3"
                                    :class="{ 'opacity-50 pointer-events-none grayscale': !backgroundBlur.isSupported.value }"
                                >
                                    <!-- None -->
                                    <button
                                        @click="store.setVideoEffect('none')"
                                        class="aspect-video rounded-lg border-2 flex flex-col items-center justify-center gap-1.5 transition-all bg-(--surface-tertiary)/10 hover:bg-(--surface-tertiary)/20 group"
                                        :class="
                                            store.videoEffect === 'none'
                                                ? 'border-blue-500 bg-blue-500/5'
                                                : 'border-transparent hover:border-(--border-subtle)'
                                        "
                                    >
                                        <div
                                            class="p-1.5 rounded-full bg-(--surface-tertiary)/30 group-hover:scale-110 transition-transform"
                                        >
                                            <Icon
                                                name="Ban"
                                                size="14"
                                                class="text-(--text-secondary)"
                                            />
                                        </div>
                                        <span
                                            class="text-[10px] font-medium text-(--text-secondary)"
                                            >Off</span
                                        >
                                    </button>

                                    <!-- Blur -->
                                    <button
                                        @click="store.setVideoEffect('blur')"
                                        class="aspect-video rounded-lg border-2 flex flex-col items-center justify-center gap-1.5 transition-all bg-(--surface-tertiary)/10 hover:bg-(--surface-tertiary)/20 group"
                                        :class="
                                            store.videoEffect === 'blur'
                                                ? 'border-blue-500 bg-blue-500/5'
                                                : 'border-transparent hover:border-(--border-subtle)'
                                        "
                                    >
                                        <div
                                            class="p-1.5 rounded-full bg-(--surface-tertiary)/30 group-hover:scale-110 transition-transform"
                                        >
                                            <Icon
                                                name="Aperture"
                                                size="14"
                                                class="text-(--text-secondary)"
                                            />
                                        </div>
                                        <span
                                            class="text-[10px] font-medium text-(--text-secondary)"
                                            >Blur</span
                                        >
                                    </button>

                                    <!-- Presets -->
                                    <button
                                        v-for="img in presetImages"
                                        :key="img.id"
                                        @click="selectPreset(img.url)"
                                        class="aspect-video rounded-lg border-2 overflow-hidden transition-all relative group"
                                        :class="
                                            store.videoEffect === 'image' &&
                                            store.backgroundImage === img.url
                                                ? 'border-blue-500'
                                                : 'border-transparent hover:border-(--border-subtle)'
                                        "
                                    >
                                        <img
                                            :src="img.url"
                                            class="w-full h-full object-cover transition-transform group-hover:scale-110"
                                        />
                                        <div
                                            class="absolute inset-0 bg-blue-600/20 flex items-center justify-center transition-opacity pointer-events-none"
                                            :class="
                                                store.videoEffect === 'image' &&
                                                store.backgroundImage ===
                                                    img.url
                                                    ? 'opacity-100'
                                                    : 'opacity-0'
                                            "
                                        >
                                            <div
                                                class="bg-blue-600 rounded-full p-1 shadow-lg scale-90 group-hover:scale-100 transition-transform"
                                            >
                                                <Icon
                                                    name="Check"
                                                    size="10"
                                                    class="text-white"
                                                />
                                            </div>
                                        </div>
                                    </button>

                                    <!-- Custom Upload -->
                                    <label
                                        class="aspect-video rounded-lg border-2 border-dashed border-(--border-subtle) flex flex-col items-center justify-center gap-1.5 cursor-pointer hover:bg-(--surface-tertiary)/10 hover:border-blue-500/50 transition-all group"
                                    >
                                        <input
                                            type="file"
                                            class="hidden"
                                            accept="image/*"
                                            @change="handleImageUpload"
                                        />
                                        <div
                                            class="p-1.5 rounded-full bg-(--surface-tertiary)/30 group-hover:scale-110 transition-transform"
                                        >
                                            <Icon
                                                name="Plus"
                                                size="14"
                                                class="text-(--text-secondary)"
                                            />
                                        </div>
                                        <span
                                            class="text-[10px] font-medium text-(--text-secondary)"
                                            >Custom</span
                                        >
                                    </label>
                                </div>

                                <!-- Chroma Key / Physical Green Screen -->
                                <div
                                    class="bg-(--surface-tertiary)/10 rounded-xl p-4 border border-(--border-subtle) space-y-4"
                                >
                                    <div
                                        class="flex items-center justify-between"
                                    >
                                        <div class="space-y-0.5">
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <div
                                                    class="h-2 w-2 rounded-full bg-green-500"
                                                ></div>
                                                <span
                                                    class="text-sm font-semibold text-(--text-primary)"
                                                    >I have a green screen</span
                                                >
                                            </div>
                                            <p
                                                class="text-[10px] text-(--text-tertiary)"
                                            >
                                                Improves edge quality and saves
                                                CPU power.
                                            </p>
                                        </div>
                                        <label
                                            class="relative inline-flex items-center cursor-pointer"
                                        >
                                            <input
                                                type="checkbox"
                                                class="sr-only peer"
                                                v-model="
                                                    store.hasPhysicalGreenScreen
                                                "
                                            />
                                            <div
                                                class="w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"
                                            ></div>
                                        </label>
                                    </div>

                                    <div
                                        v-if="store.hasPhysicalGreenScreen"
                                        class="flex items-center gap-4 pt-2 border-t border-(--border-subtle)/30 animate-in fade-in slide-in-from-top-1 duration-200"
                                    >
                                        <div class="space-y-2 flex-1">
                                            <label
                                                class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted)"
                                                >Screen Color</label
                                            >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <div
                                                    class="h-8 w-8 rounded-lg border border-(--border-subtle) shadow-sm shrink-0"
                                                    :style="{
                                                        backgroundColor:
                                                            store.greenScreenColor,
                                                    }"
                                                ></div>
                                                <input
                                                    type="color"
                                                    v-model="
                                                        store.greenScreenColor
                                                    "
                                                    class="opacity-0 absolute w-8 h-8 cursor-pointer"
                                                />
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-8 text-[10px] px-2"
                                                    @click="handleAutoDetect"
                                                >
                                                    <Icon
                                                        name="Pipette"
                                                        size="12"
                                                        class="mr-1"
                                                    />
                                                    Auto-detect
                                                </Button>
                                            </div>
                                        </div>

                                        <div class="space-y-2 flex-1">
                                            <label
                                                class="text-[10px] font-bold uppercase tracking-wider text-(--text-muted)"
                                                >Sensitivity</label
                                            >
                                            <div
                                                class="flex items-center gap-3"
                                            >
                                                <input
                                                    type="range"
                                                    min="0.01"
                                                    max="0.5"
                                                    step="0.01"
                                                    :value="
                                                        store.greenScreenThreshold
                                                    "
                                                    @input="
                                                        (e) =>
                                                            store.setGreenScreenThreshold(
                                                                parseFloat(
                                                                    (
                                                                        e.target as HTMLInputElement
                                                                    ).value,
                                                                ),
                                                            )
                                                    "
                                                    class="flex-1 h-1 bg-(--surface-tertiary) rounded-full appearance-none cursor-pointer [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-3 [&::-webkit-slider-thumb]:h-3 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-blue-600 shadow-sm"
                                                />
                                                <span
                                                    class="text-[10px] font-mono text-(--text-secondary) w-6"
                                                    >{{
                                                        Math.round(
                                                            store.greenScreenThreshold *
                                                                100,
                                                        )
                                                    }}</span
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="px-6 py-4 bg-(--surface-secondary) border-t border-(--border-subtle) flex justify-end gap-3 ring-1 ring-black/5"
            >
                <button class="btn btn-ghost text-sm" @click="$emit('close')">
                    Cancel
                </button>
                <Button @click="$emit('close')"> Done </Button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: var(--scrollbar-thumb, rgba(0, 0, 0, 0.1));
    border-radius: 10px;
}

.dark .custom-scrollbar::-webkit-scrollbar-thumb {
    background: var(--scrollbar-thumb, rgba(255, 255, 255, 0.1));
}

.mirror {
    transform: scaleX(-1);
}
</style>
