type CallMode = "mesh" | "sfu";

interface ParticipantLike {
    publicId: string;
    isSelf?: boolean;
}

interface ParticipantMids {
    audioMid?: string;
    audioMid?: string;
    videoMid?: string;
}

interface RemoteMediaState {
    muted: boolean;
    cameraOff: boolean;
}

export interface CallSfuSyncManagerOptions {
    getCallMode: () => CallMode;
    getHasJoined: () => boolean;
    getIsTransportReady: () => boolean;
    getParticipants: () => ParticipantLike[];
    getRemoteSessionId: (participantId: string) => string | undefined;
    getParticipantMids: (participantId: string) => ParticipantMids | undefined;
    getRemoteMainStream: (participantId: string) => MediaStream | undefined;
    requestRemoteMediaInfo: (participantId: string, force?: boolean) => void;
    pullParticipantTracks: (
        participantId: string,
        remoteSessionId?: string,
        audioMid?: string,
        videoMid?: string,
        videoMid?: string,
    ) => void | Promise<void>;
    getRemoteMediaState: (participantId: string) => RemoteMediaState | undefined;
    setRemoteMediaState: (
        participantId: string,
        state: RemoteMediaState,
    ) => void;
    upsertMainStream: (participantId: string, stream: MediaStream) => void;
    removeMainStream: (participantId: string) => void;
    stopAudioAnalysis: (participantId: string) => void;
    intervalMs?: number;
    log?: (message: string) => void;
}

export class CallSfuSyncManager {
    private healthCheckInterval: ReturnType<typeof setInterval> | null = null;

    constructor(private readonly options: CallSfuSyncManagerOptions) {}

    startHealthCheck(): void {
        if (this.healthCheckInterval || this.options.getCallMode() !== "sfu") {
            return;
        }

        this.options.log?.("Starting call health check worker");
        const intervalMs = this.options.intervalMs ?? 15000;
        this.healthCheckInterval = setInterval(
            () => this.runHealthCheckTick(),
            intervalMs,
        );
    }

    stopHealthCheck(): void {
        if (!this.healthCheckInterval) return;
        clearInterval(this.healthCheckInterval);
        this.healthCheckInterval = null;
    }

    handleRemoteTrackInactive(
        participantId: string,
        track: MediaStreamTrack,
    ): void {
        const streamKey = participantId.toLowerCase();

        const currentState = this.options.getRemoteMediaState(streamKey) || {
            muted: false,
            cameraOff: false,
        };
        if (track.kind === "audio") {
            this.options.setRemoteMediaState(streamKey, {
                ...currentState,
                muted: true,
            });
        }
        if (track.kind === "video") {
            this.options.setRemoteMediaState(streamKey, {
                ...currentState,
                cameraOff: true,
            });
        }

        // `onmute` can be transient (network jitter/track starvation) and must not
        // hard-remove streams, otherwise we trigger unnecessary repulls/transceiver churn.
        if (track.readyState !== "ended") {
            return;
        }

        const mainStream = this.options.getRemoteMainStream(streamKey);
        const removed = this.pruneRemoteTrackFromStream(mainStream, track);
        if (!removed || !mainStream) return;

        if (mainStream.getAudioTracks().length === 0) {
            this.options.stopAudioAnalysis(streamKey);
        }
        if (mainStream.getTracks().length === 0) {
            this.options.removeMainStream(streamKey);
        } else {
            // Re-set map entry to ensure UI observes track-level mutation.
            this.options.upsertMainStream(streamKey, mainStream);
        }
    }

    private runHealthCheckTick(): void {
        if (
            this.options.getCallMode() !== "sfu" ||
            !this.options.getHasJoined() ||
            !this.options.getIsTransportReady()
        ) {
            return;
        }

        for (const participant of this.options.getParticipants()) {
            if (participant.isSelf) continue;

            const participantId = participant.publicId.toLowerCase();
            const sessionId = this.options.getRemoteSessionId(participantId);
            const mids = this.options.getParticipantMids(participantId);
            const remoteState = this.options.getRemoteMediaState(participantId);

            if (!sessionId) {
                this.options.requestRemoteMediaInfo(participantId);
                continue;
            }

            const mainStream = this.options.getRemoteMainStream(participantId);
            const hasLiveAudio = this.hasLiveTrack(mainStream, "audio");
            const hasLiveVideo = this.hasLiveTrack(mainStream, "video");
            const expectsAudio = !remoteState?.muted || !!mids?.audioMid;
            const expectsVideo = !remoteState?.cameraOff || !!mids?.videoMid;
            const streamBroken = this.isRemoteStreamBroken(mainStream);
            const expectedMediaMissing =
                (expectsAudio && !hasLiveAudio) || (expectsVideo && !hasLiveVideo);

            if (streamBroken || expectedMediaMissing) {
                void this.options.pullParticipantTracks(
                    participantId,
                    sessionId,
                    mids?.audioMid,
                    mids?.videoMid,
                );
                if (expectsVideo && !mids?.videoMid) {
                    this.options.requestRemoteMediaInfo(participantId, true);
                }
            }

            if (mids?.screenMid) {
                const screenStream =
                    this.options.getRemoteScreenStream(participantId);
                if (this.isRemoteStreamBroken(screenStream)) {
                    void this.options.pullRemoteScreen(
                        participantId,
                        mids.screenMid,
                        sessionId,
                    );
                }
            }
        }
    }

    private isRemoteStreamBroken(stream?: MediaStream | null): boolean {
        if (!stream) return true;
        const mediaTracks = stream
            .getTracks()
            .filter((track) => track.kind === "audio" || track.kind === "video");
        if (mediaTracks.length === 0) return true;
        return mediaTracks.every((track) => track.readyState === "ended");
    }

    private hasLiveTrack(
        stream: MediaStream | undefined,
        kind: "audio" | "video",
    ): boolean {
        if (!stream) return false;
        return stream
            .getTracks()
            .some((track) => track.kind === kind && track.readyState === "live");
    }

    private pruneRemoteTrackFromStream(
        stream: MediaStream | undefined,
        track: MediaStreamTrack,
    ): boolean {
        if (!stream) return false;
        const staleTrack = stream.getTracks().find((t) => t.id === track.id);
        if (!staleTrack) return false;
        stream.removeTrack(staleTrack);
        return true;
    }
}
