export type RemotePublicationEntry = {
    sessionId: string | null;
    audioMid: string | null;
    videoMid: string | null;
    screenMid: string | null;
    mediaStateVersion: number;
    lastUpdatedAt: number | null;
    lastInfoRequestAt: number | null;
    lastPullAttemptAt: number | null;
    lastPullSuccessAt: number | null;
    lastPullRequestedFingerprint: string | null;
    lastPullSucceededFingerprint: string | null;
};

export type RemoteMediaStateUpdate = {
    sessionId: string;
    audioMid?: string | null;
    videoMid?: string | null;
    screenMid?: string | null;
    mediaStateVersion?: number;
};

export type RemoteMediaStateApplyResult = {
    status: 'applied' | 'stale';
    participantId: string;
    sessionId: string | null;
    audioMid: string | null;
    videoMid: string | null;
    screenMid: string | null;
    shouldPull: boolean;
    sessionChanged: boolean;
    changedKinds: {
        audio: boolean;
        video: boolean;
        screen: boolean;
    };
    explicitClears: {
        audio: boolean;
        video: boolean;
        screen: boolean;
    };
};

export type RemoteMediaStateReduction = {
    status: 'applied' | 'stale';
    participantId: string;
    previousFingerprint: string;
    nextFingerprint: string;
    nextEntry: RemotePublicationEntry;
    sessionChanged: boolean;
    fingerprintChanged: boolean;
    hasPullableTracks: boolean;
    shouldPull: boolean;
    changedKinds: {
        audio: boolean;
        video: boolean;
        screen: boolean;
    };
    explicitClears: {
        audio: boolean;
        video: boolean;
        screen: boolean;
    };
};

export function createEmptyRemotePublication(): RemotePublicationEntry {
    return {
        sessionId: null,
        audioMid: null,
        videoMid: null,
        screenMid: null,
        mediaStateVersion: 0,
        lastUpdatedAt: null,
        lastInfoRequestAt: null,
        lastPullAttemptAt: null,
        lastPullSuccessAt: null,
        lastPullRequestedFingerprint: null,
        lastPullSucceededFingerprint: null,
    };
}

export function buildRemotePublicationFingerprint(
    sessionId: string | null | undefined,
    audioMid?: string | null,
    videoMid?: string | null,
    screenMid?: string | null
) {
    return [
        sessionId || '',
        audioMid || '',
        videoMid || '',
        screenMid || '',
    ].join('|');
}

export function hasPullableMainMedia(entry: RemotePublicationEntry | null | undefined) {
    return !!entry && !!(entry.audioMid || entry.videoMid);
}

export function hasPullableScreenMedia(entry: RemotePublicationEntry | null | undefined) {
    return !!entry && !!entry.screenMid;
}

export function reduceRemotePublicationState(
    existingEntry: RemotePublicationEntry | null | undefined,
    participantPublicId: string,
    update: RemoteMediaStateUpdate,
    now = Date.now()
): RemoteMediaStateReduction {
    const participantId = participantPublicId.toLowerCase();
    const existing = existingEntry || createEmptyRemotePublication();
    const incomingVersion = Number(update.mediaStateVersion || 0);
    const previousFingerprint = buildRemotePublicationFingerprint(
        existing.sessionId,
        existing.audioMid,
        existing.videoMid,
        existing.screenMid
    );

    if (incomingVersion > 0 && incomingVersion <= existing.mediaStateVersion) {
        return {
            status: 'stale',
            participantId,
            previousFingerprint,
            nextFingerprint: previousFingerprint,
            nextEntry: existing,
            sessionChanged: false,
            fingerprintChanged: false,
            hasPullableTracks: !!existing.sessionId && !!(existing.audioMid || existing.videoMid || existing.screenMid),
            shouldPull: false,
            changedKinds: {
                audio: false,
                video: false,
                screen: false,
            },
            explicitClears: {
                audio: false,
                video: false,
                screen: false,
            },
        };
    }

    const sessionChanged = !!existing.sessionId && existing.sessionId !== update.sessionId;
    const nextEntry: RemotePublicationEntry = {
        ...existing,
        sessionId: update.sessionId,
        lastUpdatedAt: now,
    };

    if (sessionChanged) {
        nextEntry.audioMid = null;
        nextEntry.videoMid = null;
        nextEntry.screenMid = null;
        nextEntry.lastPullRequestedFingerprint = null;
        nextEntry.lastPullSucceededFingerprint = null;
    }

    if (incomingVersion > 0) {
        nextEntry.mediaStateVersion = incomingVersion;
    }
    if (update.audioMid !== undefined) nextEntry.audioMid = update.audioMid;
    if (update.videoMid !== undefined) nextEntry.videoMid = update.videoMid;
    if (update.screenMid !== undefined) nextEntry.screenMid = update.screenMid;

    const nextFingerprint = buildRemotePublicationFingerprint(
        nextEntry.sessionId,
        nextEntry.audioMid,
        nextEntry.videoMid,
        nextEntry.screenMid
    );
    const hasPullableTracks = !!nextEntry.sessionId && !!(nextEntry.audioMid || nextEntry.videoMid || nextEntry.screenMid);
    const fingerprintChanged = nextFingerprint !== previousFingerprint;
    const changedKinds = {
        audio: sessionChanged || existing.audioMid !== nextEntry.audioMid,
        video: sessionChanged || existing.videoMid !== nextEntry.videoMid,
        screen: sessionChanged || existing.screenMid !== nextEntry.screenMid,
    };

    return {
        status: 'applied',
        participantId,
        previousFingerprint,
        nextFingerprint,
        nextEntry,
        sessionChanged,
        fingerprintChanged,
        hasPullableTracks,
        shouldPull: hasPullableTracks && (sessionChanged || fingerprintChanged),
        changedKinds,
        explicitClears: {
            audio: update.audioMid !== undefined && !nextEntry.audioMid,
            video: update.videoMid !== undefined && !nextEntry.videoMid,
            screen: update.screenMid !== undefined && !nextEntry.screenMid,
        },
    };
}
