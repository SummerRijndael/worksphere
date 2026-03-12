import { computed, onUnmounted, ref } from "vue";

type RecorderMode = "toggle" | "hold" | null;

interface AudioClipRecorderOptions {
    maxSeconds?: number;
    onReady: (
        file: File,
        meta: {
            durationSeconds: number;
            mimeType: string;
            rawMimeType: string;
            playbackBlob: Blob;
        },
    ) => void | Promise<void>;
    onError?: (message: string) => void;
}

const MIME_CANDIDATES = [
    "audio/webm;codecs=opus",
    "audio/webm",
    "audio/ogg;codecs=opus",
    "audio/ogg",
    "audio/mp4",
];

const RECORDING_UPLOAD_MIME = "audio/webm";

const RECORDER_AUDIO_BITRATE = 192_000;
const WAVEFORM_BAR_COUNT = 12;
const WAVEFORM_MIN_HEIGHT = 4;
const WAVEFORM_MAX_HEIGHT = 18;

function normalizeRecorderMimeType(rawMimeType: string, audioOnly: boolean): string {
    const raw = (rawMimeType || "").toLowerCase();
    if (audioOnly) {
        return RECORDING_UPLOAD_MIME;
    }
    return raw || RECORDING_UPLOAD_MIME;
}

function canPlaybackMimeType(mime: string): boolean {
    if (typeof document === "undefined") return true;
    const el = document.createElement("audio");
    const base = mime.split(";")[0]?.trim() || mime;
    return Boolean(el.canPlayType(mime) || el.canPlayType(base));
}

function pickSupportedMimeType(): string | null {
    if (typeof MediaRecorder === "undefined") return null;
    let recorderOnlyFallback: string | null = null;
    for (const mime of MIME_CANDIDATES) {
        if (MediaRecorder.isTypeSupported(mime)) {
            if (!recorderOnlyFallback) recorderOnlyFallback = mime;
            if (canPlaybackMimeType(mime)) {
                return mime;
            }
        }
    }

    return recorderOnlyFallback;
}

function extensionForMimeType(mimeType: string): string {
    const lower = mimeType.toLowerCase();
    if (lower.includes("ogg")) return "ogg";
    if (lower.includes("mp4")) return "m4a";
    if (lower.includes("mpeg") || lower.includes("mp3")) return "mp3";
    if (lower.includes("wav")) return "wav";
    return "webm";
}

function buildAudioFilename(extension: string): string {
    const now = new Date();
    const date = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, "0")}${String(now.getDate()).padStart(2, "0")}`;
    const time = `${String(now.getHours()).padStart(2, "0")}${String(now.getMinutes()).padStart(2, "0")}${String(now.getSeconds()).padStart(2, "0")}`;
    return `voice-${date}-${time}.${extension}`;
}

function createWaveBars(value = 6, count = WAVEFORM_BAR_COUNT): number[] {
    return Array.from({ length: count }, () => value);
}

function clampWaveValue(value: number): number {
    return Math.min(1, Math.max(0, value));
}

function waveHeightFromValue(value: number): number {
    const normalized = clampWaveValue(value);
    return Math.round(
        WAVEFORM_MIN_HEIGHT +
            normalized * (WAVEFORM_MAX_HEIGHT - WAVEFORM_MIN_HEIGHT),
    );
}

function buildWaveBarsFromSamples(samples: number[]): number[] {
    if (!samples.length) return createWaveBars();

    const bars: number[] = [];
    for (let i = 0; i < WAVEFORM_BAR_COUNT; i += 1) {
        const start = Math.floor((i * samples.length) / WAVEFORM_BAR_COUNT);
        const end = Math.max(
            start + 1,
            Math.floor(((i + 1) * samples.length) / WAVEFORM_BAR_COUNT),
        );
        let sum = 0;
        let count = 0;

        for (let idx = start; idx < end && idx < samples.length; idx += 1) {
            sum += clampWaveValue(samples[idx] ?? 0);
            count += 1;
        }

        const avg = count > 0 ? sum / count : 0;
        bars.push(waveHeightFromValue(avg));
    }

    return bars;
}

export function useAudioClipRecorder(options: AudioClipRecorderOptions) {
    const maxSeconds = Math.max(1, options.maxSeconds ?? 120);

    const isRecording = ref(false);
    const isBusy = ref(false);
    const isPaused = ref(false);
    const mode = ref<RecorderMode>(null);
    const elapsedSeconds = ref(0);
    const error = ref<string | null>(null);
    const liveWaveformBars = ref<number[]>(createWaveBars());
    const draftWaveformBars = ref<number[]>(createWaveBars());

    const holdStarted = ref(false);
    const suppressNextClick = ref(false);
    let holdStartTimer: number | null = null;

    const isSupported = computed(() => {
        if (typeof window === "undefined") return false;
        return (
            typeof navigator !== "undefined" &&
            !!navigator.mediaDevices &&
            typeof navigator.mediaDevices.getUserMedia === "function" &&
            typeof MediaRecorder !== "undefined"
        );
    });

    const remainingSeconds = computed(() =>
        Math.max(maxSeconds - elapsedSeconds.value, 0),
    );
    const isHoldMode = computed(() => mode.value === "hold");
    const formattedElapsed = computed(() => {
        const mins = Math.floor(elapsedSeconds.value / 60);
        const secs = elapsedSeconds.value % 60;
        return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
    });

    let recorder: MediaRecorder | null = null;
    let stream: MediaStream | null = null;
    let chunks: Blob[] = [];
    let audioOnlyCapture = true;
    let timerId: number | null = null;
    let shouldSendOnStop = false;
    let stopPromise: Promise<File | null> | null = null;
    let stopResolver: ((value: File | null) => void) | null = null;
    let audioContext: AudioContext | null = null;
    let analyserNode: AnalyserNode | null = null;
    let sourceNode: MediaStreamAudioSourceNode | null = null;
    let analyserData: Uint8Array | null = null;
    let waveformRafId: number | null = null;
    let collectedWaveSamples: number[] = [];
    let lastWaveSampleAt = 0;

    function clearTimer() {
        if (timerId !== null) {
            window.clearInterval(timerId);
            timerId = null;
        }
    }

    function clearHoldTimer() {
        if (holdStartTimer !== null) {
            window.clearTimeout(holdStartTimer);
            holdStartTimer = null;
        }
    }

    function stopWaveformMonitoring(resetLiveBars = true) {
        if (waveformRafId !== null) {
            window.cancelAnimationFrame(waveformRafId);
            waveformRafId = null;
        }

        if (sourceNode) {
            try {
                sourceNode.disconnect();
            } catch {
                // noop
            }
            sourceNode = null;
        }
        if (analyserNode) {
            try {
                analyserNode.disconnect();
            } catch {
                // noop
            }
            analyserNode = null;
        }
        analyserData = null;

        if (audioContext) {
            void audioContext.close().catch(() => {});
            audioContext = null;
        }

        if (resetLiveBars) {
            liveWaveformBars.value = [];
        }
    }

    function startWaveformMonitoring(mediaStream: MediaStream) {
        stopWaveformMonitoring(false);
        if (typeof window === "undefined") return;

        const Ctx =
            window.AudioContext ||
            (window as Window & typeof globalThis & { webkitAudioContext?: typeof AudioContext })
                .webkitAudioContext;
        if (!Ctx) return;

        try {
            audioContext = new Ctx();
            analyserNode = audioContext.createAnalyser();
            analyserNode.fftSize = 256;
            analyserNode.smoothingTimeConstant = 0.82;
            sourceNode = audioContext.createMediaStreamSource(mediaStream);
            sourceNode.connect(analyserNode);
            analyserData = new Uint8Array(analyserNode.fftSize);
            lastWaveSampleAt = 0;

            const tick = (timestamp: number) => {
                if (!analyserNode || !analyserData) return;

                analyserNode.getByteTimeDomainData(analyserData as Uint8Array);
                let sumSquares = 0;
                for (let i = 0; i < analyserData.length; i += 1) {
                    const sample = (analyserData[i] - 128) / 128;
                    sumSquares += sample * sample;
                }

                const rms = Math.sqrt(sumSquares / analyserData.length);
                const boosted = clampWaveValue(rms * 4.2);
                const nextBars = liveWaveformBars.value.slice();
                if (nextBars.length < WAVEFORM_BAR_COUNT) {
                    nextBars.push(waveHeightFromValue(boosted));
                } else {
                    nextBars.shift();
                    nextBars.push(waveHeightFromValue(boosted));
                }
                liveWaveformBars.value = nextBars;

                if (timestamp - lastWaveSampleAt >= 80) {
                    collectedWaveSamples.push(boosted);
                    if (collectedWaveSamples.length > 2000) {
                        collectedWaveSamples.shift();
                    }
                    lastWaveSampleAt = timestamp;
                }

                waveformRafId = window.requestAnimationFrame(tick);
            };

            waveformRafId = window.requestAnimationFrame(tick);
        } catch {
            stopWaveformMonitoring(false);
        }
    }

    function releaseStream() {
        if (!stream) return;
        stream.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    function setError(message: string) {
        error.value = message;
        options.onError?.(message);
    }

    async function startRecording(nextMode: Exclude<RecorderMode, null>) {
        if (!isSupported.value) {
            setError("Audio recording is not supported in this browser.");
            return false;
        }
        if (isRecording.value || isBusy.value) return false;

        isBusy.value = true;
        error.value = null;
        elapsedSeconds.value = 0;
        chunks = [];
        collectedWaveSamples = [];
        liveWaveformBars.value = [];
        draftWaveformBars.value = createWaveBars();

        try {
            const highFidelityAudioConstraints: MediaTrackConstraints = {
                channelCount: { ideal: 2 },
                sampleRate: { ideal: 48_000 },
                sampleSize: { ideal: 16 },
                echoCancellation: { ideal: true },
                noiseSuppression: { ideal: true },
                autoGainControl: { ideal: true },
            };

            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    audio: highFidelityAudioConstraints,
                });
            } catch {
                // Fallback for browsers/devices that reject advanced constraints.
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            }
            startWaveformMonitoring(stream);
            audioOnlyCapture = stream.getVideoTracks().length === 0;
            const pickedMimeType = pickSupportedMimeType();
            recorder = pickedMimeType
                ? new MediaRecorder(stream, {
                      mimeType: pickedMimeType,
                      audioBitsPerSecond: RECORDER_AUDIO_BITRATE,
                  })
                : new MediaRecorder(stream, {
                      audioBitsPerSecond: RECORDER_AUDIO_BITRATE,
                  });

            shouldSendOnStop = false;

            stopPromise = new Promise<File | null>((resolve) => {
                stopResolver = resolve;
            });

            recorder.ondataavailable = (event: BlobEvent) => {
                if (event.data && event.data.size > 0) {
                    chunks.push(event.data);
                }
            };

            recorder.onerror = () => {
                setError("Recording failed. Please try again.");
            };
            recorder.onpause = () => {
                isPaused.value = true;
            };
            recorder.onresume = () => {
                isPaused.value = false;
            };
            recorder.onstart = () => {
                // Some browsers delay actual capture start after recorder.start().
                // Clear "preparing" as soon as MediaRecorder confirms start.
                isBusy.value = false;
            };

            recorder.onstop = async () => {
                const localResolver = stopResolver;
                const localShouldSend = shouldSendOnStop;
                const durationSeconds = elapsedSeconds.value;
                const rawMimeType =
                    recorder?.mimeType ||
                    chunks[0]?.type ||
                    pickedMimeType ||
                    "audio/webm";
                const mimeType = normalizeRecorderMimeType(
                    rawMimeType,
                    audioOnlyCapture,
                );

                isRecording.value = false;
                isPaused.value = false;
                mode.value = null;
                isBusy.value = false;
                clearTimer();
                stopWaveformMonitoring();
                releaseStream();
                recorder = null;
                stopResolver = null;
                stopPromise = null;
                holdStarted.value = false;

                if (!localShouldSend) {
                    draftWaveformBars.value = createWaveBars();
                    collectedWaveSamples = [];
                    localResolver?.(null);
                    return;
                }

                draftWaveformBars.value =
                    buildWaveBarsFromSamples(collectedWaveSamples);
                collectedWaveSamples = [];
                const playbackBlob = new Blob(chunks, {
                    type: rawMimeType || mimeType,
                });
                const blob = new Blob(chunks, { type: mimeType });
                chunks = [];

                if (!blob.size) {
                    setError("Recorded clip is empty. Please try again.");
                    localResolver?.(null);
                    return;
                }

                const extension = extensionForMimeType(mimeType);
                const file = new File([blob], buildAudioFilename(extension), {
                    type: mimeType,
                });

                try {
                    await options.onReady(file, {
                        durationSeconds,
                        mimeType,
                        rawMimeType,
                        playbackBlob,
                    });
                } catch (callbackError) {
                    setError(
                        callbackError instanceof Error
                            ? callbackError.message
                            : "Unable to attach recorded audio.",
                    );
                }

                localResolver?.(file);
            };

            recorder.start(250);
            isRecording.value = true;
            isPaused.value = false;
            mode.value = nextMode;
            isBusy.value = false;

            timerId = window.setInterval(() => {
                if (isPaused.value) return;
                elapsedSeconds.value += 1;
                if (elapsedSeconds.value >= maxSeconds) {
                    void stopRecording(true);
                }
            }, 1000);

            return true;
        } catch (startError: any) {
            releaseStream();
            stopWaveformMonitoring();
            recorder = null;
            isRecording.value = false;
            isPaused.value = false;
            mode.value = null;
            isBusy.value = false;

            const code = startError?.name;
            if (code === "NotAllowedError" || code === "SecurityError") {
                setError("Microphone permission was denied.");
            } else if (code === "NotFoundError" || code === "DevicesNotFoundError") {
                setError("No microphone device was found.");
            } else {
                setError("Unable to start audio recording.");
            }

            return false;
        }
    }

    async function stopRecording(sendClip: boolean) {
        if (!recorder || (!isRecording.value && !isBusy.value)) return null;

        shouldSendOnStop = sendClip;
        const pending = stopPromise;

        if (recorder.state === "inactive") {
            isRecording.value = false;
            isPaused.value = false;
            mode.value = null;
            clearTimer();
            stopWaveformMonitoring();
            releaseStream();
            recorder = null;
            isBusy.value = false;
            stopResolver?.(null);
            stopResolver = null;
            stopPromise = null;
            return null;
        }

        try {
            recorder.stop();
        } catch {
            isRecording.value = false;
            isPaused.value = false;
            mode.value = null;
            clearTimer();
            stopWaveformMonitoring();
            releaseStream();
            recorder = null;
            isBusy.value = false;
            stopResolver?.(null);
            stopResolver = null;
            stopPromise = null;
            return null;
        }

        return pending ? await pending : null;
    }

    async function pauseRecording() {
        if (!recorder || !isRecording.value || isPaused.value) return;
        if (recorder.state !== "recording") return;
        try {
            recorder.pause();
            isPaused.value = true;
        } catch {
            // noop
        }
    }

    async function resumeRecording() {
        if (!recorder || !isRecording.value || !isPaused.value) return;
        if (recorder.state !== "paused") return;
        try {
            recorder.resume();
            isPaused.value = false;
        } catch {
            // noop
        }
    }

    async function toggleRecordingPause() {
        if (isPaused.value) {
            await resumeRecording();
        } else {
            await pauseRecording();
        }
    }

    function onRecordButtonPointerDown() {
        if (!isSupported.value || isRecording.value || isBusy.value) return;

        holdStarted.value = false;
        clearHoldTimer();

        holdStartTimer = window.setTimeout(() => {
            holdStarted.value = true;
            suppressNextClick.value = true;
            void startRecording("hold");
        }, 220);
    }

    async function onRecordButtonPointerUp() {
        clearHoldTimer();

        if (holdStarted.value && isRecording.value && mode.value === "hold") {
            holdStarted.value = false;
            await stopRecording(true);
        }
    }

    async function onRecordButtonClick() {
        if (!isSupported.value) {
            setError("Audio recording is not supported in this browser.");
            return;
        }

        if (suppressNextClick.value) {
            suppressNextClick.value = false;
            return;
        }

        if (isRecording.value) {
            if (isPaused.value) {
                await resumeRecording();
                return;
            }
            if (mode.value === "toggle") {
                await stopRecording(true);
            }
            return;
        }

        await startRecording("toggle");
    }

    async function cancelRecording() {
        await stopRecording(false);
    }

    async function stopAndPrepareRecording() {
        await stopRecording(true);
    }

    onUnmounted(() => {
        clearHoldTimer();
        clearTimer();
        stopWaveformMonitoring();
        releaseStream();
    });

    return {
        isSupported,
        isRecording,
        isBusy,
        isPaused,
        isHoldMode,
        elapsedSeconds,
        remainingSeconds,
        formattedElapsed,
        liveWaveformBars,
        draftWaveformBars,
        error,
        onRecordButtonClick,
        onRecordButtonPointerDown,
        onRecordButtonPointerUp,
        cancelRecording,
        stopAndPrepareRecording,
        pauseRecording,
        resumeRecording,
        toggleRecordingPause,
    };
}
