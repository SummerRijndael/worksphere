type CallType = "audio" | "video";
type VideoEffect = "none" | "blur" | "image";

export interface AcquireLocalMediaOptions {
    callType: CallType;
    defaultCameraOff: boolean;
    defaultMicOff: boolean;
    videoEffect: VideoEffect;
    selectedVideoDeviceId?: string | null;
    backgroundImage?: string | null;
    startVideoEffect: (
        track: MediaStreamTrack,
        effect: Exclude<VideoEffect, "none">,
        backgroundImage?: string,
    ) => Promise<MediaStreamTrack>;
}

export interface AcquireLocalMediaResult {
    stream: MediaStream | null;
    originalVideoTrack: MediaStreamTrack | null;
    videoFallback: boolean;
    errorMessage?: string;
}

export interface AcquireCameraTrackResult {
    track: MediaStreamTrack | null;
    originalTrack: MediaStreamTrack | null;
    errorMessage?: string;
}

export interface AcquireMicrophoneTrackResult {
    track: MediaStreamTrack | null;
    errorMessage?: string;
}

function videoConstraints(selectedVideoDeviceId?: string | null) {
    return {
        deviceId: selectedVideoDeviceId ? { exact: selectedVideoDeviceId } : undefined,
        width: { ideal: 1280, max: 1280 },
        height: { ideal: 720, max: 720 },
        facingMode: "user",
    };
}

export async function acquireCameraTrack(
    options: Pick<
        AcquireLocalMediaOptions,
        "videoEffect" | "backgroundImage" | "startVideoEffect" | "selectedVideoDeviceId"
    >,
): Promise<AcquireCameraTrackResult> {
    const tryAcquire = async () =>
        navigator.mediaDevices.getUserMedia({
            audio: false,
            video: videoConstraints(options.selectedVideoDeviceId),
        });

    try {
        let cameraStream = await tryAcquire();

        const originalVideoTrack = cameraStream.getVideoTracks()[0] || null;
        if (!originalVideoTrack) {
            return {
                track: null,
                originalTrack: null,
                errorMessage: "Could not access camera hardware.",
            };
        }

        let finalTrack: MediaStreamTrack = originalVideoTrack;
        if (
            options.videoEffect === "blur" ||
            options.videoEffect === "image"
        ) {
            finalTrack = await options.startVideoEffect(
                originalVideoTrack,
                options.videoEffect,
                options.backgroundImage || undefined,
            );
        }

        return {
            track: finalTrack,
            originalTrack: originalVideoTrack,
        };
    } catch (error: any) {
        // Single-webcam handoff can transiently throw NotReadableError while device is being released.
        if (error?.name === "NotReadableError" || error?.name === "AbortError") {
            try {
                await new Promise((resolve) => setTimeout(resolve, 650));
                const retriedStream = await tryAcquire();
                const retriedTrack = retriedStream.getVideoTracks()[0] || null;
                if (retriedTrack) {
                    let finalTrack: MediaStreamTrack = retriedTrack;
                    if (
                        options.videoEffect === "blur" ||
                        options.videoEffect === "image"
                    ) {
                        finalTrack = await options.startVideoEffect(
                            retriedTrack,
                            options.videoEffect,
                            options.backgroundImage || undefined,
                        );
                    }
                    return {
                        track: finalTrack,
                        originalTrack: retriedTrack,
                    };
                }
            } catch {
                // Fall through to unified error handling below.
            }
        }

        console.warn("[Call] Failed to acquire camera track", error);
        return {
            track: null,
            originalTrack: null,
            errorMessage:
                error?.name === "NotReadableError"
                    ? "Camera is busy on another app/tab. Turn it off there and try again."
                    : "Could not access camera hardware.",
        };
    }
}

export async function acquireMicrophoneTrack(): Promise<AcquireMicrophoneTrackResult> {
    const tryAcquire = async () =>
        navigator.mediaDevices.getUserMedia({
            audio: true,
            video: false,
        });

    try {
        const micStream = await tryAcquire();
        const micTrack = micStream.getAudioTracks()[0] || null;
        if (!micTrack) {
            return {
                track: null,
                errorMessage: "Could not access microphone hardware.",
            };
        }

        return {
            track: micTrack,
        };
    } catch (error: any) {
        if (error?.name === "NotReadableError" || error?.name === "AbortError") {
            try {
                await new Promise((resolve) => setTimeout(resolve, 450));
                const retriedStream = await tryAcquire();
                const retriedTrack = retriedStream.getAudioTracks()[0] || null;
                if (retriedTrack) {
                    return {
                        track: retriedTrack,
                    };
                }
            } catch {
                // Fall through to unified handling below.
            }
        }

        return {
            track: null,
            errorMessage:
                error?.name === "NotAllowedError" || error?.name === "SecurityError"
                    ? "Microphone access is blocked. Allow microphone permission and try again."
                    : error?.name === "NotFoundError"
                      ? "No microphone device found. Connect a microphone and try again."
                      : error?.name === "NotReadableError"
                        ? "Microphone is busy on another app/tab. Turn it off there and try again."
                        : "Could not access microphone hardware.",
        };
    }
}

export async function acquireLocalMedia(
    options: AcquireLocalMediaOptions,
): Promise<AcquireLocalMediaResult> {
    console.log("[Call] acquireMedia, type:", options.callType);

    try {
        if (options.callType === "video" && !options.defaultCameraOff) {
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                const hasCamera = devices.some(
                    (device) => device.kind === "videoinput",
                );

                if (hasCamera) {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        audio: true,
                        video: videoConstraints(options.selectedVideoDeviceId),
                    });

                    const originalVideoTrack = stream.getVideoTracks()[0] || null;
                    if (
                        originalVideoTrack &&
                        (options.videoEffect === "blur" ||
                            options.videoEffect === "image")
                    ) {
                        const processedTrack = await options.startVideoEffect(
                            originalVideoTrack,
                            options.videoEffect,
                            options.backgroundImage || undefined,
                        );
                        stream.removeTrack(originalVideoTrack);
                        stream.addTrack(processedTrack);
                    }

                    if (options.defaultCameraOff) {
                        stream
                            .getVideoTracks()
                            .forEach((track) => (track.enabled = false));
                    }
                    if (options.defaultMicOff) {
                        stream
                            .getAudioTracks()
                            .forEach((track) => (track.enabled = false));
                    }

                    console.log("[Call] Local media acquired:", {
                        audio: stream.getAudioTracks().length > 0 ? "YES" : "NO",
                        video: stream.getVideoTracks().length > 0 ? "YES" : "NO",
                    });

                    return {
                        stream,
                        originalVideoTrack,
                        videoFallback: false,
                    };
                }

                console.warn("[Call] No camera found on this device.");
            } catch (error) {
                console.warn(
                    "[Call] Camera access error or unavailable, fallback to audio",
                    error,
                );
            }
        } else if (options.callType === "video" && options.defaultCameraOff) {
            console.log(
                "[Call] Camera starts OFF by policy. Acquiring audio-only stream.",
            );
        }

        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: false,
        });

        if (options.defaultMicOff) {
            stream
                .getAudioTracks()
                .forEach((track) => (track.enabled = false));
        }

        console.log("[Call] Local media acquired:", {
            audio: stream.getAudioTracks().length > 0 ? "YES" : "NO",
            video: "NO (Audio-only mode)",
        });

        return {
            stream,
            originalVideoTrack: null,
            videoFallback: true,
        };
    } catch (error) {
        let silentStream = new MediaStream();
        try {
            const audioCtx = new (window.AudioContext || (window as any).webkitAudioContext)();
            const dest = audioCtx.createMediaStreamDestination();
            silentStream = dest.stream;
            silentStream.getAudioTracks().forEach(t => t.enabled = false);
        } catch (e) {
            console.warn("[Call] Could not create silent audio fallback via AudioContext:", e);
        }

        return {
            stream: silentStream,
            originalVideoTrack: null,
            videoFallback: true,
            errorMessage: "Microphone access denied or unreadable. You joined without a microphone.",
        };
    }
}

export async function applyOutputDevice(
    deviceId: string | null,
    ringtoneAudio: HTMLAudioElement | null,
): Promise<void> {
    if (!deviceId) return;
    console.log("[Call] Applying output device:", deviceId);

    const elements = document.querySelectorAll("video, audio");
    for (const element of Array.from(elements)) {
        try {
            if ((element as any).setSinkId) {
                await (element as any).setSinkId(deviceId);
            }
        } catch (error) {
            console.warn("[Call] Failed to setSinkId on element", error);
        }
    }

    if (ringtoneAudio && (ringtoneAudio as any).setSinkId) {
        try {
            await (ringtoneAudio as any).setSinkId(deviceId);
        } catch (error) {
            console.warn("[Call] Failed to setSinkId on ringtoneAudio", error);
        }
    }
}
