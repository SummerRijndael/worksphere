<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed, watch } from "vue";
import { Icon, SelectFilter } from "@/components/ui";
import { ProgressRoot, ProgressIndicator } from "reka-ui";
import { useVideoCallStore } from "@/stores/videocall";

const store = useVideoCallStore();

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

// Format device lists for SelectFilter
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

// Helper to get devices
async function loadDevices() {
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        audioInputs.value = devices.filter((d) => d.kind === "audioinput");
        audioOutputs.value = devices.filter((d) => d.kind === "audiooutput");
        videoInputs.value = devices.filter((d) => d.kind === "videoinput");

        // Set initial selection if store is empty
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
        console.error("[HardwareSetup] Failed to load devices", e);
    }
}

// Mic Visualizer Logic
async function startVisualizer(deviceId: string) {
    stopVisualizer();
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: { deviceId: { exact: deviceId } },
        });
        microphoneStream.value = stream;

        audioContext.value = new AudioContext();
        if (audioContext.value.state === 'suspended') {
            await audioContext.value.resume();
        }
        
        analyser.value = audioContext.value.createAnalyser();
        analyser.value.fftSize = 512;
        const source = audioContext.value.createMediaStreamSource(stream);
        
        gainNode = audioContext.value.createGain();
        gainNode.gain.value = micGain.value;
        
        source.connect(gainNode);
        gainNode.connect(analyser.value);
        
        drawVisualizer();
    } catch (e) {
        console.error("[HardwareSetup] Failed to start mic visualizer", e);
    }
}

function stopVisualizer() {
    if (animationFrame) cancelAnimationFrame(animationFrame);
    if (microphoneStream.value) {
        microphoneStream.value.getTracks().forEach(t => t.stop());
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
    if (gainNode) gainNode.gain.value = value;
}

// Speaker Test Logic
async function toggleSpeakerTest() {
    if (speakerTestPlaying.value) {
        stopSpeakerTest();
        return;
    }
    
    try {
        speakerTestAudio = new Audio('/static/sounds/inbound-call.mp3');
        speakerTestAudio.volume = store.globalVolume;
        
        if (store.selectedOutputDeviceId && (speakerTestAudio as any).setSinkId) {
            await (speakerTestAudio as any).setSinkId(store.selectedOutputDeviceId);
        }
        
        speakerAudioContext = new AudioContext();
        speakerAnalyser = speakerAudioContext.createAnalyser();
        speakerAnalyser.fftSize = 512;
        
        const source = speakerAudioContext.createMediaElementSource(speakerTestAudio);
        source.connect(speakerAnalyser);
        speakerAnalyser.connect(speakerAudioContext.destination);
        
        speakerTestAudio.addEventListener('ended', stopSpeakerTest);
        await speakerTestAudio.play();
        speakerTestPlaying.value = true;
        drawSpeakerMeter();
    } catch (e) {
        console.error('[HardwareSetup] Speaker test failed:', e);
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
    if (speakerAnimationFrame) cancelAnimationFrame(speakerAnimationFrame);
    if (speakerAudioContext) speakerAudioContext.close();
    speakerAnalyser = null;
    speakerTestPlaying.value = false;
    speakerLevel.value = 0;
}

watch(() => store.selectedAudioDeviceId, (newId) => {
    if (newId) startVisualizer(newId);
});

onMounted(() => {
    loadDevices();
    if (store.selectedAudioDeviceId) {
        startVisualizer(store.selectedAudioDeviceId);
    }
});

onBeforeUnmount(() => {
    stopVisualizer();
    stopSpeakerTest();
});
</script>

<template>
    <div class="space-y-6 bg-(--surface-primary) rounded-xl border border-(--border-subtle) p-6">
        <div class="flex items-center gap-2 mb-2">
            <Icon name="Settings" size="18" class="text-blue-500" />
            <h3 class="text-sm font-bold text-(--text-primary) uppercase tracking-wider">Hardware Setup</h3>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <!-- Audio Section -->
            <div class="space-y-6">
                <!-- Microphone -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-(--text-secondary) uppercase tracking-wider">Microphone</label>
                        <span class="text-[10px] font-mono text-success tabular-nums font-bold">{{ Math.round(volumeLevel) }}%</span>
                    </div>
                    
                    <SelectFilter
                        v-model="store.selectedAudioDeviceId"
                        :options="audioInputOptions"
                        placeholder="Select Microphone"
                        class="w-full"
                    />

                    <ProgressRoot 
                        :model-value="volumeLevel" 
                        class="relative h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                    >
                        <ProgressIndicator 
                            class="h-full w-full transition-transform duration-100" 
                            :style="{ 
                                transform: `translateX(-${100 - volumeLevel}%)`,
                                backgroundColor: volumeLevel > 85 ? '#ef4444' : (volumeLevel > 70 ? '#eab308' : '#22c55e')
                            }" 
                        />
                    </ProgressRoot>

                    <div class="flex items-center gap-3">
                        <Icon name="Mic" size="14" class="text-(--text-muted)" />
                        <input 
                            type="range" min="0" max="2" step="0.01" :value="micGain"
                            @input="(e) => setMicGain(parseFloat((e.target as HTMLInputElement).value))"
                            class="flex-1 h-1 bg-(--surface-tertiary) rounded-full appearance-none cursor-pointer"
                        />
                    </div>
                </div>

                <!-- Speaker -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-(--text-secondary) uppercase tracking-wider">Speakers</label>
                        <button
                            class="text-[9px] font-bold uppercase tracking-tight px-2 py-1 rounded bg-(--surface-tertiary) text-(--text-secondary) hover:bg-(--surface-tertiary)/80"
                            :class="{ 'bg-blue-600 text-white': speakerTestPlaying }"
                            @click="toggleSpeakerTest"
                        >
                            {{ speakerTestPlaying ? '■ Stop' : '▶ Test' }}
                        </button>
                    </div>

                    <SelectFilter
                        v-model="store.selectedOutputDeviceId"
                        :options="audioOutputOptions"
                        placeholder="Select Speaker"
                        class="w-full"
                    />

                    <ProgressRoot 
                        :model-value="speakerLevel" 
                        class="relative h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                    >
                        <ProgressIndicator 
                            class="h-full w-full transition-transform duration-100" 
                            :style="{ 
                                transform: `translateX(-${100 - speakerLevel}%)`,
                                backgroundColor: speakerLevel > 85 ? '#ef4444' : (speakerLevel > 70 ? '#eab308' : '#22c55e')
                            }" 
                        />
                    </ProgressRoot>
                    
                    <div class="flex items-center gap-3">
                        <Icon name="Volume2" size="14" class="text-(--text-muted)" />
                        <input 
                            type="range" min="0" max="1" step="0.01" :value="store.globalVolume"
                            @input="(e) => store.setGlobalVolume(parseFloat((e.target as HTMLInputElement).value))"
                            class="flex-1 h-1 bg-(--surface-tertiary) rounded-full appearance-none cursor-pointer"
                        />
                    </div>
                </div>
            </div>

            <!-- Video Section -->
            <div class="space-y-6">
                <div class="space-y-3">
                    <label class="text-xs font-semibold text-(--text-secondary) uppercase tracking-wider">Video Camera</label>
                    <SelectFilter
                        v-model="store.selectedVideoDeviceId"
                        :options="videoInputOptions"
                        placeholder="Select Camera"
                        class="w-full"
                    />
                </div>

                <!-- Video Effects -->
                <div class="space-y-3">
                    <label class="text-[10px] font-bold uppercase tracking-wider text-(--text-tertiary)">Background Effect</label>
                    <div class="flex gap-2">
                        <button 
                            @click="store.setVideoEffect('none')"
                            class="flex-1 py-2 px-3 rounded-lg border text-[10px] font-medium transition-all"
                            :class="store.videoEffect === 'none' ? 'border-blue-500 bg-blue-500/5 text-blue-500' : 'border-(--border-subtle) text-(--text-secondary)'"
                        >
                            Off
                        </button>
                        <button 
                            @click="store.setVideoEffect('blur')"
                            class="flex-1 py-2 px-3 rounded-lg border text-[10px] font-medium transition-all"
                            :class="store.videoEffect === 'blur' ? 'border-blue-500 bg-blue-500/5 text-blue-500' : 'border-(--border-subtle) text-(--text-secondary)'"
                        >
                            Blur
                        </button>
                        <button 
                            @click="store.setVideoEffect('image')"
                            class="flex-1 py-2 px-3 rounded-lg border text-[10px] font-medium transition-all"
                            :class="store.videoEffect === 'image' ? 'border-blue-500 bg-blue-500/5 text-blue-500' : 'border-(--border-subtle) text-(--text-secondary)'"
                        >
                            Image
                        </button>
                    </div>
                </div>

                <!-- Auto-Framing -->
                <div class="flex items-center justify-between p-3 rounded-lg border border-(--border-subtle) bg-(--surface-secondary)/50">
                    <div class="flex items-center gap-2">
                        <Icon name="Maximize" size="14" class="text-(--text-secondary)" />
                        <span class="text-xs font-medium text-(--text-primary)">AI Auto-Framing</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input 
                            type="checkbox" 
                            class="sr-only peer"
                            :checked="store.autoFraming"
                            @change="(e: any) => store.setAutoFraming(e.target.checked)"
                        >
                        <div class="toggle-switch-thumb"></div>
                    </label>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
input[type="range"] {
    appearance: none;
    background: var(--surface-tertiary);
    border-radius: 9999px;
    height: 4px;
}

input[type="range"]::-webkit-slider-thumb {
    appearance: none;
    width: 12px;
    height: 12px;
    border-radius: 9999px;
    background-color: #2563eb; /* blue-600 */
    border: 1px solid white;
    cursor: pointer;
}

input[type="range"]::-moz-range-thumb {
    width: 12px;
    height: 12px;
    border-radius: 9999px;
    background-color: #2563eb;
    border: 1px solid white;
    cursor: pointer;
}

/* Toggle Switch Styles */
.toggle-switch-thumb {
    width: 2rem;
    height: 1rem;
    background-color: #e5e7eb;
    border-radius: 9999px;
    position: relative;
    transition: background-color 0.2s;
    cursor: pointer;
}

:any-link(dark) .toggle-switch-thumb {
    background-color: #374151;
}

.peer:checked + .toggle-switch-thumb {
    background-color: #2563eb;
}

.toggle-switch-thumb::after {
    content: '';
    position: absolute;
    top: 2px;
    left: 2px;
    width: 12px;
    height: 12px;
    background-color: white;
    border-radius: 9999px;
    transition: transform 0.2s;
}

.peer:checked + .toggle-switch-thumb::after {
    transform: translateX(100%);
}

.peer:focus + .toggle-switch-thumb {
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
}
</style>
