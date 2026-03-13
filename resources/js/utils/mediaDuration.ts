export interface UploadMediaMetadataEntry {
    duration_seconds?: number | null;
}

const AUDIO_EXTENSIONS = /\.(mp3|wav|ogg|oga|aac|m4a|flac|opus|weba)$/i;
const VIDEO_EXTENSIONS = /\.(mp4|webm|mov|m4v|ogv|avi|mkv)$/i;

function inferMediaProbeType(file: File): "audio" | "video" | null {
    const mime = String(file.type || "").toLowerCase();
    const name = String(file.name || "").toLowerCase();

    if (mime.startsWith("audio/") || AUDIO_EXTENSIONS.test(name)) {
        return "audio";
    }

    // Voice clips may come through as video/webm containers.
    if (
        mime.startsWith("video/") &&
        (name.startsWith("voice-") ||
            name.startsWith("audio-") ||
            name.startsWith("recording-"))
    ) {
        return "audio";
    }

    if (mime.startsWith("video/") || VIDEO_EXTENSIONS.test(name)) {
        return "video";
    }

    return null;
}

async function probeFileDurationSeconds(
    file: File,
    timeoutMs = 4500,
): Promise<number | null> {
    if (typeof document === "undefined" || typeof URL === "undefined") {
        return null;
    }

    const probeType = inferMediaProbeType(file);
    if (!probeType) return null;

    const media =
        probeType === "audio"
            ? (document.createElement("audio") as HTMLMediaElement)
            : (document.createElement("video") as HTMLMediaElement);

    media.preload = "metadata";

    const objectUrl = URL.createObjectURL(file);

    return new Promise((resolve) => {
        let settled = false;
        let timerId: ReturnType<typeof setTimeout> | null = null;

        const finish = (value: number | null) => {
            if (settled) return;
            settled = true;

            if (timerId) {
                clearTimeout(timerId);
                timerId = null;
            }

            media.onloadedmetadata = null;
            media.onerror = null;
            media.removeAttribute("src");
            media.load();
            URL.revokeObjectURL(objectUrl);
            resolve(value);
        };

        media.onloadedmetadata = () => {
            const directDuration = Number(media.duration);
            if (Number.isFinite(directDuration) && directDuration > 0) {
                finish(Math.round(directDuration));
                return;
            }

            if (media.seekable && media.seekable.length > 0) {
                const seekableEnd = media.seekable.end(media.seekable.length - 1);
                if (Number.isFinite(seekableEnd) && seekableEnd > 0) {
                    finish(Math.round(seekableEnd));
                    return;
                }
            }

            finish(null);
        };

        media.onerror = () => finish(null);

        timerId = setTimeout(() => finish(null), timeoutMs);
        media.src = objectUrl;
    });
}

export async function buildUploadMediaMetadata(
    files: File[],
): Promise<UploadMediaMetadataEntry[]> {
    const entries = await Promise.all(
        files.map(async (file) => ({
            duration_seconds: await probeFileDurationSeconds(file),
        })),
    );

    return entries;
}
