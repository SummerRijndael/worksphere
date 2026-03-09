export interface SfuTrackAssociation {
    participantId: string;
    trackName: string;
}

export interface PendingSfuTrackEvent {
    track: MediaStreamTrack;
    mid: string;
    transceiver: RTCRtpTransceiver;
    streams: readonly MediaStream[];
}

export interface FlushPendingTracksOptions {
    resolveParticipantId: (event: PendingSfuTrackEvent) => string | null;
    onResolved: (event: PendingSfuTrackEvent, participantId: string) => void;
    onStats?: (stats: {
        processed: number;
        resolved: number;
        unresolved: number;
    }) => void;
}

export class CallSfuSessionManager {
    private generation = 0;
    private lastResetAt = 0;
    private negotiationQueue: Promise<void> = Promise.resolve();
    private readonly participantPullAttempts = new Map<string, number>();
    private readonly screenPullAttempts = new Map<string, number>();
    private readonly midToParticipantMap = new Map<string, string>();
    private readonly pendingTrackEvents: PendingSfuTrackEvent[] = [];
    private readonly transceiverMap = new WeakMap<
        RTCRtpTransceiver,
        SfuTrackAssociation
    >();
    private iceRestartTimer: ReturnType<typeof setTimeout> | null = null;
    private wasConnected = false;

    runInQueue(
        task: () => Promise<void>,
        onSkipped?: (queuedGeneration: number, currentGeneration: number) => void,
    ): Promise<void> {
        const queuedGeneration = this.generation;

        this.negotiationQueue = this.negotiationQueue.then(async () => {
            if (queuedGeneration !== this.generation) {
                onSkipped?.(queuedGeneration, this.generation);
                return;
            }

            const jitterMs = Math.floor(Math.random() * 200);
            await new Promise((resolve) => setTimeout(resolve, 200 + jitterMs));
            await task();
        });

        return this.negotiationQueue;
    }

    bumpGeneration(): number {
        this.generation += 1;
        return this.generation;
    }

    tryEnterResetWindow(minIntervalMs = 5000): boolean {
        const now = Date.now();
        if (now - this.lastResetAt < minIntervalMs) {
            return false;
        }
        this.lastResetAt = now;
        return true;
    }

    nextParticipantPullAttempt(participantId: string): number {
        const count = (this.participantPullAttempts.get(participantId) || 0) + 1;
        this.participantPullAttempts.set(participantId, count);
        return count;
    }

    clearParticipantPullAttempt(participantId: string): void {
        this.participantPullAttempts.delete(participantId);
    }

    nextScreenPullAttempt(participantId: string): number {
        const count = (this.screenPullAttempts.get(participantId) || 0) + 1;
        this.screenPullAttempts.set(participantId, count);
        return count;
    }

    clearScreenPullAttempt(participantId: string): void {
        this.screenPullAttempts.delete(participantId);
    }

    mapMid(mid: string, participantId: string): void {
        this.midToParticipantMap.set(mid, participantId);
    }

    getParticipantByMid(mid: string): string | undefined {
        return this.midToParticipantMap.get(mid);
    }

    setTransceiverAssociation(
        transceiver: RTCRtpTransceiver,
        association: SfuTrackAssociation,
    ): void {
        this.transceiverMap.set(transceiver, association);
    }

    getTransceiverAssociation(
        transceiver: RTCRtpTransceiver,
    ): SfuTrackAssociation | undefined {
        return this.transceiverMap.get(transceiver);
    }

    queuePendingTrackEvent(event: PendingSfuTrackEvent): void {
        this.pendingTrackEvents.push(event);
    }

    flushPendingTracks(options: FlushPendingTracksOptions): void {
        if (this.pendingTrackEvents.length === 0) return;

        let resolved = 0;
        const unresolved: PendingSfuTrackEvent[] = [];
        const total = this.pendingTrackEvents.length;

        for (const event of this.pendingTrackEvents) {
            const participantId = options.resolveParticipantId(event);
            if (!participantId) {
                unresolved.push(event);
                continue;
            }

            resolved += 1;
            options.onResolved(event, participantId);
        }

        this.pendingTrackEvents.length = 0;
        unresolved.forEach((event) => this.pendingTrackEvents.push(event));
        options.onStats?.({
            processed: total,
            resolved,
            unresolved: unresolved.length,
        });
    }

    handleIceConnectionState(
        state: RTCIceConnectionState | undefined,
        callbacks: {
            onDisconnectedStable: () => void;
            onFailed: () => void;
            delayMs?: number;
        },
    ): void {
        if (!state) return;

        if (state === "connected" || state === "completed") {
            this.wasConnected = true;
            if (this.iceRestartTimer) {
                clearTimeout(this.iceRestartTimer);
                this.iceRestartTimer = null;
            }
            return;
        }

        if (state === "disconnected" && this.wasConnected) {
            if (this.iceRestartTimer) {
                clearTimeout(this.iceRestartTimer);
            }
            const delayMs = callbacks.delayMs ?? 5000;
            this.iceRestartTimer = setTimeout(() => {
                callbacks.onDisconnectedStable();
            }, delayMs);
            return;
        }

        if (state === "failed") {
            callbacks.onFailed();
        }
    }

    clearRuntimeState(): void {
        this.participantPullAttempts.clear();
        this.screenPullAttempts.clear();
        this.midToParticipantMap.clear();
        this.pendingTrackEvents.length = 0;
        this.wasConnected = false;
        if (this.iceRestartTimer) {
            clearTimeout(this.iceRestartTimer);
            this.iceRestartTimer = null;
        }
    }

    cleanup(): void {
        this.clearRuntimeState();
        this.negotiationQueue = Promise.resolve();
    }
}
