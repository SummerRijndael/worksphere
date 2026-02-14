<script setup lang="ts">
import { ref, onMounted, computed, watch } from "vue";
import { Modal, Icon, Button } from "@/components/ui";
import { useVideoCallStore } from "@/stores/videocall";

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

// Visualizer
const audioContext = ref<AudioContext | null>(null);
const analyser = ref<AnalyserNode | null>(null);
const microphoneStream = ref<MediaStream | null>(null);
const volumeLevel = ref(0);
let animationFrame: number;

const tabs = [
    { id: "audio", label: "Audio", icon: "Mic" },
    { id: "video", label: "Video", icon: "Video" },
];

// Helper to get devices
async function loadDevices() {
    try {
        // Ensure permissions are granted so labels show up
        // We might already have streams, but let's be safe.
        // In a real app, you might want to only do this if streams exist.
        
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
    } catch (e) {
        console.error("Failed to load devices", e);
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
        analyser.value = audioContext.value.createAnalyser();
        analyser.value.fftSize = 256;
        
        const source = audioContext.value.createMediaStreamSource(stream);
        source.connect(analyser.value);
        
        drawVisualizer();
    } catch (e) {
        console.error("Failed to start mic visualizer", e);
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
}

function drawVisualizer() {
    if (!analyser.value) return;
    const dataArray = new Uint8Array(analyser.value.frequencyBinCount);
    analyser.value.getByteFrequencyData(dataArray);
    
    // Average volume
    let sum = 0;
    for (const v of dataArray) sum += v;
    const average = sum / dataArray.length;
    
    // Smooth transition
    volumeLevel.value = Math.min(100, Math.max(0, (average / 128) * 100)); // Normalize roughly
    
    animationFrame = requestAnimationFrame(drawVisualizer);
}

// Watchers
watch(() => props.open, (isOpen) => {
    if (isOpen) {
        loadDevices();
        if (store.selectedAudioDeviceId) {
            startVisualizer(store.selectedAudioDeviceId);
        }
    } else {
        stopVisualizer();
    }
});

watch(() => store.selectedAudioDeviceId, (newId) => {
    if (newId && props.open) {
        startVisualizer(newId);
    }
});

onMounted(() => {
    // If modal is already open on mount (unlikely but possible)
    if (props.open) loadDevices();
});

</script>

<template>
    <Modal
        :open="open"
        title="Call Settings"
        description="Configure your audio and video devices."
        size="2xl"
        @update:open="$emit('update:open', $event)"
        @close="$emit('close')"
    >
        <div class="flex h-[450px]">
            <!-- Sidebar -->
            <div class="w-48 border-r border-(--border-default) p-2 space-y-1">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    class="w-full flex items-center gap-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors"
                    :class="[
                        activeTab === tab.id
                            ? 'bg-(--interactive-primary) text-white'
                            : 'text-(--text-secondary) hover:bg-(--surface-hover)'
                    ]"
                    @click="activeTab = tab.id"
                >
                    <Icon :name="tab.icon" size="16" />
                    {{ tab.label }}
                </button>
            </div>

            <!-- Content -->
            <div class="flex-1 p-6 overflow-y-auto custom-scrollbar">
                
                <!-- Audio Settings -->
                <div v-if="activeTab === 'audio'" class="space-y-6">
                    <!-- Microphone -->
                    <div class="space-y-3">
                        <label class="text-sm font-medium text-(--text-secondary)">Microphone</label>
                        <select
                            v-model="store.selectedAudioDeviceId"
                            class="w-full bg-(--surface-tertiary) border border-(--border-default) rounded-lg px-3 py-2.5 text-sm text-(--text-primary) focus:outline-none focus:ring-2 focus:ring-(--focus-ring)"
                        >
                            <option v-for="device in audioInputs" :key="device.deviceId" :value="device.deviceId">
                                {{ device.label || `Microphone ${audioInputs.indexOf(device) + 1}` }}
                            </option>
                        </select>

                        <!-- Mic Visualizer -->
                        <div class="bg-(--surface-tertiary) rounded-lg p-3">
                            <div class="flex justify-between text-xs text-(--text-secondary) mb-1">
                                <span>Input Level</span>
                                <span>{{ Math.round(volumeLevel) }}%</span>
                            </div>
                            <div class="h-2 bg-(--surface-elevated) rounded-full overflow-hidden">
                                <div 
                                    class="h-full bg-green-500 transition-all duration-100 ease-out"
                                    :style="{ width: `${volumeLevel}%` }"
                                ></div>
                            </div>
                        </div>
                    </div>

                    <!-- Speakers (Chrome only) -->
                    <div>
                        <h3 class="text-sm font-semibold text-(--text-secondary) uppercase tracking-wider mb-4">Speakers</h3>
                         <div class="space-y-4">
                            <!-- Note: Output selection is only supported in Chrome-based browsers -->
                            <select
                                v-if="audioOutputs.length > 0"
                                class="w-full bg-(--surface-tertiary) border border-(--border-default) rounded-lg px-4 py-2.5 text-(--text-primary) focus:ring-2 focus:ring-(--focus-ring) outline-none"
                            >
                                 <option v-for="device in audioOutputs" :key="device.deviceId" :value="device.deviceId">
                                    {{ device.label || `Speaker ${device.deviceId.slice(0, 5)}...` }}
                                </option>
                            </select>
                            <p v-else class="text-sm text-(--text-muted) italic">
                                System default speaker will be used.
                            </p>

                             <!-- Global Volume Slider -->
                            <div class="space-y-2">
                                <label class="text-sm text-(--text-secondary)">Incoming Volume</label>
                                <div class="flex items-center gap-3">
                                    <Icon name="Volume1" size="16" class="text-(--text-tertiary)" />
                                    <input 
                                        type="range" 
                                        min="0" 
                                        max="1" 
                                        step="0.01"
                                        :value="store.globalVolume"
                                        @input="(e) => store.setGlobalVolume(parseFloat((e.target as HTMLInputElement).value))"
                                        class="flex-1 h-2 bg-(--surface-tertiary) rounded-lg appearance-none cursor-pointer [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:w-4 [&::-webkit-slider-thumb]:h-4 [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-white"
                                    />
                                    <Icon name="Volume2" size="16" class="text-(--text-primary)" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Video Settings -->
                <div v-if="activeTab === 'video'" class="space-y-6">
                    <div class="space-y-3">
                        <label class="text-sm font-medium text-(--text-primary)">Camera</label>
                        <select
                            v-model="store.selectedVideoDeviceId"
                            class="w-full bg-(--surface-tertiary) border border-(--border-default) rounded-lg px-3 py-2.5 text-sm text-(--text-primary) focus:outline-none focus:ring-2 focus:ring-(--focus-ring)"
                        >
                            <option v-for="device in videoInputs" :key="device.deviceId" :value="device.deviceId">
                                {{ device.label || `Camera ${videoInputs.indexOf(device) + 1}` }}
                            </option>
                        </select>
                        
                        <!-- Camera Preview Placeholder -->
                        <div class="aspect-video bg-black rounded-xl border border-(--border-default) flex items-center justify-center relative overflow-hidden group">
                            <p class="text-(--text-muted) group-hover:opacity-0 transition-opacity">Camera Preview</p>
                            <!-- In a real implementation, you'd mount a video element here with the selected stream -->
                        </div>
                    </div>
                </div>

            </div>
        </div>
        
        <template #footer>
            <div class="flex justify-end">
                <button 
                    class="px-4 py-2 bg-(--surface-tertiary) hover:bg-(--surface-hover) text-(--text-primary) rounded-lg text-sm font-medium transition-colors"
                    @click="$emit('close')"
                >
                    Close
                </button>
            </div>
        </template>
    </Modal>
</template>
