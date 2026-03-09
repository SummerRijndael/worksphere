type CallType = "audio" | "video";
type VideoEffect = "none" | "blur" | "image";

export interface AcquireLocalMediaOptions {
    callType: CallType;
    defaultCameraOff: boolean;
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

function videoConstraints(selectedVideoDeviceId?: string | null) {
    return {
        deviceId: selectedVideoDeviceId || undefined,
        width: { ideal: 1280 },
        height: { ideal: 720 },
        facingMode: "user",
    };
}

export async function acquireCameraTrack(
    options: Pick<
        AcquireLocalMediaOptions,
        "videoEffect" | "backgroundImage" | "startVideoEffect" | "selectedVideoDeviceId"
    >,
): Promise<AcquireCameraTrackResult> {
    try {
        const cameraStream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: videoConstraints(options.selectedVideoDeviceId),
        });

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
    } catch (error) {
        console.warn("[Call] Failed to acquire camera track", error);
        return {
            track: null,
            originalTrack: null,
            errorMessage: "Could not access camera hardware.",
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
        console.error("[Call] Media acquisition failed:", error);
        return {
            stream: null,
            originalVideoTrack: null,
            videoFallback: true,
            errorMessage: "Microphone access denied.",
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
