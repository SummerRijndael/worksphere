import { ref, type Ref } from 'vue';
import { createLogger } from './logger';
import { meetingService } from '@/services/meeting.service';
import type { Meeting, MeetingParticipant } from '@/types/models';
import {
    buildRemotePublicationFingerprint,
    createEmptyRemotePublication,
    hasPullableMainMedia,
    hasPullableScreenMedia,
    reduceRemotePublicationState,
    type RemoteMediaStateApplyResult,
    type RemotePublicationEntry,
} from './legacySfuPublicationState';

const log = createLogger('SFU');

type LocalPublicationKind = 'audio' | 'video' | 'screen';
type LocalPublicationStatus = 'idle' | 'publishing' | 'published' | 'unpublishing' | 'failed';

type LocalPublicationEntry = {
    desiredTrackId: string | null;
    confirmedTrackId: string | null;
    mid: string | null;
    state: LocalPublicationStatus;
    revision: number;
    confirmedRevision: number;
    lastError: string | null;
};

type PullParticipantTracksOptions = {
    forceApiPull?: boolean;
    reason?: string;
    pullKinds?: {
        audio?: boolean;
        video?: boolean;
        screen?: boolean;
    };
};

function getClientInstanceId(): string {
    const w = window as any;
    if (!w.__wsClientInstanceId) {
        w.__wsClientInstanceId = `wsi-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
    }
    return w.__wsClientInstanceId;
}

export function createStreamManager(
    meetingRef: Ref<Meeting | null>,
    localParticipantRef: Ref<MeetingParticipant | null>,
    iceServersRef: Ref<any[]>,
    currentRoomIdRef: Ref<string | null>,
    emitTalkingState: (participantId: string, isTalking: boolean) => void,
    onSfuMediaReady: (audioMid?: string, videoMid?: string, screenMid?: string) => void,
    onSFUError: (err: any) => void
) {
    const clientInstanceId = getClientInstanceId();
    // Media State
    const localStream = ref<MediaStream | null>(null);
    const localScreenStream = ref<MediaStream | null>(null);
    const remoteStreams = ref<Map<string, MediaStream>>(new Map());
    const remoteSfuSessions = new Map<string, string>();
    const remoteSfuTracks = new Map<string, {
        audioMid?: string | null;
        videoMid?: string | null;
        screenMid?: string | null;
    }>();
    const remotePublications = new Map<string, RemotePublicationEntry>();
    let lastBroadcastedLocalMediaFingerprint: string | null = null;
    
    // SFU Connection State
    const sfuIceState = ref("new");
    const sfuConnectionState = ref<string>('new');
    const isInitializingSFU = ref(false);
    let sfuPc: RTCPeerConnection | null = null;
    const sfuSessionId = ref<string | null>(null);
    let sfuGeneration = Date.now();
    let localPublicationRevision = 0;
    const localPublications: Record<LocalPublicationKind, LocalPublicationEntry> = {
        audio: createEmptyLocalPublication(),
        video: createEmptyLocalPublication(),
        screen: createEmptyLocalPublication(),
    };
    
    // SFU API specific maps (ported from CallApp.vue)
    const sfuTransceiverMap = new Map<RTCRtpTransceiver, { participantId: string, trackName: string }>();
    const midToParticipantMap = new Map<string, string>();
    const participantPullAttempts = new Map<string, number>();
    const participantPullGeneration = new Map<string, number>();
    const visibleParticipantIds = ref<Set<string>>(new Set());
    const participantFirstSeen = new Map<string, number>(); // timestamp of first sfu-media-ready
    const MAX_VIDEO_SUBSCRIPTIONS = 9; // Active Grid: cap video pulls to this many participants
    // Persist MIDs per participant to help "Sticky MIDs" logic
    const remoteParticipantMids = new Map<string, { audio?: string; video?: string; screen?: string }>();
    
    // Sticky Slots: Maintain a consistent order of participants to keep MIDs stable across resets
    const participantSlots = ref<string[]>([]);
    
    const pendingTrackEvents: { track: MediaStreamTrack, mid: string, transceiver: RTCRtpTransceiver, streams: readonly MediaStream[] }[] = [];
    // Queue for signals that arrive before SFU session is established
    const pendingPullSignals: { participantPublicId: string, remoteSessionId?: string, audioMid?: string, videoMid?: string, screenMid?: string }[] = [];
    
    // Proactive Track Binding (SDK: createConsumerObjectAndWaitForTrack)
    const trackResolvers = new Map<string | RTCRtpTransceiver, { 
        resolve: (payload: { track: MediaStreamTrack, transceiver: RTCRtpTransceiver }) => void, 
        reject: (err: any) => void,
        timeout: any
    }>();
    
    // Desync Guard (Self-Healing)
    let healthCheckInterval: number | null = null;

    // ── Simulcast Layer Switching (Opt 3) ──
    // Tracks which layer we've requested for each participant's video
    const trackPreferredLayers = new Map<string, string>(); // participantId → "l"|"m"|"h"
    const spotlightedParticipantId = ref<string | null>(null);
    let simulcastDebounceTimer: ReturnType<typeof setTimeout> | null = null;
    const SIMULCAST_DEBOUNCE_MS = 500;
    // Cross-browser stability: avoid simulcast hints during initial pull.
    // We still do layer tuning later via tracks/update when a video MID exists.
    const ENABLE_SIMULCAST_PULL_HINTS = false;
    
    // SFU Queue
    let sfuQueue: Promise<void> = Promise.resolve();
    function runInSFUQueue<T>(fn: () => Promise<T>): Promise<T> {
        const result = sfuQueue.then(async () => {
            try {
                return await fn();
            } catch (err) {
                log('ERROR', 'SFU Queue task failed', err);
                throw err;
            }
        });
        sfuQueue = result.then(() => {}, () => {});
        return result as Promise<T>;
    }

    // Audio Analysis
    const audioAnalysers = new Map<string, {
        context: AudioContext,
        source: MediaStreamAudioSourceNode,
        analyser: AnalyserNode,
        interval: number
    }>();

    function createEmptyLocalPublication(): LocalPublicationEntry {
        return {
            desiredTrackId: null,
            confirmedTrackId: null,
            mid: null,
            state: 'idle',
            revision: 0,
            confirmedRevision: 0,
            lastError: null,
        };
    }

    function resetLocalPublications() {
        localPublicationRevision = 0;
        lastBroadcastedLocalMediaFingerprint = null;
        (Object.keys(localPublications) as LocalPublicationKind[]).forEach((kind) => {
            localPublications[kind] = createEmptyLocalPublication();
        });
    }

    function nextLocalPublicationRevision() {
        localPublicationRevision += 1;
        return localPublicationRevision;
    }

    function isLiveTrack(track: MediaStreamTrack | null | undefined): track is MediaStreamTrack {
        return !!track && track.readyState === 'live';
    }

    function getLocalPublicationErrorMessage(error: any): string {
        if (typeof error?.message === 'string' && error.message) return error.message;
        if (typeof error === 'string' && error) return error;
        return 'unknown_error';
    }

    function getHttpStatus(error: any): number | null {
        const status = error?.response?.status;
        return Number.isFinite(status) ? Number(status) : null;
    }

    function shouldRescueSfuSession(error: any): boolean {
        const status = getHttpStatus(error);
        if (status === null) return false;
        // 406 = negotiation mismatch, 410/404 = session gone, 5xx = backend failed to process session state.
        return status === 406 || status === 410 || status === 404 || status >= 500;
    }

    function markLocalPublicationPending(kind: LocalPublicationKind, track: MediaStreamTrack | null) {
        const entry = localPublications[kind];
        entry.desiredTrackId = track?.id ?? null;
        entry.revision = nextLocalPublicationRevision();
        entry.lastError = null;
        entry.state = track ? 'publishing' : 'unpublishing';
    }

    function confirmLocalPublication(kind: LocalPublicationKind, mid: string | null, track: MediaStreamTrack | null) {
        const entry = localPublications[kind];
        entry.desiredTrackId = track?.id ?? null;
        entry.confirmedTrackId = track?.id ?? null;
        entry.mid = mid ?? null;
        entry.confirmedRevision = entry.revision;
        entry.lastError = null;
        entry.state = track ? 'published' : 'idle';
    }

    function clearLocalPublication(kind: LocalPublicationKind) {
        confirmLocalPublication(kind, null, null);
    }

    function failLocalPublication(kind: LocalPublicationKind, error: any) {
        const entry = localPublications[kind];
        entry.lastError = getLocalPublicationErrorMessage(error);
        entry.state = 'failed';
    }

    function findSelfTransceiver(kind: LocalPublicationKind) {
        if (!sfuPc) return null;
        const matches = sfuPc.getTransceivers().filter((transceiver) =>
            sfuTransceiverMap.get(transceiver)?.participantId === 'self' &&
            sfuTransceiverMap.get(transceiver)?.trackName === kind
        );
        if (matches.length === 0) return null;
        return matches.find((transceiver) => isLiveTrack(transceiver.sender.track))
            || matches.find((transceiver) => !!transceiver.sender.track)
            || matches[matches.length - 1]
            || null;
    }

    function syncLocalPublicationFromTransceiver(kind: LocalPublicationKind) {
        const tc = findSelfTransceiver(kind);
        const track = tc?.sender.track || null;
        if (tc?.mid && isLiveTrack(track)) {
            confirmLocalPublication(kind, tc.mid, track);
            return;
        }
        clearLocalPublication(kind);
    }

    function syncLocalPublicationsFromTransceivers(kinds?: LocalPublicationKind[]) {
        const targetKinds = kinds || (Object.keys(localPublications) as LocalPublicationKind[]);
        targetKinds.forEach((kind) => syncLocalPublicationFromTransceiver(kind));
    }

    function syncRemotePublicationMaps(participantId: string, entry: RemotePublicationEntry) {
        if (entry.sessionId) {
            remoteSfuSessions.set(participantId, entry.sessionId);
        } else {
            remoteSfuSessions.delete(participantId);
        }

        remoteSfuTracks.set(participantId, {
            audioMid: entry.audioMid,
            videoMid: entry.videoMid,
            screenMid: entry.screenMid,
        });
    }

    function getRemotePublication(participantPublicId: string, ensure = false): RemotePublicationEntry | null {
        const participantId = participantPublicId.toLowerCase();
        const existing = remotePublications.get(participantId);
        if (existing || !ensure) return existing || null;
        const created = createEmptyRemotePublication();
        remotePublications.set(participantId, created);
        return created;
    }

    function isMediaStreamBroken(stream: MediaStream | null | undefined, kinds?: Array<'audio' | 'video'>) {
        if (!stream) return true;
        const relevantKinds = kinds && kinds.length > 0 ? new Set(kinds) : null;
        const tracks = stream.getTracks().filter((track) => {
            if (track.kind !== 'audio' && track.kind !== 'video') return false;
            return !relevantKinds || relevantKinds.has(track.kind as 'audio' | 'video');
        });
        if (tracks.length === 0) return true;
        return tracks.every((track) => track.readyState === 'ended');
    }

    function hasLiveStreamTrack(stream: MediaStream | null | undefined, kind: 'audio' | 'video') {
        if (!stream) return false;
        const tracks = kind === 'audio' ? stream.getAudioTracks() : stream.getVideoTracks();
        return tracks.some((track) => track.readyState === 'live');
    }

    function hasSatisfiedPullTargets(
        participantPublicId: string,
        targets: {
            audio: boolean;
            video: boolean;
            screen: boolean;
        }
    ) {
        const participantId = participantPublicId.toLowerCase();
        const mainStream = remoteStreams.value.get(participantId);
        const screenStream = remoteStreams.value.get(`${participantId}:screen`);

        if (targets.audio && !hasLiveStreamTrack(mainStream, 'audio')) return false;
        if (targets.video && !hasLiveStreamTrack(mainStream, 'video')) return false;
        if (targets.screen && !hasLiveStreamTrack(screenStream, 'video')) return false;
        return true;
    }

    function getSatisfiedPullTargetKinds(participantPublicId: string) {
        const participantId = participantPublicId.toLowerCase();
        const mainStream = remoteStreams.value.get(participantId);
        const screenStream = remoteStreams.value.get(`${participantId}:screen`);

        return {
            audio: hasLiveStreamTrack(mainStream, 'audio'),
            video: hasLiveStreamTrack(mainStream, 'video'),
            screen: hasLiveStreamTrack(screenStream, 'video'),
        };
    }

    function applyRemoteMediaState(
        participantPublicId: string,
        update: {
            sessionId: string;
            audioMid?: string | null;
            videoMid?: string | null;
            screenMid?: string | null;
            mediaStateVersion?: number;
        }
    ): RemoteMediaStateApplyResult {
        const participantId = participantPublicId.toLowerCase();
        const reduction = reduceRemotePublicationState(
            remotePublications.get(participantId),
            participantId,
            update
        );

        if (reduction.status === 'stale') {
            return {
                status: 'stale',
                participantId,
                sessionId: reduction.nextEntry.sessionId,
                audioMid: reduction.nextEntry.audioMid,
                videoMid: reduction.nextEntry.videoMid,
                screenMid: reduction.nextEntry.screenMid,
                shouldPull: false,
                sessionChanged: false,
                changedKinds: {
                    audio: false,
                    video: false,
                    screen: false,
                },
                explicitClears: reduction.explicitClears,
            };
        }

        if (reduction.sessionChanged) {
            participantPullAttempts.delete(participantId);
            participantPullGeneration.delete(participantId);
        }

        remotePublications.set(participantId, reduction.nextEntry);
        syncRemotePublicationMaps(participantId, reduction.nextEntry);

        if (reduction.hasPullableTracks && !participantFirstSeen.has(participantId)) {
            participantFirstSeen.set(participantId, reduction.nextEntry.lastUpdatedAt || Date.now());
        }

        return {
            status: 'applied',
            participantId,
            sessionId: reduction.nextEntry.sessionId,
            audioMid: reduction.nextEntry.audioMid,
            videoMid: reduction.nextEntry.videoMid,
            screenMid: reduction.nextEntry.screenMid,
            shouldPull: reduction.shouldPull,
            sessionChanged: reduction.sessionChanged,
            changedKinds: reduction.changedKinds,
            explicitClears: reduction.explicitClears,
        };
    }

    function getConfirmedLocalTrackMids() {
        return {
            audioMid: localPublications.audio.state === 'published' ? localPublications.audio.mid || undefined : undefined,
            videoMid: localPublications.video.state === 'published' ? localPublications.video.mid || undefined : undefined,
            screenMid: localPublications.screen.state === 'published' ? localPublications.screen.mid || undefined : undefined,
        };
    }

    function getSignalableLocalTrackMids() {
        const resolveMid = (kind: LocalPublicationKind) => {
            const entry = localPublications[kind];
            if (entry.state === 'published') {
                return entry.mid || undefined;
            }

            // Preserve the last stable MID while a hot track replacement is still
            // publishing. This prevents remote peers from interpreting a transient
            // sender replaceTrack window as an explicit media-off signal.
            if (entry.state === 'publishing' && entry.mid && entry.confirmedTrackId) {
                return entry.mid;
            }

            return undefined;
        };

        return {
            audioMid: resolveMid('audio'),
            videoMid: resolveMid('video'),
            screenMid: resolveMid('screen'),
        };
    }

    function hasPendingLocalPublication() {
        return (Object.values(localPublications) as LocalPublicationEntry[]).some(
            (entry) => entry.state === 'publishing' || entry.state === 'unpublishing'
        );
    }

    function getActiveLocalTrackObjects(kinds?: LocalPublicationKind[]) {
        if (!sfuPc) return [];
        const targetKinds = kinds ? new Set(kinds) : null;
        return sfuPc.getTransceivers()
            .filter((transceiver) => {
                if (!transceiver.mid) return false;
                const assoc = sfuTransceiverMap.get(transceiver);
                if (!assoc || assoc.participantId !== 'self') return false;
                if (targetKinds && !targetKinds.has(assoc.trackName as LocalPublicationKind)) return false;
                return isLiveTrack(transceiver.sender.track);
            })
            .map((transceiver) => {
                const assoc = sfuTransceiverMap.get(transceiver)!;
                return {
                    location: 'local',
                    mid: transceiver.mid!,
                    trackName: assoc.trackName,
                };
            });
    }

    function mungeSdp(sdp: string): string {
        if (!sdp) return sdp;
        try {
            return sdp.split(/\r?\n/)
                .map(l => l.trim())
                .filter(l => l.length > 0 && !l.includes('a=max-message-size:'))
                .join('\r\n') + '\r\n';
        } catch (e) {
            return sdp;
        }
    }

    function toSdpDescription(sd: any, fallbackType: RTCSdpType = 'answer'): RTCSessionDescriptionInit {
        const type = sd?.type;
        if (type === 'offer' || type === 'answer' || type === 'pranswer' || type === 'rollback') {
            return { type, sdp: sd?.sdp || '' };
        }

        return { type: fallbackType, sdp: sd?.sdp || '' };
    }

    // Cloudflare Calls API often returns sessionDescription without a 'type' field.
    // RTCSessionDescription requires a valid type ('answer') for setRemoteDescription.
    function toSdpAnswer(sd: any): RTCSessionDescriptionInit {
        return toSdpDescription(sd, 'answer');
    }

    function flushPendingTracks() {
        if (pendingTrackEvents.length === 0) return;
        log('SFU', `Flushing ${pendingTrackEvents.length} buffered track event(s)`);

        const stillPending: typeof pendingTrackEvents = [];
        for (const evt of pendingTrackEvents) {
            let participantId = midToParticipantMap.get(evt.mid);

            if (!participantId) {
                const assoc = sfuTransceiverMap.get(evt.transceiver);
                if (assoc) {
                    participantId = assoc.participantId;
                    // If it's a screen track, append ':screen' to the participantId for unique identification
                    if (assoc.trackName === 'screen') participantId += ':screen';
                }
            }

            if (participantId) {
                // Ensure participantId is lowercased for consistent map access
                const normalizedParticipantId = participantId.toLowerCase();
                log('TRACK', `Resolved buffered track ${evt.track.kind} (${evt.mid}) for ${normalizedParticipantId}`);
                let s = evt.streams[0] || new MediaStream([evt.track]);
                
                const handleActive = () => {
                    const pid = normalizedParticipantId;
                    const existingStream = remoteStreams.value.get(pid);
                    if (existingStream) {
                        if (!existingStream.getTracks().find(t => t.id === evt.track.id)) {
                            existingStream.addTrack(evt.track);
                        }
                    } else {
                        // Create a new stream map to trigger reactivity
                        const newMap = new Map(remoteStreams.value);
                        newMap.set(pid, s);
                        remoteStreams.value = newMap;
                        // Only start audio analysis for non-screen audio tracks
                        if (evt.track.kind === 'audio' && !pid.endsWith(':screen')) {
                            startAudioAnalysis(pid, s);
                        }
                    }
                };

                const handleInactive = () => {
                    const pid = normalizedParticipantId;
                    const existingStream = remoteStreams.value.get(pid);
                    if (!existingStream) return;

                    const stale = existingStream.getTracks().find(t => t.id === evt.track.id);
                    if (!stale) return;

                    existingStream.removeTrack(stale);
                    try { stale.stop(); } catch {}

                    if (stale.kind === 'audio' && !pid.endsWith(':screen') && existingStream.getAudioTracks().length === 0) {
                        stopAudioAnalysis(pid);
                    }

                    if (existingStream.getTracks().length === 0) {
                        const newMap = new Map(remoteStreams.value);
                        newMap.delete(pid);
                        remoteStreams.value = newMap;
                    } else {
                        remoteStreams.value = new Map(remoteStreams.value);
                    }
                };
                
                // Attach handler for when the track becomes unmuted (active)
                evt.track.onunmute = handleActive;
                evt.track.onmute = handleInactive;
                evt.track.onended = handleInactive;
                // If the track is already unmuted, call the handler immediately
                if (!evt.track.muted) handleActive();
            } else {
                stillPending.push(evt);
            }
        }
        pendingTrackEvents.splice(0, pendingTrackEvents.length, ...stillPending);
    }

    function startAudioAnalysis(id: string, stream: MediaStream) {
        const idLower = id.toLowerCase();
        if (audioAnalysers.has(idLower)) return;
        if (!stream.getAudioTracks().length) return;

        try {
            const AudioContextClass = (window as any).AudioContext || (window as any).webkitAudioContext;
            const context = new AudioContextClass();
            const source = context.createMediaStreamSource(stream);
            const analyser = context.createAnalyser();
            analyser.fftSize = 256;
            analyser.smoothingTimeConstant = 0.8;
            source.connect(analyser);

            const dataArray = new Uint8Array(analyser.frequencyBinCount);

            const interval = window.setInterval(() => {
                analyser.getByteFrequencyData(dataArray);
                let volume = 0;
                for (let i = 0; i < dataArray.length; i++) {
                    volume += dataArray[i];
                }
                const average = volume / dataArray.length;

                if (average > 15) {
                    emitTalkingState(idLower, true);
                } else {
                    emitTalkingState(idLower, false);
                }
            }, 100);

            audioAnalysers.set(idLower, { context, source, analyser, interval });
        } catch (e) {
            log('ERROR', `Audio analysis failed for ${idLower}`, e);
        }
    }

    function stopAudioAnalysis(id: string) {
        const idLower = id.toLowerCase();
        const entry = audioAnalysers.get(idLower);
        if (entry) {
            window.clearInterval(entry.interval);
            entry.context.close().catch(() => {});
            audioAnalysers.delete(idLower);
        }
        emitTalkingState(idLower, false);
    }

    function broadcastMediaMids(retryCount = 0) {
        if (!sfuPc || !localParticipantRef.value) return;
        const { audioMid, videoMid, screenMid } = getSignalableLocalTrackMids();
        const nextFingerprint = buildRemotePublicationFingerprint(
            sfuSessionId.value,
            audioMid,
            videoMid,
            screenMid
        );
        const hasAnyConfirmedMid = !!(audioMid || videoMid || screenMid);

        // If no confirmed publications are available yet, retry briefly while a publish
        // is still settling with the backend.
        if (!hasAnyConfirmedMid && hasPendingLocalPublication() && retryCount < 5) {
            log('SIGNAL', `No MIDs available yet (attempt ${retryCount + 1}/5), retrying in 1s...`);
            setTimeout(() => broadcastMediaMids(retryCount + 1), 1000);
            return;
        }

        if (nextFingerprint === lastBroadcastedLocalMediaFingerprint) {
            log('SIGNAL', 'Skipping SFU Media Ready broadcast: local publication fingerprint unchanged', {
                audioMid,
                videoMid,
                screenMid,
            });
            return;
        }

        // If we previously advertised media and now have none, broadcast one explicit clear.
        if (!hasAnyConfirmedMid && lastBroadcastedLocalMediaFingerprint && lastBroadcastedLocalMediaFingerprint !== nextFingerprint) {
            log('SIGNAL', 'Triggering SFU Media Ready broadcast', { audioMid, videoMid, screenMid });
            onSfuMediaReady(undefined, undefined, undefined);
            lastBroadcastedLocalMediaFingerprint = nextFingerprint;
            return;
        }

        if (!hasAnyConfirmedMid) {
            log('SIGNAL', 'Skipping SFU Media Ready broadcast: still no confirmed local track MIDs', {
                publications: localPublications,
                lastBroadcastedLocalMediaFingerprint,
            });
            return;
        }

        log('SIGNAL', 'Triggering SFU Media Ready broadcast', { audioMid, videoMid, screenMid });
        onSfuMediaReady(audioMid, videoMid, screenMid);
        lastBroadcastedLocalMediaFingerprint = nextFingerprint;
    }

    // Ported from CallApp.vue L1782-1818: When a new participant joins,
    // re-send our sfu-media-ready signal so late joiners can pull our tracks.
    function rebroadcastToJoiner(joinerPublicId: string, retryCount = 0) {
        if (!sfuSessionId.value || !sfuPc || !localParticipantRef.value) return;
        const { audioMid, videoMid, screenMid } = getSignalableLocalTrackMids();
        const hasAnyConfirmedMid = !!(audioMid || videoMid || screenMid);

        // If we have no MIDs yet, retry — our transceivers may not have tracks active
        if (!hasAnyConfirmedMid && hasPendingLocalPublication() && retryCount < 3) {
            log('SIGNAL', `No MIDs to rebroadcast to ${joinerPublicId} yet (attempt ${retryCount + 1}/3), retrying in 2s...`);
            setTimeout(() => rebroadcastToJoiner(joinerPublicId, retryCount + 1), 2000);
            return;
        }
        if (!hasAnyConfirmedMid) {
            log('SIGNAL', `Skipping rebroadcast to ${joinerPublicId}: no confirmed local track MIDs`, {
                publications: localPublications,
            });
            return;
        }
        
        log('SIGNAL', `Re-broadcasting media info to new joiner ${joinerPublicId}`, { audioMid, videoMid, screenMid });
        
        meetingService.sendSignal(meetingRef.value!.public_id, {
            sender_participant_public_id: localParticipantRef.value.public_id,
            signal_type: 'signal',
            signal_data: {
                type: 'sfu-media-ready',
                current_room_id: currentRoomIdRef.value,
                sessionId: sfuSessionId.value,
                audioMid,
                videoMid,
                screenMid
            },
            target_participant_public_id: joinerPublicId,
        }).catch(() => {});

        // Also notify about active screen share explicitly if it exists
        if (screenMid) {
            // Ensure local presence state is synced for the UI
            (window as any).meetingPresence?.toggleScreenShareState(localParticipantRef.value.public_id, true);
            
            meetingService.sendSignal(meetingRef.value!.public_id, {
                sender_participant_public_id: localParticipantRef.value.public_id,
                signal_type: 'screen-share-toggle',
                signal_data: { sharing: true, mid: screenMid },
                target_participant_public_id: joinerPublicId,
            }).catch(() => {});
        }
    }

    function requestMediaInfo(
        participantPublicId: string,
        options?: {
            minIntervalMs?: number;
            force?: boolean;
            reason?: string;
        }
    ) {
        if (!localParticipantRef.value || !meetingRef.value) return;
        const participantId = participantPublicId.toLowerCase();
        const minIntervalMs = options?.minIntervalMs ?? 2500;
        const force = !!options?.force;
        const reason = options?.reason || 'generic';
        const publication = getRemotePublication(participantId, true)!;
        const now = Date.now();
        const lastInfoRequestAt = publication.lastInfoRequestAt || 0;
        const lastUpdatedAt = publication.lastUpdatedAt || 0;
        const lastRequestAgeMs = lastInfoRequestAt ? now - lastInfoRequestAt : null;
        const lastUpdateAgeMs = lastUpdatedAt ? now - lastUpdatedAt : null;

        if (!force) {
            if (lastInfoRequestAt && now - lastInfoRequestAt < minIntervalMs) {
                log('SIGNAL', `Skipping media info request for ${participantId}: debounced`, {
                    reason,
                    lastRequestAgeMs,
                    minIntervalMs,
                });
                return;
            }
            if (lastUpdatedAt && now - lastUpdatedAt < 1500) {
                log('SIGNAL', `Skipping media info request for ${participantId}: remote state updated recently`, {
                    reason,
                    lastUpdateAgeMs,
                    mediaStateVersion: publication.mediaStateVersion,
                    mids: {
                        audioMid: publication.audioMid,
                        videoMid: publication.videoMid,
                        screenMid: publication.screenMid,
                    },
                });
                return;
            }
        }

        publication.lastInfoRequestAt = now;
        log('SIGNAL', `Requesting media info from ${participantId}`, {
            reason,
            lastRequestAgeMs,
            lastUpdateAgeMs,
            knownState: {
                sessionId: publication.sessionId,
                mediaStateVersion: publication.mediaStateVersion,
                audioMid: publication.audioMid,
                videoMid: publication.videoMid,
                screenMid: publication.screenMid,
            },
        });
        meetingService.sendSignal(meetingRef.value.public_id, {
            sender_participant_public_id: localParticipantRef.value.public_id,
            signal_type: 'request-media-info',
            signal_data: {},
            target_participant_public_id: participantId
        }).catch(() => {});
    }

    function isSfuConnected(pc: RTCPeerConnection): boolean {
        return pc.connectionState === 'connected' || pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed';
    }

    async function waitForSfuConnected(timeoutMs = 4500): Promise<boolean> {
        if (!sfuPc) return false;
        const pc = sfuPc;

        if (isSfuConnected(pc)) return true;

        return new Promise((resolve) => {
            let settled = false;
            const timer = window.setTimeout(() => finalize(false), timeoutMs);

            const onStateChange = () => {
                if (pc !== sfuPc) return finalize(false);
                if (isSfuConnected(pc)) finalize(true);
            };

            function finalize(result: boolean) {
                if (settled) return;
                settled = true;
                window.clearTimeout(timer);
                pc.removeEventListener('connectionstatechange', onStateChange);
                pc.removeEventListener('iceconnectionstatechange', onStateChange);
                resolve(result);
            }

            pc.addEventListener('connectionstatechange', onStateChange);
            pc.addEventListener('iceconnectionstatechange', onStateChange);
        });
    }

    function startHealthCheck() {
        if (healthCheckInterval) return;
        log('HEALTH', 'Starting SFU Desync Guard (60s check)', { clientInstanceId });
        healthCheckInterval = window.setInterval(async () => {
            if (!sfuPc || !sfuSessionId.value || !meetingRef.value) return;

            const participants = meetingRef.value.participants || [];
            const localId = localParticipantRef.value?.public_id.toLowerCase();

            for (const p of participants) {
                const pid = p.public_id.toLowerCase();
                if (pid === localId || p.status !== 'admitted') continue;

                const stream = remoteStreams.value.get(pid);
                const screenStream = remoteStreams.value.get(`${pid}:screen`);
                const publication = getRemotePublication(pid, false);
                const sessionId = publication?.sessionId || remoteSfuSessions.get(pid);
                const knownTracks = publication || remoteSfuTracks.get(pid) || null;
                const isVisible = visibleParticipantIds.value.has(pid) || visibleParticipantIds.value.has(`${pid}:screen`);
                const firstSeenAt = participantFirstSeen.get(pid) || null;
                const firstSeenAgeMs = firstSeenAt ? Date.now() - firstSeenAt : null;
                const streamSummary = stream
                    ? {
                        audioTracks: stream.getAudioTracks().length,
                        videoTracks: stream.getVideoTracks().length,
                        allEnded: stream.getTracks().length > 0 && stream.getTracks().every(t => t.readyState === 'ended'),
                    }
                    : null;
                const screenStreamSummary = screenStream
                    ? {
                        videoTracks: screenStream.getVideoTracks().length,
                        allEnded: screenStream.getTracks().length > 0 && screenStream.getTracks().every(t => t.readyState === 'ended'),
                    }
                    : null;
                const diag = {
                    clientInstanceId,
                    localSfuSessionId: sfuSessionId.value,
                    remoteSfuSessionId: sessionId || null,
                    knownTracks,
                    isVisible,
                    firstSeenAgeMs,
                    streamSummary,
                    screenStreamSummary,
                    connectionState: sfuConnectionState.value,
                    iceState: sfuIceState.value,
                };
                
                // --- ROOM FILTERING ---
                // Only "repair" participants who are in the same room as us.
                // This prevents pulling tracks for people in different breakout rooms.
                const pRoomId = p.current_room_id ? String(p.current_room_id) : null;
                const myRoomId = currentRoomIdRef.value ? String(currentRoomIdRef.value) : null;

                if (pRoomId !== myRoomId) {
                    // If they are in a different room but we still have their stream, clean it up
                    if (stream || screenStream) {
                        log('HEALTH', `Cleaning up out-of-room stream for ${pid} (${pRoomId} vs ${myRoomId})`, diag);
                        removeParticipantStreams(pid);
                    }
                    continue;
                }

                const hasMainMedia = hasPullableMainMedia(publication);
                const hasScreenMedia = hasPullableScreenMedia(publication);
                const mainStreamBroken = hasMainMedia && isMediaStreamBroken(stream, ['audio', 'video']);
                const screenStreamBroken = hasScreenMedia && isMediaStreamBroken(screenStream, ['video']);
                const publicationStale = !publication?.lastUpdatedAt || (Date.now() - publication.lastUpdatedAt > 15000);

                // Case 1: No session info yet (missed sfu-media-ready)
                if (!sessionId) {
                    log('HEALTH', `No session for ${pid}. Requesting media info.`, diag);
                    requestMediaInfo(pid, { minIntervalMs: 5000, reason: 'health:no-session' });
                }
                // Case 2: We have a session but no known publication state yet
                else if (!hasMainMedia && !hasScreenMedia) {
                    if (publicationStale) {
                        log('HEALTH', `Session present but remote media state is empty/stale for ${pid}. Requesting refresh.`, diag);
                        requestMediaInfo(pid, { minIntervalMs: 5000, reason: 'health:stale-publication' });
                    }
                }
                // Case 3: Publication exists but the local consumer is broken/missing
                else if (mainStreamBroken || screenStreamBroken) {
                    if (mainStreamBroken && stream) {
                        removeParticipantMainStream(pid);
                    }
                    if (screenStreamBroken && screenStream) {
                        const newMap = new Map(remoteStreams.value);
                        newMap.delete(`${pid}:screen`);
                        remoteStreams.value = newMap;
                    }
                    log('HEALTH', `Broken/Missing published stream for ${pid}. Triggering repair pull.`, diag);
                    pullParticipantTracks(
                        pid,
                        sessionId,
                        publication?.audioMid ?? undefined,
                        publication?.videoMid ?? undefined,
                        publication?.screenMid ?? undefined
                    );
                }
            }
        }, 90000); // Relaxed for high-latency stability
    }

    async function initSFU(stream: MediaStream | null) {
        if (!meetingRef.value || !localParticipantRef.value) return;
        return runInSFUQueue(async () => {
            if (!meetingRef.value || !localParticipantRef.value) return;
            
            // Optimization: If session already exists and is healthy, don't restart it.
            // Guard against stale state after cleanup() where refs may still look "connected"
            // while the actual RTCPeerConnection/session is already torn down.
            const hasActivePc =
                !!sfuPc &&
                sfuPc.connectionState !== 'closed' &&
                sfuPc.signalingState !== 'closed';
            const hasHealthySession =
                hasActivePc &&
                !!sfuSessionId.value &&
                (
                    sfuPc!.connectionState === 'connected' ||
                    sfuPc!.connectionState === 'connecting' ||
                    sfuPc!.iceConnectionState === 'connected' ||
                    sfuPc!.iceConnectionState === 'completed' ||
                    sfuPc!.iceConnectionState === 'checking'
                );

            if (hasHealthySession) {
                log('SFU', 'SFU already healthy, skipping redundant initSFU');
                if (stream) {
                    // Just update tracks if provided
                    for (const track of stream.getTracks()) {
                        await replaceTrack(track.kind as 'audio' | 'video', track);
                    }
                }
                return;
            }

            isInitializingSFU.value = true;
            try {
                await doInitSFU(stream);
            } finally {
                isInitializingSFU.value = false;
            }
        });
    }

    async function doInitSFU(stream: MediaStream | null) {
        if (sfuPc) sfuPc.close();

        log('SFU', 'Initializing RTCPeerConnection (Cold Start)', { hasStream: !!stream });
        sfuPc = new RTCPeerConnection({
            iceServers: iceServersRef.value.length > 0 ? iceServersRef.value : [{ urls: 'stun:stun.cloudflare.com:3478' }],
            bundlePolicy: 'balanced', // More resilient than max-bundle
        });

        const iceConnectedPromise = Promise.race([
            new Promise((resolve) => {
                const checkState = () => {
                    if (sfuPc!.iceConnectionState === "connected" || sfuPc!.iceConnectionState === "completed") {
                        resolve(true);
                    }
                };
                sfuPc!.oniceconnectionstatechange = () => {
                    sfuIceState.value = sfuPc!.iceConnectionState;
                    log('SFU', `ICE Connection State: ${sfuIceState.value}`);
                    checkState();
                };
                sfuPc!.onconnectionstatechange = () => {
                    sfuConnectionState.value = sfuPc!.connectionState;
                    log('SFU', `Connection State: ${sfuConnectionState.value}`);
                };
                checkState();
            }),
            new Promise((resolve) => setTimeout(() => {
                log('SFU', 'ICE Connection wait timed out (20s), proceeding with signaling...');
                resolve(true);
            }, 20000))
        ]);

        sfuPc.ontrack = (event) => {
            const track = event.track;
            const mid = event.transceiver.mid;
            if (!mid) return;
            
            log("TRACK", `ontrack Event: kind=${track.kind}, mid=${mid}`);

            let participantId = midToParticipantMap.get(mid);
            const assoc = sfuTransceiverMap.get(event.transceiver);

            // Guard: Never process tracks from our own publication transceivers.
            if (assoc?.participantId === 'self') {
                log('DEBUG', `Ignoring ontrack for local transceiver mid=${mid}`);
                return;
            }

            if (!participantId && assoc) {
                participantId = assoc.participantId;
                if (assoc.trackName === 'screen') participantId += ':screen';
                log("TRACK", `Resolved ${mid} via transceiver association to ${participantId}`);
            } else if (participantId) {
                log("TRACK", `Resolved ${mid} via MID map to ${participantId}`);
                
                // Potential Theft Detection: If signaling says it's for Person A but 
                // the transceiver was previously associated with Person B, log it.
                if (assoc && assoc.participantId !== participantId.replace(':screen', '')) {
                    log('WARNING', `Transceiver theft detected! Signaling=${participantId}, StaleAssoc=${assoc.participantId}:${assoc.trackName}. Preferring signaling.`);
                }
            }

            // 3. Resolve internal track promises (Asynchronous Binding Pattern)
            const key = `${mid}:${track.kind}`;
            const resolver = trackResolvers.get(key) || trackResolvers.get(event.transceiver);
            
            if (resolver) {
                log('SFU', `Resolving pending track for ${mid ? key : 'transceiver-bound'}`);
                clearTimeout(resolver.timeout);
                trackResolvers.delete(key);
                trackResolvers.delete(event.transceiver);
                resolver.resolve({ track, transceiver: event.transceiver });
            }

            if (participantId) {
                const pid = participantId.toLowerCase();
                log("TRACK", `Final Match: ${track.kind} (${mid}) for ${pid}`);
                let s = event.streams[0] || new MediaStream([track]);
                
                const handleActive = () => {
                    const existingStream = remoteStreams.value.get(pid);
                    if (existingStream) {
                        let didUpdateExistingStream = false;

                        // Prune dead tracks of same kind to avoid UI freezes/zombie tiles
                        // Only remove tracks of the same kind that ARE NOT the new track
                        existingStream.getTracks()
                            .filter(t => t.kind === track.kind && t.id !== track.id)
                            .forEach(t => {
                                log('TRACK', `Pruning old ${t.kind} track ${t.id} for ${pid}`);
                                existingStream.removeTrack(t);
                                didUpdateExistingStream = true;
                            });

                        if (!existingStream.getTracks().find(t => t.id === track.id)) {
                            existingStream.addTrack(track);
                            log('TRACK', `Linked ${track.kind} track ${track.id} to existing stream for ${pid}`);
                            didUpdateExistingStream = true;
                        }

                        if (didUpdateExistingStream) {
                            // Re-emit the stream map so Vue consumers notice hot track swaps
                            // inside an existing MediaStream and can rebind playback elements.
                            remoteStreams.value = new Map(remoteStreams.value);
                        }
                    } else {
                        const newMap = new Map(remoteStreams.value);
                        newMap.set(pid, s);
                        remoteStreams.value = newMap;
                        log('TRACK', `Created new stream for ${pid} with ${track.kind} track ${track.id}`);
                        if (!pid.endsWith(':screen')) {
                            startAudioAnalysis(pid, s);
                        }
                    }
                };

                const handleInactive = () => {
                    const existingStream = remoteStreams.value.get(pid);
                    if (!existingStream) return;

                    const stale = existingStream.getTracks().find(t => t.id === track.id);
                    if (!stale) return;

                    existingStream.removeTrack(stale);
                    try { stale.stop(); } catch {}
                    log('TRACK', `Track ${track.id} became inactive for ${pid}; pruned from stream`);

                    if (stale.kind === 'audio' && !pid.endsWith(':screen') && existingStream.getAudioTracks().length === 0) {
                        stopAudioAnalysis(pid);
                    }

                    if (existingStream.getTracks().length === 0) {
                        const newMap = new Map(remoteStreams.value);
                        newMap.delete(pid);
                        remoteStreams.value = newMap;
                    } else {
                        remoteStreams.value = new Map(remoteStreams.value);
                    }
                };
                
                track.onunmute = handleActive;
                track.onmute = handleInactive;
                track.onended = handleInactive;
                if (!track.muted) handleActive();
            } else {
                log("TRACK", `No participant mapping for MID ${mid}, buffering...`);
                pendingTrackEvents.push({
                    track, mid, transceiver: event.transceiver, streams: event.streams
                });
            }
        };

        // ALWAYS add placeholder transceivers for Cold Start/Double Tap prep.
        // This ensures we have BUNDLE-able media sections even if the stream is initially empty.
        log('SFU', 'Adding placeholder transceivers for Cold Start/Double Tap prep');
        const atc = sfuPc!.addTransceiver("audio", { direction: "sendonly" });
        const vtc = sfuPc!.addTransceiver("video", {
            direction: "sendonly",
            sendEncodings: [
                { rid: "l", active: true, maxBitrate: 150000, scaleResolutionDownBy: 4 },
                { rid: "m", active: true, maxBitrate: 500000, scaleResolutionDownBy: 2 },
                { rid: "h", active: true, maxBitrate: 1500000, scaleResolutionDownBy: 1 },
            ],
        });
        sfuTransceiverMap.set(atc, { participantId: 'self', trackName: 'audio' });
        sfuTransceiverMap.set(vtc, { participantId: 'self', trackName: 'video' });

        // --- STICKY SLOTS PRE-ALLOCATION ---
        // Restore transceivers for existing participants in their original order
        participantSlots.value.forEach(pid => {
            const audioTc = sfuPc!.addTransceiver("audio", { direction: "recvonly" });
            const videoTc = sfuPc!.addTransceiver("video", { direction: "recvonly" });
            sfuTransceiverMap.set(audioTc, { participantId: pid, trackName: "audio" });
            sfuTransceiverMap.set(videoTc, { participantId: pid, trackName: "video" });
            
            // If they had a screenshare slot, restore that too
            if (remoteStreams.value.has(`${pid}:screen`)) {
                const screenTc = sfuPc!.addTransceiver("video", { direction: "recvonly" });
                sfuTransceiverMap.set(screenTc, { participantId: pid, trackName: "screen" });
            }
        });

        if (stream) {
            log('SFU', 'Applying initial stream tracks to transceivers');
            stream.getTracks().forEach((track) => {
                let tc = sfuPc!.getTransceivers().find(
                    (t) => t.receiver.track.kind === track.kind && t.direction === "sendonly"
                );

                if (tc) {
                    log('SFU', `Assigning local ${track.kind} track to transceiver`);
                    tc.sender.replaceTrack(track);
                    try {
                        const params = tc.sender.getParameters();
                        if (params.encodings && params.encodings.length > 0) {
                            params.encodings.forEach(e => e.active = true);
                            tc.sender.setParameters(params).catch(() => {});
                        }
                    } catch (e) {}
                } else {
                    // Fallback for unexpected track kinds (like screen if it was somehow in the main stream)
                    const ntc = sfuPc!.addTransceiver(track, { direction: "sendonly", streams: [stream] });
                    sfuTransceiverMap.set(ntc, { participantId: 'self', trackName: track.kind });
                }
            });
        }

        const offer = await sfuPc.createOffer();
        await sfuPc.setLocalDescription(offer);

        const trackObjects = getActiveLocalTrackObjects();

        log('SFU', 'Creating New Session on Backend', trackObjects);
        
        try {
            const sessionRes = await meetingService.sfuSessionNew(
                meetingRef.value!.public_id,
                mungeSdp(sfuPc.localDescription!.sdp),
                trackObjects
            );

            if (sessionRes.sessionId) {
                sfuSessionId.value = sessionRes.sessionId;
                log('SFU', 'Session Established', sessionRes.sessionId);

                // CRITICAL: Complete the initial handshake
                if (sessionRes.sessionDescription) {
                    log('SFU', 'Processing initial server answer');
                    await sfuPc.setRemoteDescription(new RTCSessionDescription(sessionRes.sessionDescription));
                }

                // 2. DOUBLE TAP: Explicitly register tracks via sfuSessionTracks (from CallApp.vue L2293)
                if (trackObjects.length > 0) {
                    log('SFU', 'Double Tap: Explicitly registering tracks to ensure activation');
                    const executeDoubleTap = async (attempt = 1): Promise<void> => {
                        try {
                            const tracksRes = await meetingService.sfuSessionTracks(
                                meetingRef.value!.public_id,
                                sfuSessionId.value!,
                                trackObjects,
                                undefined
                            );
                            if (tracksRes.sessionDescription) {
                                log('SFU', 'Applying Double Tap SDP Answer');
                                await sfuPc!.setRemoteDescription(new RTCSessionDescription(tracksRes.sessionDescription));
                            }
                        } catch (e: any) {
                            const isRetryable = e?.response?.status === 425 || e?.response?.status === 502;
                            if (isRetryable && attempt < 3) {
                                const delay = 1000 * attempt;
                                log('SFU', `Double Tap failed with ${e?.response?.status}. Retrying in ${delay}ms...`);
                                await new Promise(r => setTimeout(r, delay));
                                return executeDoubleTap(attempt + 1);
                            }
                            log('ERROR', 'Double Tap track registration failed/warning:', e);
                        }
                    };
                    await executeDoubleTap();
                } else {
                    log('SFU', 'Skipping Double Tap: no active local sender tracks to register yet');
                }

                await iceConnectedPromise;
                syncLocalPublicationsFromTransceivers();
                
                // Deterministic Handshake Guard:
                // Wait a tiny bit for the browser to fully settle MIDs in the PC 
                await new Promise(r => setTimeout(r, 100));
                
                broadcastMediaMids();
                startHealthCheck();
                startQualityMonitor();

                // Replay any queued signals that arrived before we were ready
                if (pendingPullSignals.length > 0) {
                    log('SFU', `Replaying ${pendingPullSignals.length} queued pull signals`);
                    const signals = [...pendingPullSignals];
                    pendingPullSignals.length = 0;
                    for (const sig of signals) {
                        pullParticipantTracks(sig.participantPublicId, sig.remoteSessionId, sig.audioMid, sig.videoMid, sig.screenMid);
                    }
                }
            }
        } catch (e) {
            onSFUError(e);
        }
    }

    // Ported from CallApp.vue L2768-2985: Server-initiated SDP flow
    async function pullParticipantTracks(
        participantPublicId: string,
        remoteSessionId?: string,
        audioMid?: string,
        videoMid?: string,
        screenMid?: string,
        pullGeneration?: number,
        options?: PullParticipantTracksOptions
    ) {
        // Queue if session not ready yet (signal arrived before initSFU completed)
        if (!sfuPc || !sfuSessionId.value) {
            log('SFU', `Session not ready, queuing signal for ${participantPublicId}`);
            pendingPullSignals.push({ participantPublicId, remoteSessionId, audioMid, videoMid, screenMid });
            return;
        }

        const normalizedId = participantPublicId.toLowerCase();
        const forceApiPull = !!options?.forceApiPull;
        
        // Guard: Never pull our own tracks through the SFU. This prevents redundant transceivers
        // and signaling loops that can lead to transceiver state confusion.
        if (localParticipantRef.value && normalizedId === localParticipantRef.value.public_id.toLowerCase()) {
            log('DEBUG', `Ignoring pull request for local participant ${normalizedId}`);
            return;
        }

        const remotePublication = remotePublications.get(normalizedId) || createEmptyRemotePublication();
        const knownTracks = remoteSfuTracks.get(normalizedId);
        const targetSessionId = remoteSessionId || remotePublication.sessionId || remoteSfuSessions.get(normalizedId);
        const actualAudioMid = audioMid ?? remotePublication.audioMid ?? knownTracks?.audioMid;
        const actualVideoMid = videoMid ?? remotePublication.videoMid ?? knownTracks?.videoMid;
        const actualScreenMid = screenMid ?? remotePublication.screenMid ?? knownTracks?.screenMid;
        const forcedKinds = options?.pullKinds || null;
        const allowAudioKind = !forcedKinds || !!forcedKinds.audio;
        const allowVideoKind = !forcedKinds || !!forcedKinds.video;
        const allowScreenKind = !forcedKinds || !!forcedKinds.screen;
        const requestFingerprint = buildRemotePublicationFingerprint(
            targetSessionId,
            actualAudioMid,
            actualVideoMid,
            actualScreenMid
        );
        const requestedPublicationChanged =
            remotePublication.lastPullRequestedFingerprint !== null &&
            remotePublication.lastPullRequestedFingerprint !== requestFingerprint;

        if (pullGeneration === undefined) {
            const inFlightAttempts = participantPullAttempts.get(normalizedId) || 0;
            if (inFlightAttempts > 0 && !requestedPublicationChanged) {
                log('SFU', `Pull already in-flight for ${normalizedId}, skipping duplicate request`, {
                    attempts: inFlightAttempts,
                    provided: { audioMid, videoMid, screenMid },
                    known: knownTracks || null,
                    requestFingerprint,
                    lastRequestedFingerprint: remotePublication.lastPullRequestedFingerprint,
                });
                return;
            }
            const nextGeneration = (participantPullGeneration.get(normalizedId) || 0) + 1;
            participantPullGeneration.set(normalizedId, nextGeneration);
            pullGeneration = nextGeneration;
        } else {
            const activeGeneration = participantPullGeneration.get(normalizedId);
            if (activeGeneration && pullGeneration !== activeGeneration) {
                log('SFU', `Skipping stale pull for ${normalizedId} (gen ${pullGeneration}, active ${activeGeneration})`);
                return;
            }
        }

        if (!targetSessionId) {
            log('SFU', `No session ID for ${participantPublicId}, cannot pull tracks.`);
            clearParticipantPullState(normalizedId, pullGeneration);
            return;
        }

        if (!actualAudioMid && !actualVideoMid && !actualScreenMid) {
            log('SFU', `No pullable MIDs yet for ${normalizedId} (gen ${pullGeneration})`, {
                provided: { audioMid, videoMid, screenMid },
                known: remotePublication || knownTracks || null,
                sessionId: targetSessionId,
                clientInstanceId,
                localSfuSessionId: sfuSessionId.value,
            });
        }

        if (remotePublication.lastPullRequestedFingerprint !== requestFingerprint) {
            participantPullAttempts.delete(normalizedId);
            remotePublication.lastPullRequestedFingerprint = requestFingerprint;
        }

        if (audioMid !== undefined || videoMid !== undefined || screenMid !== undefined || remoteSessionId) {
            remotePublication.sessionId = targetSessionId;
            remotePublication.audioMid = actualAudioMid ?? null;
            remotePublication.videoMid = actualVideoMid ?? null;
            remotePublication.screenMid = actualScreenMid ?? null;
            remotePublication.lastUpdatedAt = Date.now();
            remotePublications.set(normalizedId, remotePublication);
            syncRemotePublicationMaps(normalizedId, remotePublication);
        }

        // Retry logic with backoff (from CallApp.vue L2799-2812)
        const currentAttempts = (participantPullAttempts.get(normalizedId) || 0) + 1;
        participantPullAttempts.set(normalizedId, currentAttempts);
        remotePublication.lastPullAttemptAt = Date.now();
        const retryDelays = [1000, 1500, 2000, 3000, 5000];
        if (currentAttempts > retryDelays.length) {
            log('ERROR', `Failed to pull tracks for ${normalizedId} after ${retryDelays.length} attempts (gen ${pullGeneration}). Giving up.`, {
                sessionId: targetSessionId,
                mids: { audio: actualAudioMid, video: actualVideoMid, screen: actualScreenMid },
                clientInstanceId,
                localSfuSessionId: sfuSessionId.value,
            });
            clearParticipantPullState(normalizedId, pullGeneration);
            return;
        }

        // Case-specific track requests:
        const existingStream = remoteStreams.value.get(normalizedId);
        
        // Selective subscribing: pull only tracks with explicit usable MIDs.
        // Video/Screen additionally require visibility/grace unless explicitly requested.
        const GRACE_PERIOD_MS = 5000;
        if (!participantFirstSeen.has(normalizedId)) {
            participantFirstSeen.set(normalizedId, Date.now());
        }
        const timeSinceFirstSeen = Date.now() - (participantFirstSeen.get(normalizedId) || Date.now());
        const isVisible = visibleParticipantIds.value.has(normalizedId);
        const inGracePeriod = timeSinceFirstSeen < GRACE_PERIOD_MS;
        const hasExplicitVideoMid = allowVideoKind && actualVideoMid !== undefined && actualVideoMid !== null && actualVideoMid !== '';
        const hasExplicitScreenMid = allowScreenKind && actualScreenMid !== undefined && actualScreenMid !== null && actualScreenMid !== '';
        const hasExplicitAudioMid = allowAudioKind && actualAudioMid !== undefined && actualAudioMid !== null && actualAudioMid !== '';
        const shouldPullVideo = isVisible || inGracePeriod || hasExplicitVideoMid;
        const shouldPullScreen = isVisible || inGracePeriod || hasExplicitScreenMid;

        if (!shouldPullVideo && !shouldPullScreen) {
            log('SFU', `Selective sub: skipping video/screen for ${normalizedId} (not visible, grace expired). Audio still pulled.`);
        }

        const existingAudioTracks = existingStream?.getAudioTracks() || [];
        const existingVideoTracks = existingStream?.getVideoTracks() || [];
        const existingScreenStream = remoteStreams.value.get(`${normalizedId}:screen`);
        const hasScreenStream = !!existingScreenStream;
        const needsAudio = hasExplicitAudioMid && (
            existingAudioTracks.length === 0 ||
            isMediaStreamBroken(existingStream, ['audio']) ||
            audioMid !== undefined
        );
        const needsVideo = hasExplicitVideoMid && shouldPullVideo && (
            existingVideoTracks.length === 0 ||
            isMediaStreamBroken(existingStream, ['video']) ||
            videoMid !== undefined
        );
        const needsScreen = hasExplicitScreenMid && shouldPullScreen && (
            !hasScreenStream ||
            isMediaStreamBroken(existingScreenStream, ['video']) ||
            screenMid !== undefined
        );
        const requestedKinds = {
            audio: needsAudio,
            video: needsVideo,
            screen: needsScreen || (currentAttempts === 1 && participantPublicId.includes(':screen')),
        };

        const trackReqs: any[] = [];
        if (needsAudio) trackReqs.push({ location: "remote", sessionId: targetSessionId, trackName: "audio" });
        if (needsVideo) {
            // Determine initial simulcast layer based on spotlight/visibility
            const isSpotlighted = spotlightedParticipantId.value === normalizedId;
            const preferredRid = isSpotlighted ? 'h' : 'l';
            trackPreferredLayers.set(normalizedId, preferredRid);
            const videoReq: any = {
                location: "remote",
                sessionId: targetSessionId,
                trackName: "video",
            };
            if (ENABLE_SIMULCAST_PULL_HINTS) {
                videoReq.simulcast = {
                    preferredRid,
                    priorityOrdering: 'none',
                    ridNotAvailable: 'asciibetical'
                };
            }
            trackReqs.push(videoReq);
        }
        if (needsScreen || (currentAttempts === 1 && participantPublicId.includes(':screen'))) {
             trackReqs.push({ location: "remote", sessionId: targetSessionId, trackName: "screen" });
        }

        if (trackReqs.length === 0) {
            log('SFU', `No tracks to pull for ${participantPublicId}, skipping.`, {
                requestedKinds,
                mids: { audio: actualAudioMid, video: actualVideoMid, screen: actualScreenMid },
                requestFingerprint,
            });
            clearParticipantPullState(normalizedId, pullGeneration);
            return;
        }

        // QUEUE the handshake (from CallApp.vue L2839)
        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) {
                clearParticipantPullState(normalizedId, pullGeneration);
                return;
            }

            try {
                // Ensure transceivers exist before mapping MIDs
                // FIND TRANSCEIVERS: Include inactive ones for reuse to prevent transceiver pile-up (leakage)
                let audioTransceiver = sfuPc.getTransceivers().find(t =>
                    sfuTransceiverMap.get(t)?.participantId === normalizedId &&
                    sfuTransceiverMap.get(t)?.trackName === "audio"
                );
                let videoTransceiver = sfuPc.getTransceivers().find(t =>
                    sfuTransceiverMap.get(t)?.participantId === normalizedId &&
                    sfuTransceiverMap.get(t)?.trackName === "video"
                );
                let screenTransceiver = sfuPc.getTransceivers().find(t =>
                    sfuTransceiverMap.get(t)?.participantId === normalizedId &&
                    sfuTransceiverMap.get(t)?.trackName === "screen"
                );

                if (audioTransceiver && audioTransceiver.direction === 'inactive') {
                    log('SFU', `Reusing inactive audio transceiver for ${normalizedId}`);
                    audioTransceiver.direction = 'recvonly';
                }
                if (videoTransceiver && videoTransceiver.direction === 'inactive') {
                    log('SFU', `Reusing inactive video transceiver for ${normalizedId}`);
                    videoTransceiver.direction = 'recvonly';
                }
                if (screenTransceiver && screenTransceiver.direction === 'inactive') {
                    log('SFU', `Reusing inactive screen transceiver for ${normalizedId}`);
                    screenTransceiver.direction = 'recvonly';
                }

                if (!audioTransceiver && trackReqs.some(r => r.trackName === 'audio')) {
                    audioTransceiver = sfuPc.addTransceiver("audio", { direction: "recvonly" });
                    sfuTransceiverMap.set(audioTransceiver, { participantId: normalizedId, trackName: "audio" });
                    if (!participantSlots.value.includes(normalizedId)) participantSlots.value.push(normalizedId);
                }
                if (!videoTransceiver && trackReqs.some(r => r.trackName === 'video')) {
                    videoTransceiver = sfuPc.addTransceiver("video", { direction: "recvonly" });
                    sfuTransceiverMap.set(videoTransceiver, { participantId: normalizedId, trackName: "video" });
                    if (!participantSlots.value.includes(normalizedId)) participantSlots.value.push(normalizedId);
                }
                if (!screenTransceiver && trackReqs.some(r => r.trackName === 'screen')) {
                    screenTransceiver = sfuPc.addTransceiver("video", { direction: "recvonly" });
                    sfuTransceiverMap.set(screenTransceiver, { participantId: normalizedId, trackName: "screen" });
                }

                // Proactive MID Assignment: Map local transceiver MIDs to the request
                const trackReqsWithMid = trackReqs.map(req => {
                    const t = req.trackName === 'audio' ? audioTransceiver : 
                              req.trackName === 'video' ? videoTransceiver : 
                              req.trackName === 'screen' ? screenTransceiver : null;
                    
                    if (t?.mid) {
                        return { ...req, mid: t.mid };
                    }
                    return req;
                });

                // Set up track resolvers (SDK: createConsumerObjectAndWaitForTrack pattern)
                trackReqsWithMid.forEach((req) => {
                    const mid = req.mid;
                    const t = req.trackName === 'audio' ? audioTransceiver :
                              req.trackName === 'video' ? videoTransceiver :
                              req.trackName === 'screen' ? screenTransceiver : null;

                    if (!t) return;

                    const kind = req.trackName === 'audio' ? 'audio' : 'video';
                    const midKey = mid ? `${mid}:${kind}` : null;

                    // PROACTIVE CHECK: Track already live; no resolver needed.
                    if (
                        !forceApiPull &&
                        t.receiver.track &&
                        isLiveTrack(t.receiver.track) &&
                        !t.receiver.track.muted
                    ) {
                        log('DEBUG', `Proactive Resolver Hit: Track for ${normalizedId}:${req.trackName} already live in PC`);
                        return;
                    }

                    const timeout = setTimeout(() => {
                        if ((midKey && trackResolvers.has(midKey)) || trackResolvers.has(t)) {
                            log('WARNING', `Timed out waiting for track event ${midKey || 'transceiver-bound'} for ${normalizedId}`);
                            if (midKey) trackResolvers.delete(midKey);
                            trackResolvers.delete(t);
                        }
                    }, 10000);

                    const noopResolve = (_value: { track: MediaStreamTrack, transceiver: RTCRtpTransceiver }) => {};
                    const noopReject = (_error: any) => {};
                    if (midKey) trackResolvers.set(midKey, { resolve: noopResolve, reject: noopReject, timeout });
                    trackResolvers.set(t, { resolve: noopResolve, reject: noopReject, timeout });
                });

                const connected = await waitForSfuConnected();
                if (!connected) {
                    const delay = retryDelays[currentAttempts - 1] || 5000;
                    log('SFU', `PeerConnection not connected yet for pull ${participantPublicId} (gen ${pullGeneration}), retrying in ${delay}ms...`);
                    setTimeout(() => {
                        pullParticipantTracks(
                            participantPublicId,
                            targetSessionId,
                            audioMid,
                            videoMid,
                            screenMid,
                            pullGeneration,
                            options
                        );
                    }, delay);
                    return;
                }

                // PROACTIVE SATISFACTION: If the PeerConnection already has all requested tracks live,
                // we can skip the SFU API call entirely. This prevents "success-but-error" retry loops.
                const satisfiedCount = trackReqsWithMid.filter(req => {
                    if (!req.mid) return false;
                    const tc = sfuPc!.getTransceivers().find(t => t.mid === req.mid);
                    // Check if transceiver is live and matches KIND (security/correctness guard)
                    const track = tc?.receiver.track;
                    const expectedKind = req.trackName === 'audio' ? 'audio' : 'video';
                    return (
                        !!track &&
                        track.kind === expectedKind &&
                        isLiveTrack(track) &&
                        !track.muted
                    );
                }).length;

                if (satisfiedCount >= trackReqs.length && trackReqs.length > 0) {
                    if (forceApiPull) {
                        log(
                            'SFU',
                            `Bypassing proactive-satisfied shortcut for ${participantPublicId}; forcing API pull`,
                            { reason: options?.reason || 'unspecified' }
                        );
                    } else {
                        log('SFU', `Pull targets fully satisfied locally (Proactive) for ${participantPublicId}, skipping API call.`);
                        clearParticipantPullState(normalizedId, pullGeneration);
                        return;
                    }
                }

                log('SFU', `Attempt ${currentAttempts} (gen ${pullGeneration}): Pulling tracks for ${participantPublicId} [Audio: ${audioTransceiver?.mid || 'None'}, Video: ${videoTransceiver?.mid || 'None'}, Screen: ${screenTransceiver?.mid || 'None'}] using local session ${sfuSessionId.value}, remote session ${targetSessionId}...`);

                // SERVER-INITIATED OFFER FLOW (from CallApp.vue L2895-2955)
                // Use OUR local session ID for the API call (we're telling the SFU
                // "pull these remote tracks INTO my session"), but the track requests
                // contain the remote participant's sessionId to identify which tracks to pull.
                const res = await meetingService.sfuSessionTracks(
                    meetingRef.value!.public_id,
                    sfuSessionId.value!,
                    trackReqsWithMid,
                    undefined  // No client offer — server-initiated flow
                );

                const validTracks = Array.isArray(res.tracks)
                    ? res.tracks.filter((track: any) => track.mid && !track.errorCode)
                    : [];
                const explicitTrackErrors = Array.isArray(res.tracks)
                    ? res.tracks.filter((track: any) =>
                        !!track?.errorCode &&
                        trackReqs.some((req) => req.trackName === track.trackName)
                    )
                    : [];
                const erroredTrackNames = new Set(
                    explicitTrackErrors
                        .map((track: any) => track.trackName)
                        .filter((trackName: any) => typeof trackName === 'string')
                );
                const canProcessOffer = !!res.sessionDescription && (validTracks.length > 0 || explicitTrackErrors.length === 0);

                const scheduleRetryForKinds = (
                    retryKinds: { audio: boolean; video: boolean; screen: boolean },
                    reason: string,
                    extra: Record<string, any> = {}
                ) => {
                    const hasRetryTargets = retryKinds.audio || retryKinds.video || retryKinds.screen;
                    if (!hasRetryTargets) {
                        clearParticipantPullState(normalizedId, pullGeneration);
                        return;
                    }

                    const delay = retryDelays[currentAttempts - 1] || 5000;
                    log('SFU', reason, {
                        requestedKinds,
                        retryKinds,
                        explicitTrackErrors: explicitTrackErrors.map((track: any) => ({
                            trackName: track.trackName,
                            mid: track.mid || null,
                            errorCode: track.errorCode || null,
                        })),
                        ...extra,
                    });
                    setTimeout(() => {
                        pullParticipantTracks(
                            participantPublicId,
                            targetSessionId,
                            retryKinds.audio ? (actualAudioMid ?? undefined) : undefined,
                            retryKinds.video ? (actualVideoMid ?? undefined) : undefined,
                            retryKinds.screen ? (actualScreenMid ?? undefined) : undefined,
                            pullGeneration,
                            options
                        );
                    }, delay);
                };

                if (canProcessOffer) {
                    // Normalizing ID for consistency in internal maps
                    const normalizedId = participantPublicId.toLowerCase();

                    // Map MIDs to participant
                    if (validTracks.length > 0) {
                        validTracks.forEach((track: any) => {
                            if (track.mid) {
                                const mapKey = track.trackName === 'screen' ? `${normalizedId}:screen` : normalizedId;
                                
                                // MID MAPPING PURGE: Clear any existing MIDs for this logical target.
                                for (const [oldMid, oldTarget] of midToParticipantMap.entries()) {
                                    if (oldTarget === mapKey && oldMid !== track.mid) {
                                        log('SFU', `Purging stale MID ${oldMid} for ${mapKey} (replaced by ${track.mid})`);
                                        
                                        // HARDEN PURGE: Also clear the transceiver association to prevent ontrack mis-matching
                                        const oldTc = sfuPc!.getTransceivers().find(tr => tr.mid === oldMid);
                                        if (oldTc) {
                                            sfuTransceiverMap.delete(oldTc);
                                            log('SFU', `Cleared stale transceiver map association for MID ${oldMid}`);
                                        }

                                        midToParticipantMap.delete(oldMid);
                                    }
                                }

                                midToParticipantMap.set(track.mid, mapKey);
                                
                                const t = sfuPc!.getTransceivers().find(tr => tr.mid === track.mid);
                                if (t) {
                                    sfuTransceiverMap.set(t, { participantId: normalizedId, trackName: track.trackName });
                                }
                            }
                        });
                    }

                    flushPendingTracks();

                    // Apply server offer and send answer — all wrapped in non-fatal catch
                    // because by this point tracks are already received via ontrack
                    try {
                        log('SFU', `Processing Server Offer for tracks from ${participantPublicId}`);
                        await sfuPc!.setRemoteDescription(toSdpAnswer(res.sessionDescription));
                    } catch (sdpErr) {
                        log('SFU', `setRemoteDescription warning for ${participantPublicId} (track already received, non-fatal):`, sdpErr);
                    }


                    flushPendingTracks();

                    try {
                        const answer = await sfuPc!.createAnswer();
                        await sfuPc!.setLocalDescription(answer);

                        log('SFU', `Renegotiating backend for ${participantPublicId}...`);
                        await meetingService.sfuSessionRenegotiate(
                            meetingRef.value!.public_id,
                            sfuSessionId.value!,
                            mungeSdp(answer.sdp!),
                            'answer',
                            'PUT'
                        );
                    } catch (renegErr) {
                        // Don't throw — the track is already received and linked.
                        // Renegotiation failure shouldn't trigger a full retry.
                        log('SFU', `Renegotiation warning for ${participantPublicId} (track already received, non-fatal):`, renegErr);
                    }

                    const existingParticipantMids = remoteParticipantMids.get(normalizedId) || {};
                    remoteParticipantMids.set(normalizedId, {
                        audio: validTracks.find((t: any) => t.trackName === 'audio')?.mid || existingParticipantMids.audio || '',
                        video: validTracks.find((t: any) => t.trackName === 'video')?.mid || existingParticipantMids.video || '',
                        screen: validTracks.find((t: any) => t.trackName === 'screen')?.mid || existingParticipantMids.screen || ''
                    });

                    // CRITICAL: Settling Delay for High Latency (1500ms)
                    // Gives the PeerConnection a moment to "digest" the new tracks before new signaling
                    log('SFU', `Settling handshake for ${participantPublicId}...`);
                    await new Promise(r => setTimeout(r, 1000));

                    const satisfiedKinds = getSatisfiedPullTargetKinds(normalizedId);
                    
                    // TERMINAL ERROR DETECTION: If the SFU explicitly says a track is missing/forbidden,
                    // we should NOT retry it. This prevents infinite loops on stale metadata.
                    const terminalErrorKinds = {
                        audio: explicitTrackErrors.some(t => t.trackName === 'audio' && (t.errorCode >= 400 || t.errorCode === 'NOT_FOUND')),
                        video: explicitTrackErrors.some(t => t.trackName === 'video' && (t.errorCode >= 400 || t.errorCode === 'NOT_FOUND')),
                        screen: explicitTrackErrors.some(t => t.trackName === 'screen' && (t.errorCode >= 400 || t.errorCode === 'NOT_FOUND')),
                    };

                    const retryKinds = {
                        audio: requestedKinds.audio && !satisfiedKinds.audio && !terminalErrorKinds.audio,
                        video: requestedKinds.video && !satisfiedKinds.video && !terminalErrorKinds.video,
                        screen: requestedKinds.screen && !satisfiedKinds.screen && !terminalErrorKinds.screen,
                    };
                    const satisfiedTargets = hasSatisfiedPullTargets(normalizedId, requestedKinds);
                    const hasRemainingRetries = retryKinds.audio || retryKinds.video || retryKinds.screen;

                    if ((validTracks.length > 0 || satisfiedTargets) && !hasRemainingRetries) {
                        log('SFU', `Track pull success on attempt ${currentAttempts} for ${participantPublicId}`, {
                            returnedTracks: validTracks.map((track: any) => ({
                                trackName: track.trackName,
                                mid: track.mid,
                            })),
                            satisfiedTargets,
                        });

                        // Only delete attempt counter after successful settlement
                        const remotePublicationEntry = remotePublications.get(normalizedId);
                        if (remotePublicationEntry) {
                            remotePublicationEntry.lastPullSuccessAt = Date.now();
                            remotePublicationEntry.lastPullSucceededFingerprint = requestFingerprint;
                            remotePublicationEntry.lastPullRequestedFingerprint = requestFingerprint;
                        }
                        clearParticipantPullState(normalizedId, pullGeneration);
                    } else {
                        scheduleRetryForKinds(
                            retryKinds,
                            `Pull attempt ${currentAttempts} for ${participantPublicId} (gen ${pullGeneration}) completed negotiation but did not attach all requested tracks. Rescheduling remaining kinds...`,
                            {
                                satisfiedKinds,
                                satisfiedTargets,
                                returnedTracks: validTracks.map((track: any) => ({
                                    trackName: track.trackName,
                                    mid: track.mid,
                                })),
                            }
                        );
                    }
                } else {
                    // Retry only the kinds that explicitly failed when possible.
                    const retryKinds = {
                        audio: requestedKinds.audio && (erroredTrackNames.size === 0 || erroredTrackNames.has('audio')),
                        video: requestedKinds.video && (erroredTrackNames.size === 0 || erroredTrackNames.has('video')),
                        screen: requestedKinds.screen && (erroredTrackNames.size === 0 || erroredTrackNames.has('screen')),
                    };
                    scheduleRetryForKinds(
                        retryKinds,
                        `Pull attempt ${currentAttempts} for ${participantPublicId} (gen ${pullGeneration}) returned no usable track response. Rescheduling...`,
                        {
                            hasSessionDescription: !!res.sessionDescription,
                            returnedTracks: validTracks.map((track: any) => ({
                                trackName: track.trackName,
                                mid: track.mid,
                            })),
                            lowLevelReturnedTracks: Array.isArray(res.tracks)
                                ? res.tracks.map((track: any) => ({
                                    trackName: track.trackName,
                                    mid: track.mid || null,
                                    errorCode: track.errorCode || null,
                                }))
                                : [],
                        }
                    );
                }
            } catch (error: any) {
                if (shouldRescueSfuSession(error)) {
                    log('ERROR', 'Terminal SFU pull error. Triggering session rescue.', {
                        status: getHttpStatus(error),
                    });
                    await handleSFU406Rescue();
                    return;
                }
                // Retry on server/network errors
                const delay = retryDelays[currentAttempts - 1] || 5000;
                log('ERROR', `Failed to pull tracks (attempt ${currentAttempts}, gen ${pullGeneration}), retrying in ${delay}ms...`, error);
                setTimeout(() => {
                    pullParticipantTracks(
                        participantPublicId,
                        targetSessionId,
                        audioMid,
                        videoMid,
                        screenMid,
                        pullGeneration,
                        options
                    );
                }, delay);
            }
        });
    }

    async function replaceTrack(kind: 'audio' | 'video', newTrack: MediaStreamTrack | null) {
        if (!sfuPc || !sfuSessionId.value) return;

        log('MEDIA', `Replacing ${kind} track`, { hasNewTrack: !!newTrack });
        markLocalPublicationPending(kind, newTrack);

        // Wrap in SFU queue to prevent race conditions with track pulls
        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return;

            const tc = sfuPc.getTransceivers().find(t => 
                sfuTransceiverMap.get(t)?.trackName === kind && 
                sfuTransceiverMap.get(t)?.participantId === 'self'
            );
            
            if (tc) {
                try {
                    const previousTrack = tc.sender.track;
                    const wasTrackLive = !!previousTrack && previousTrack.readyState === 'live';
                    const willHaveLiveTrack = !!newTrack && newTrack.readyState === 'live';

                    // Only renegotiate when enabling for the first time (inactive → sendonly)
                    // Turning OFF: just null the track, no direction/SDP change needed
                    let needsRenegotiation = false;
                    if (newTrack && tc.direction !== 'sendonly') {
                        tc.direction = 'sendonly';
                        needsRenegotiation = true;
                        log('MEDIA', `Upgraded ${kind} transceiver to sendonly`);
                    }
                    // Note: we do NOT change direction to 'inactive' on OFF.
                    // This avoids SDP renegotiation (which was causing 406 errors).

                    await tc.sender.replaceTrack(newTrack);

                    if (!newTrack) {
                        clearLocalPublication(kind);
                    }

                    // Some SFU sessions require an explicit backend SDP sync when a sender track
                    // transitions from absent/ended -> live, even if transceiver direction is already sendonly.
                    const needsBackendSync = needsRenegotiation || (!wasTrackLive && willHaveLiveTrack);
                    if (needsBackendSync) {
                        const reason = needsRenegotiation
                            ? 'direction change'
                            : 'first live track attach';
                        log('MEDIA', `Renegotiating ${kind} due to ${reason}`);
                        const offer = await sfuPc.createOffer();
                        await sfuPc.setLocalDescription(offer);

                        const registerTrackObjects = getActiveLocalTrackObjects();

                        try {
                            const res = await meetingService.sfuSessionTracks(
                                meetingRef.value!.public_id,
                                sfuSessionId.value!,
                                registerTrackObjects,
                                mungeSdp(sfuPc.localDescription!.sdp)
                            );

                            if (kind === 'video' && willHaveLiveTrack) {
                                const registeredVideo = Array.isArray(res.tracks)
                                    ? res.tracks.find((track: any) =>
                                        track.trackName === 'video' &&
                                        !!track.mid &&
                                        !track.errorCode
                                    )
                                    : null;

                                if (!registeredVideo) {
                                    throw new Error('[SFU] Local video track registration returned no valid video track.');
                                }
                            }

                            await sfuPc.setRemoteDescription(toSdpAnswer(res.sessionDescription));
                            syncLocalPublicationsFromTransceivers([kind]);
                        } catch (error: any) {
                            if (shouldRescueSfuSession(error)) {
                                log('ERROR', 'Terminal replaceTrack renegotiation error. Triggering Rescue state.', {
                                    status: getHttpStatus(error),
                                });
                                failLocalPublication(kind, error);
                                await handleSFU406Rescue();
                                return; // Don't broadcast after rescue
                            } else {
                                failLocalPublication(kind, error);
                                log('ERROR', 'Failed to renegotiate backend track sync', error);
                            }
                        }
                    } else if (newTrack && tc.mid) {
                        confirmLocalPublication(kind, tc.mid, newTrack);
                    }

                    // Always broadcast MIDs after any track change (not just direction changes)
                    // so others know when our media becomes available or goes away
                    broadcastMediaMids();
                } catch (error) {
                    failLocalPublication(kind, error);
                    throw error;
                }
            } else {
                failLocalPublication(kind, new Error(`[SFU] Missing self ${kind} transceiver`));
            }
        });
    }

    async function publishScreenTrack(screenStream: MediaStream): Promise<{ mid: string, stream: MediaStream } | null> {
        if (!sfuPc || !sfuSessionId.value) return null;

        localScreenStream.value = screenStream;
        log('MEDIA', `Publishing screen share track (Queued)`);
        const videoTrack = screenStream.getVideoTracks()[0] || null;
        markLocalPublicationPending('screen', videoTrack);

        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return null;

            const getRegisteredScreenTrack = (res: any) => Array.isArray(res?.tracks)
                ? res.tracks.find((track: any) =>
                    track.trackName === 'screen' &&
                    !!track.mid &&
                    !track.errorCode
                ) || null
                : null;

            const maxRetries = 5;
            let attempt = 0;

            const executePublish = async (): Promise<{ mid: string, stream: MediaStream } | null> => {
                attempt++;
                try {
                    const selfScreenTransceivers = sfuPc!.getTransceivers().filter((transceiver) =>
                        sfuTransceiverMap.get(transceiver)?.trackName === 'screen' &&
                        sfuTransceiverMap.get(transceiver)?.participantId === 'self'
                    );
                    let screenTc = selfScreenTransceivers.find((transceiver) =>
                        sfuTransceiverMap.get(transceiver)?.trackName === 'screen'
                    ) || null;

                    if (screenTc) {
                        const isReusing = !!screenTc.mid;
                        if (isReusing) {
                            log('MEDIA', `Reusing existing screen transceiver (MID: ${screenTc.mid}, Attempt ${attempt})`);
                        } else {
                            log('MEDIA', `Reusing inactive screen placeholder transceiver (Attempt ${attempt})`);
                        }

                        await screenTc.sender.replaceTrack(videoTrack);

                        // If it wasn't sendonly, we must upgrade it
                        let needsRenegotiation = false;
                        if (screenTc.direction !== 'sendonly') {
                            screenTc.direction = 'sendonly';
                            needsRenegotiation = true;
                        }

                        // Re-attaching or upgrading a screen track after a prior unpublish 
                        // needs an explicit backend sync, otherwise peers keep the old 
                        // screen MID metadata but the SFU has no live screen publication.
                        const offer = await sfuPc!.createOffer();
                        await sfuPc!.setLocalDescription(offer);

                        const trackObjects = getActiveLocalTrackObjects(['screen']);

                        const res = await meetingService.sfuSessionTracks(
                            meetingRef.value!.public_id,
                            sfuSessionId.value!,
                            trackObjects,
                            mungeSdp(sfuPc!.localDescription!.sdp)
                        );
                        const registeredScreen = getRegisteredScreenTrack(res);
                        if (!registeredScreen) {
                            log('SFU', 'Screen re-publish returned no valid screen track from backend', {
                                returnedTracks: Array.isArray(res?.tracks)
                                    ? res.tracks.map((track: any) => ({
                                        trackName: track.trackName || null,
                                        mid: track.mid || null,
                                        errorCode: track.errorCode || null,
                                    }))
                                    : [],
                            });
                            throw new Error('[SFU] Screen track registration returned no valid screen track.');
                        }
                        await sfuPc!.setRemoteDescription(toSdpAnswer(res.sessionDescription));
                        syncLocalPublicationsFromTransceivers(['screen']);
                        if (localPublications.screen.state !== 'published' && videoTrack) {
                            confirmLocalPublication('screen', screenTc.mid || registeredScreen.mid || null, videoTrack);
                        }

                        broadcastMediaMids();
                        return { mid: screenTc.mid || '', stream: screenStream };
                    }

                    if (!screenTc) {
                        log('MEDIA', `Creating new transceiver for screen share (Attempt ${attempt})`);
                        screenTc = sfuPc!.addTransceiver(videoTrack, {
                            direction: 'sendonly',
                            streams: [screenStream],
                            // SIMPLIFIED SCREEN ENCODINGS: Omit RIDs/simulcast for screen share.
                            // Some receivers expect a single high-quality stream and stall on simulcast layers.
                        });
                        sfuTransceiverMap.set(screenTc, { participantId: 'self', trackName: 'screen' });
                        
                        // Renegotiate with SFU for NEW transceiver
                        const offer = await sfuPc!.createOffer();
                        await sfuPc!.setLocalDescription(offer);

                        const trackObjects = getActiveLocalTrackObjects();

                        const res = await meetingService.sfuSessionTracks(
                            meetingRef.value!.public_id,
                            sfuSessionId.value!,
                            trackObjects,
                            mungeSdp(sfuPc!.localDescription!.sdp)
                        );
                        const registeredScreen = getRegisteredScreenTrack(res);
                        if (!registeredScreen) {
                            log('SFU', 'Screen publish returned no valid screen track from backend', {
                                returnedTracks: Array.isArray(res?.tracks)
                                    ? res.tracks.map((track: any) => ({
                                        trackName: track.trackName || null,
                                        mid: track.mid || null,
                                        errorCode: track.errorCode || null,
                                    }))
                                    : [],
                            });
                            throw new Error('[SFU] Screen track registration returned no valid screen track.');
                        }
                        await sfuPc!.setRemoteDescription(toSdpAnswer(res.sessionDescription));
                        syncLocalPublicationsFromTransceivers(['screen']);
                        if (localPublications.screen.state !== 'published' && videoTrack) {
                            confirmLocalPublication('screen', screenTc.mid || registeredScreen.mid || null, videoTrack);
                        }
                        broadcastMediaMids();
                        return { mid: screenTc.mid || '', stream: screenStream };
                    }
                } catch (error: any) {
                    const isTooEarly = error?.response?.status === 425 || error?.message?.includes('425');
                    const isMissingTrackRegistration = error?.message?.includes('no valid screen track');
                    
                    if ((isTooEarly || isMissingTrackRegistration) && attempt < maxRetries) {
                        const delay = 500 * attempt;
                        log(
                            'SFU',
                            `${isTooEarly ? 'Backend reported 425 (Too Early)' : 'Backend did not confirm screen registration'} on publish attempt ${attempt}. Retrying in ${delay}ms...`
                        );
                        await new Promise(r => setTimeout(r, delay));
                        return executePublish();
                    }

                    if (shouldRescueSfuSession(error)) {
                        log('ERROR', 'Terminal publish-screen error. Rescuing SFU session.', {
                            status: getHttpStatus(error),
                        });
                        failLocalPublication('screen', error);
                        await handleSFU406Rescue();
                    } else {
                        failLocalPublication('screen', error);
                        log('ERROR', `Failed to publish screen track after ${attempt} attempts`, error);
                    }
                    return null;
                }
            };

            return executePublish();
        });
    }

    function stopLocalScreenCapture(stream: MediaStream | null) {
        if (!stream) return;

        stream.getTracks().forEach((track) => {
            track.onended = null;
            try { track.stop(); } catch {}
        });
    }

    async function unpublishScreenTrack() {
        if (!sfuPc || !sfuSessionId.value) return;

        const activeScreenStream = localScreenStream.value;
        localScreenStream.value = null;
        log('MEDIA', 'Unpublishing screen track (Queued)');
        markLocalPublicationPending('screen', null);
        stopLocalScreenCapture(activeScreenStream);

        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return;

            const screenTc = sfuPc.getTransceivers().find(t => 
                sfuTransceiverMap.get(t)?.trackName === 'screen' && 
                sfuTransceiverMap.get(t)?.participantId === 'self'
            );
            
            if (screenTc) {
                log('MEDIA', 'Unpublishing screen track natively');
                await screenTc.sender.replaceTrack(null);
                
                // SYNC BACKEND FIRST: Inform the SFU we are dropping the track.
                // We do this BEFORE setting direction to 'inactive' to avoid 406 Not Acceptable status
                // where the SFU refuses to negotiate a transceiver that we already killed locally.
                const trackObjects = getActiveLocalTrackObjects();
                try {
                    await meetingService.sfuSessionTracks(
                        meetingRef.value!.public_id,
                        sfuSessionId.value!,
                        trackObjects,
                        undefined
                    );
                } catch (e) {
                    log('WARN', 'Failed to sync backend on screen unpublish', e);
                    // Even if sync fails, we proceed with local cleanup to keep UI responsive
                }

                // CLEANUP LAST: Explicitly set direction to inactive.
                if (screenTc.direction !== 'inactive' && screenTc.direction !== 'stopped') {
                    try {
                        screenTc.direction = 'inactive';
                        log('MEDIA', 'Set screen transceiver to inactive');
                    } catch (e) {
                        log('WARN', 'Failed to set screen transceiver inactive', e);
                    }
                }
                
                clearLocalPublication('screen');
                
                broadcastMediaMids();
            } else {
                clearLocalPublication('screen');
            }
        });
    }

    async function handleSFU406Rescue() {
        if (!sfuPc || !sfuSessionId.value) return;
        log('ERROR', '>>> INITIATING 406 RESCUE TEARDOWN <<<');
        await resetSFUSession(localStream.value);
    }

    async function resetSFUSession(activeLocalStream: MediaStream | null) {
        return runInSFUQueue(async () => {
            log('SFU', 'Tearing down SFU State completely...');
            cleanup();
            
            if (sfuPc) {
                sfuPc.onicecandidate = null;
                sfuPc.ontrack = null;
                sfuPc.oniceconnectionstatechange = null;
                sfuPc.onconnectionstatechange = null;
                sfuPc.close();
                sfuPc = null;
            }

            sfuSessionId.value = null;

            log('SFU', 'Rebuilding SFU State...');
            // Need to run doInitSFU directly here since we are already in the queue
            isInitializingSFU.value = true;
            try {
                await doInitSFU(activeLocalStream);
                
                // --- RE-PUBLISH SCREEN SHARE ---
                // If we were sharing before the reset (e.g. jumping rooms), 
                // we should automatically restart the share in the new session.
                if (localScreenStream.value && localScreenStream.value.getTracks().some(t => t.readyState === 'live')) {
                    log('SFU', 'Auto-restoring persistent screen share after reset');
                    // We call publishScreenTrack which will queue itself behind this reset
                    // but we don't await it here to avoid deadlocks (it's already in the queue)
                    publishScreenTrack(localScreenStream.value);
                }
            } finally {
                isInitializingSFU.value = false;
            }
        });
    }

    function addLocalStream(stream: MediaStream | null) {
        localStream.value = stream;
        
        if (sfuSessionId.value && sfuPc) {
            log('SFU', 'Session already active, updating tracks via replaceTrack');
            if (stream) {
                stream.getTracks().forEach(track => {
                    replaceTrack(track.kind as 'audio' | 'video', track);
                });
            } else {
                replaceTrack('audio', null);
                replaceTrack('video', null);
            }
        } else {
            log('SFU', 'No session, initializing SFU');
            initSFU(stream);
        }
    }

    function setLocalStream(stream: MediaStream | null) {
        localStream.value = stream;
    }

    function clearParticipantPullState(publicId: string, generation?: number) {
        const pid = publicId.toLowerCase();
        participantPullAttempts.delete(pid);
        if (generation === undefined || participantPullGeneration.get(pid) === generation) {
            participantPullGeneration.delete(pid);
        }
    }

    function removeParticipantStreams(publicId: string) {
        const isScreenOnly = publicId.endsWith(':screen');
        const baseId = isScreenOnly ? publicId.slice(0, -7) : publicId;

        const newMap = new Map(remoteStreams.value);
        if (isScreenOnly) {
            newMap.delete(publicId);
        } else {
            newMap.delete(baseId);
            newMap.delete(`${baseId}:screen`);
            stopAudioAnalysis(baseId);
        }
        remoteStreams.value = newMap;

        // Receiver cleanup: set transceivers to inactive and CLEAR the map associations
        // This prevents ontrack from mis-identifying reused transceivers before new MIDs arrive.
        if (sfuPc) {
            sfuPc.getTransceivers().forEach(t => {
                const assoc = sfuTransceiverMap.get(t);
                if (assoc && assoc.participantId === baseId) {
                    // If removing ONLY screenshare, don't touch audio/video transceivers
                    if (isScreenOnly && assoc.trackName !== 'screen') return;

                    if (t.direction !== 'inactive' && t.direction !== 'stopped') {
                        log('SFU', `Inactivating ${assoc.trackName} transceiver for ${baseId} on removal`);
                        try {
                            t.direction = 'inactive';
                        } catch (e) {
                            log('WARN', `Failed to set inactivating direction for ${baseId}:${assoc.trackName}`, e);
                        }
                    }
                    sfuTransceiverMap.delete(t);
                    log('SFU', `Cleared stale association for ${baseId}:${assoc.trackName}`);
                }
            });
        }
    }

    function removeParticipantMainStream(publicId: string) {
        const pid = publicId.toLowerCase();
        const newMap = new Map(remoteStreams.value);
        newMap.delete(pid);
        remoteStreams.value = newMap;
        stopAudioAnalysis(pid);
    }

    function removeParticipantTrack(publicId: string, kind: 'audio' | 'video') {
        const pid = publicId.toLowerCase();
        const stream = remoteStreams.value.get(pid);
        if (!stream) return;

        stream.getTracks()
            .filter((t) => t.kind === kind)
            .forEach((t) => {
                stream.removeTrack(t);
                try { t.stop(); } catch {}
            });

        if (kind === 'audio' && stream.getAudioTracks().length === 0) {
            stopAudioAnalysis(pid);
        }

        const newMap = new Map(remoteStreams.value);
        if (stream.getTracks().length === 0) {
            newMap.delete(pid);
        } else {
            newMap.set(pid, stream);
        }
        remoteStreams.value = newMap;

        // SFU cleanup: also inactivate the specific transceiver if possible
        if (sfuPc) {
            const trackName = kind === 'video' ? 'video' : 'audio';
            sfuPc.getTransceivers().forEach(t => {
                const assoc = sfuTransceiverMap.get(t);
                if (assoc && assoc.participantId === pid && assoc.trackName === trackName) {
                    if (t.direction !== 'inactive' && t.direction !== 'stopped') {
                        log('SFU', `Inactivating ${trackName} transceiver for ${pid} on track removal`);
                        try {
                            t.direction = 'inactive';
                        } catch (e) {
                            log('WARN', `Failed to set inactivating direction for ${pid}:${trackName}`, e);
                        }
                    }
                    sfuTransceiverMap.delete(t);
                }
            });
        }
    }

    function cleanup() {
        if (healthCheckInterval) {
            window.clearInterval(healthCheckInterval);
            healthCheckInterval = null;
        }
        if (qualityMonitorInterval) {
            window.clearInterval(qualityMonitorInterval);
            qualityMonitorInterval = null;
        }
        if (sfuPc) {
            sfuPc.onicecandidate = null;
            sfuPc.ontrack = null;
            sfuPc.oniceconnectionstatechange = null;
            sfuPc.onconnectionstatechange = null;
            sfuPc.close();
            sfuPc = null;
        }
        sfuSessionId.value = null;
        sfuIceState.value = 'new';
        sfuConnectionState.value = 'new';
        isInitializingSFU.value = false;
        remoteStreams.value.clear();
        participantSlots.value = [];
        remoteSfuSessions.clear();
        remoteSfuTracks.clear();
        remotePublications.clear();
        sfuTransceiverMap.clear();
        midToParticipantMap.clear();
        participantPullAttempts.clear();
        participantPullGeneration.clear();
        pendingTrackEvents.length = 0;
        pendingPullSignals.length = 0;
        remoteParticipantMids.clear();
        prevReceiverStats.clear();
        trackPreferredLayers.clear();
        audioAnalysers.forEach(x => {
            window.clearInterval(x.interval);
            x.context.close().catch(()=>{});
        });
        audioAnalysers.clear();
        resetLocalPublications();
    }

    function setVisibleParticipants(ids: string[], spotlightId?: string | null) {
        // Active Grid: cap to MAX_VIDEO_SUBSCRIPTIONS (prioritization order is determined by caller)
        const cappedIds = ids.slice(0, MAX_VIDEO_SUBSCRIPTIONS).map(id => id.toLowerCase());
        const expandedIds: string[] = [];
        const seen = new Set<string>();
        const addVisible = (id: string) => {
            if (!id || seen.has(id)) return;
            seen.add(id);
            expandedIds.push(id);
        };

        // If a participant's screen is visible, consider their base participant visible too.
        // This prevents camera pull starvation while screen share is spotlighted.
        cappedIds.forEach((id) => {
            if (id.endsWith(':screen')) {
                addVisible(id.slice(0, -7));
            }
            addVisible(id);
        });

        const newSet = new Set(expandedIds);
        const added = [...newSet].filter(id => !visibleParticipantIds.value.has(id));
        const removed = [...visibleParticipantIds.value].filter(id => !newSet.has(id));

        visibleParticipantIds.value = newSet;
        spotlightedParticipantId.value = spotlightId?.toLowerCase() || null;

        if (ids.length > MAX_VIDEO_SUBSCRIPTIONS) {
            log('SFU', `Active Grid: capped visible from ${ids.length} to ${MAX_VIDEO_SUBSCRIPTIONS} (expanded to ${expandedIds.length} with screen/base pairing)`);
        }

        if (added.length > 0) {
            log('SFU', `Visibility updated: Added ${added.join(', ')}`);
            // Proactively pull tracks for newly visible participants if we have their session info
            added.forEach(id => {
                const publication = getRemotePublication(id, false);
                const sessionId = publication?.sessionId || remoteSfuSessions.get(id);
                if (sessionId && (publication?.audioMid || publication?.videoMid || publication?.screenMid)) {
                    pullParticipantTracks(
                        id,
                        sessionId,
                        publication?.audioMid ?? undefined,
                        publication?.videoMid ?? undefined,
                        publication?.screenMid ?? undefined
                    );
                }
            });
        }

        if (removed.length > 0) {
            log('SFU', `Visibility updated: Removed ${removed.length} participants from active view.`);
            unsubscribeTracks(removed).catch((err) => {
                log('SFU', 'Non-fatal unsubscribe error on visibility change', err);
            });
        }

        // Debounced simulcast layer update
        scheduleSimulcastLayerUpdate();
    }

    function scheduleSimulcastLayerUpdate() {
        if (simulcastDebounceTimer) clearTimeout(simulcastDebounceTimer);
        simulcastDebounceTimer = setTimeout(() => updateSimulcastLayers(), SIMULCAST_DEBOUNCE_MS);
    }

    async function updateSimulcastLayers() {
        if (!sfuPc || !sfuSessionId.value || !meetingRef.value) return;

        const tracksToUpdate: any[] = [];
        const spotlight = spotlightedParticipantId.value;

        for (const [transceiver, meta] of sfuTransceiverMap.entries()) {
            if (meta.trackName !== 'video' || meta.participantId === 'self') continue;
            if (!transceiver.mid) continue;

            const pid = meta.participantId;
            const isSpotlighted = spotlight === pid;
            const isVisible = visibleParticipantIds.value.has(pid);

            // Determine desired layer
            let desiredRid = 'l'; // filmstrip default = low (180p)
            if (isSpotlighted) {
                desiredRid = 'h'; // spotlight = high (720p)
            } else if (isVisible) {
                desiredRid = 'm'; // visible grid tile = medium (360p)
            }

            // Only update if layer actually changed
            const currentRid = trackPreferredLayers.get(pid);
            if (currentRid === desiredRid) continue;

            trackPreferredLayers.set(pid, desiredRid);
            tracksToUpdate.push({
                mid: transceiver.mid,
                simulcast: {
                    preferredRid: desiredRid,
                    priorityOrdering: 'none',
                    ridNotAvailable: 'asciibetical'
                }
            });

            log('SFU', `Simulcast layer switch: ${pid} → ${desiredRid} (${isSpotlighted ? 'spotlight' : isVisible ? 'grid' : 'filmstrip'})`);
        }

        if (tracksToUpdate.length === 0) return;

        const connected = await waitForSfuConnected();
        if (!connected) {
            log('SFU', 'Skipping simulcast layer update: PeerConnection not connected');
            return;
        }

        try {
            await meetingService.sfuTracksUpdate(
                meetingRef.value.public_id,
                sfuSessionId.value,
                tracksToUpdate
            );
            log('SFU', `Simulcast layers updated for ${tracksToUpdate.length} track(s)`);
        } catch (err) {
            // Non-fatal — layer switching failure shouldn't break anything
            log('SFU', `Simulcast layer update failed (non-fatal):`, err);
        }
    }

    async function closeTracksByMid(trackMids: string[], reason: string) {
        if (!sfuPc || !sfuSessionId.value || !meetingRef.value) return;

        const uniqueMids = [...new Set(trackMids.filter(Boolean))];
        if (uniqueMids.length === 0) return;

        const connected = await waitForSfuConnected();
        if (!connected) {
            log('SFU', `Skipping tracks/close (${reason}): PeerConnection not connected`);
            return;
        }

        try {
            const response = await meetingService.sfuTracksClose(
                meetingRef.value.public_id,
                sfuSessionId.value,
                uniqueMids.map(mid => ({ mid })),
                false
            );

            if (response?.sessionDescription?.sdp) {
                await sfuPc.setRemoteDescription(toSdpDescription(response.sessionDescription, 'offer'));

                const answer = await sfuPc.createAnswer();
                await sfuPc.setLocalDescription(answer);
                await meetingService.sfuSessionRenegotiate(
                    meetingRef.value.public_id,
                    sfuSessionId.value,
                    mungeSdp(answer.sdp || ''),
                    'answer',
                    'PUT'
                );
            }
        } catch (error) {
            log('SFU', `tracks/close failed (${reason})`, error);
        }
    }

    async function unsubscribeTracks(participantPublicIds: string[]) {
        if (!sfuPc || !sfuSessionId.value) return;

        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return;
            const normalizedIds = [...new Set(participantPublicIds.map(id => id.toLowerCase()))]
                .filter(id => id !== localParticipantRef.value?.public_id?.toLowerCase());

            if (normalizedIds.length === 0) return;

            const midsToClose: string[] = [];
            for (const [tc, meta] of sfuTransceiverMap.entries()) {
                if (meta.trackName !== 'video') continue;
                if (tc.direction !== 'recvonly') continue;
                if (!tc.mid) continue;
                if (!normalizedIds.includes(meta.participantId)) continue;
                midsToClose.push(tc.mid);
            }

            if (midsToClose.length > 0) {
                log('SFU', `Unsubscribing ${midsToClose.length} remote video track(s) for: ${normalizedIds.join(', ')}`);
                await closeTracksByMid(midsToClose, `visibility:${normalizedIds.join(',')}`);
                midsToClose.forEach(mid => midToParticipantMap.delete(mid));
            }

            // Keep remote audio subscribed, but drop video tiles for off-screen participants.
            normalizedIds.forEach(pid => {
                removeParticipantTrack(pid, 'video');
                trackPreferredLayers.delete(pid);

                const mids = remoteParticipantMids.get(pid);
                if (mids) {
                    remoteParticipantMids.set(pid, { ...mids, video: undefined });
                }
            });
        });
    }

    // ========== Per-Participant Quality Scoring ==========
    const participantQualityScores = ref<Map<string, { score: number, packetsLost: number, jitter: number, fps: number }>>(new Map());
    let qualityMonitorInterval: ReturnType<typeof setInterval> | null = null;
    // Store previous stats for delta calculation
    const prevReceiverStats = new Map<string, { packetsReceived: number, packetsLost: number, timestamp: number }>();

    function startQualityMonitor() {
        if (qualityMonitorInterval) return;
        log('QUALITY', 'Starting per-participant quality monitor (5s interval)');

        qualityMonitorInterval = setInterval(async () => {
            if (!sfuPc) return;

            const newScores = new Map(participantQualityScores.value);

            // Group transceivers by participant
            const participantStats = new Map<string, { packetsLost: number, packetsReceived: number, jitter: number, fps: number, trackCount: number }>();

            for (const [transceiver, meta] of sfuTransceiverMap.entries()) {
                if (!transceiver.receiver || transceiver.direction !== 'recvonly') continue;
                const pid = meta.participantId;

                try {
                    const stats = await transceiver.receiver.getStats();
                    let inboundRtp: any = null;

                    stats.forEach((report: any) => {
                        if (report.type === 'inbound-rtp') {
                            inboundRtp = report;
                        }
                    });

                    if (!inboundRtp) continue;

                    const existing = participantStats.get(pid) || { packetsLost: 0, packetsReceived: 0, jitter: 0, fps: 0, trackCount: 0 };

                    // Delta calculation for loss rate
                    const prevKey = `${pid}:${meta.trackName}`;
                    const prev = prevReceiverStats.get(prevKey);
                    const currentReceived = inboundRtp.packetsReceived || 0;
                    const currentLost = inboundRtp.packetsLost || 0;

                    if (prev) {
                        const deltaReceived = currentReceived - prev.packetsReceived;
                        const deltaLost = currentLost - prev.packetsLost;
                        existing.packetsReceived += deltaReceived;
                        existing.packetsLost += deltaLost;
                    }

                    prevReceiverStats.set(prevKey, {
                        packetsReceived: currentReceived,
                        packetsLost: currentLost,
                        timestamp: Date.now()
                    });

                    // Jitter (take worst across tracks)
                    if (inboundRtp.jitter && inboundRtp.jitter > existing.jitter) {
                        existing.jitter = inboundRtp.jitter;
                    }

                    // FPS (video only)
                    if (meta.trackName === 'video' && inboundRtp.framesPerSecond) {
                        existing.fps = inboundRtp.framesPerSecond;
                    }

                    existing.trackCount++;
                    participantStats.set(pid, existing);
                } catch (e) {
                    // getStats can fail for ended transceivers
                }
            }

            // Compute scores
            for (const [pid, stats] of participantStats.entries()) {
                const totalPackets = stats.packetsReceived + stats.packetsLost;
                const lossRate = totalPackets > 0 ? stats.packetsLost / totalPackets : 0;

                // Score: 5 (excellent) → 1 (critical)
                let score = 5;
                if (lossRate > 0.15) score = 1;       // >15% loss = critical
                else if (lossRate > 0.08) score = 2;   // >8% loss = poor
                else if (lossRate > 0.03) score = 3;   // >3% loss = fair
                else if (lossRate > 0.01) score = 4;   // >1% loss = good

                // Penalize high jitter
                if (stats.jitter > 0.1) score = Math.min(score, 2);
                else if (stats.jitter > 0.05) score = Math.min(score, 3);

                // Penalize very low FPS (if we have video)
                if (stats.fps > 0 && stats.fps < 5) score = Math.min(score, 2);
                else if (stats.fps > 0 && stats.fps < 15) score = Math.min(score, 3);

                newScores.set(pid, {
                    score,
                    packetsLost: stats.packetsLost,
                    jitter: Math.round(stats.jitter * 1000), // ms
                    fps: Math.round(stats.fps)
                });
            }

            participantQualityScores.value = newScores;
        }, 5000);
    }

    return {
        localStream,
        remoteStreams,
        remoteSfuSessions,
        remoteSfuTracks,
        remotePublications,
        visibleParticipantIds,
        sfuIceState,
        sfuConnectionState,
        sfuSessionId,
        sfuPc: () => sfuPc, // Expose as a getter function since it can be reassigned

        addLocalStream,
        setLocalStream,
        setVisibleParticipants,
        initSFU,
        resetSFUSession,
        pullParticipantTracks,
        applyRemoteMediaState,
        requestMediaInfo,
        rebroadcastToJoiner,
        replaceTrack,
        publishScreenTrack,
        unpublishScreenTrack,
        removeParticipantStreams,
        removeParticipantMainStream,
        removeParticipantTrack,
        cleanup,
        
        // Per-participant quality scoring
        participantQualityScores,
        startQualityMonitor,

        // Simulcast layer switching
        updateSimulcastLayers,
        spotlightedParticipantId
    };
}
