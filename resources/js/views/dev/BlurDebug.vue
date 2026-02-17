<script setup lang="ts">
import { ref, onMounted, onUnmounted, shallowRef, watch } from "vue";
import { useBackgroundBlur } from "@/composables/useBackgroundBlur";

const videoRef = ref<HTMLVideoElement | null>(null);
const canvasRef = ref<HTMLCanvasElement | null>(null);
const blur = useBackgroundBlur();

const isRunning = ref(false);
const showMask = ref(false);
const processingTime = ref(0);
const frameCount = ref(0);
const fps = ref(0);
const currentEffect = ref<'blur' | 'image'>('blur');
const testImageUrl = ref('https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=1000');

let stream: MediaStream | null = null;
let animationFrame: number;
let lastTime = 0;

async function startCamera() {
    try {
        stream = await navigator.mediaDevices.getUserMedia({
             video: { width: 640, height: 480 }
        });
        if (videoRef.value) {
            videoRef.value.srcObject = stream;
        }
    } catch (e) {
        console.error("Camera error", e);
    }
}

async function stopCamera() {
    if (stream) {
        stream.getTracks().forEach(t => t.stop());
        stream = null;
    }
}

async function toggleProcessing() {
    if (isRunning.value) {
        blur.stopProcessing();
        isRunning.value = false;
        if (videoRef.value && stream) {
            videoRef.value.srcObject = stream;
        }
    } else {
        if (!stream) await startCamera();
        const track = stream?.getVideoTracks()[0];
        if (track) {
            isRunning.value = true;
            const processedTrack = await blur.startVideoEffect(
                track, 
                currentEffect.value, 
                currentEffect.value === 'image' ? testImageUrl.value : undefined
            );
            const processedStream = new MediaStream([processedTrack]);
            if (videoRef.value) {
                videoRef.value.srcObject = processedStream;
            }
        }
    }
}

// Stats loop
function updateStats() {
    const now = performance.now();
    frameCount.value++;
    if (now - lastTime >= 1000) {
        fps.value = frameCount.value;
        frameCount.value = 0;
        lastTime = now;
    }
    animationFrame = requestAnimationFrame(updateStats);
}

onMounted(() => {
    startCamera();
    updateStats();
});

onUnmounted(() => {
    stopCamera();
    blur.stopProcessing();
    cancelAnimationFrame(animationFrame);
});
</script>

<template>
    <div class="p-6 space-y-6">
        <h1 class="text-2xl font-bold">Background Blur Debugger</h1>
        
        <div class="grid grid-cols-2 gap-4">
            <div class="space-y-2">
                <h3 class="font-semibold">Preview</h3>
                <div class="relative bg-black rounded-lg overflow-hidden aspect-video border border-gray-700">
                    <video 
                        ref="videoRef" 
                        autoplay 
                        playsinline 
                        muted 
                        class="w-full h-full object-cover"
                    ></video>
                    <div class="absolute top-2 left-2 bg-black/60 text-white text-xs px-2 py-1 rounded">
                        FPS: {{ fps }}
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="p-4 bg-gray-100 dark:bg-gray-800 rounded-lg space-y-4">
                    <h3 class="font-semibold">Controls</h3>
                    
                    <div class="flex flex-col gap-2">
                        <select 
                            v-model="currentEffect"
                            class="px-3 py-2 rounded-lg bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-sm"
                        >
                            <option value="blur">Blur Background</option>
                            <option value="image">Virtual Image</option>
                        </select>

                        <button 
                            @click="toggleProcessing"
                            class="px-4 py-2 rounded-lg font-medium transition-colors"
                            :class="isRunning ? 'bg-red-500 text-white hover:bg-red-600' : 'bg-blue-500 text-white hover:bg-blue-600'"
                        >
                            {{ isRunning ? 'Stop Effect' : 'Start Effect' }}
                        </button>
                    </div>

                    <div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                        <div class="flex justify-between">
                            <span>Model Loaded:</span>
                            <span :class="blur.isLoaded.value ? 'text-green-500' : 'text-gray-400'">
                                {{ blur.isLoaded.value ? 'Yes' : 'No' }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span>Is Loading:</span>
                            <span>{{ blur.isLoading.value ? 'Yes' : 'No' }}</span>
                        </div>
                         <div v-if="blur.error.value" class="text-red-500">
                            Error: {{ blur.error.value }}
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg text-sm">
                    <p>
                        <strong>Note:</strong> This tool forces the blur effect on a local stream.
                        It uses the same `useBackgroundBlur` composable as the main app.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
