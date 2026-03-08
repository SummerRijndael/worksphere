import { ref, computed, type Ref } from 'vue';
import { createStreamManager } from './StreamManager';
import { createRealtimeKitManager } from './RealtimeKitManager';
import type { Meeting, MeetingParticipant } from '@/types/models';

/**
 * UnifiedMediaManager acts as a multiplexer between the legacy Cloudflare Calls SFU
 * and the new Cloudflare Realtime Kit SDK.
 * 
 * It ensures the MeetingStore and SignalingManager don't need to care WHICH engine
 * is powering the meeting's media.
 */
export function createUnifiedMediaManager(
    meetingRef: Ref<Meeting | null>,
    localParticipantRef: Ref<MeetingParticipant | null>,
    iceServersRef: Ref<any[]>,
    currentRoomIdRef: Ref<string | null>,
    emitTalkingState: (participantId: string, isTalking: boolean) => void,
    onScreenShareToggle: (participantId: string, isSharing: boolean) => void,
    onSfuMediaReady: (audioMid?: string, videoMid?: string, screenMid?: string) => void,
    onSFUError: (err: any) => void
) {
    const legacyManager = createStreamManager(
        meetingRef,
        localParticipantRef,
        iceServersRef,
        currentRoomIdRef,
        emitTalkingState,
        onSfuMediaReady,
        onSFUError
    );

    const sdkManager = createRealtimeKitManager(
        meetingRef,
        localParticipantRef,
        emitTalkingState,
        onScreenShareToggle,
        onSFUError
    );

    // DETERMINING THE ENGINE
    // We use the 'recording_enabled' flag from the backend as the indicator for PRO/SDK sessions.
    const useSDK = computed(() => !!meetingRef.value?.recording_enabled);

    const activeManager = computed(() => useSDK.value ? sdkManager : legacyManager);

    return {
        // Reactive State (proxied to the active manager)
        localStream: computed(() => activeManager.value.localStream.value),
        remoteStreams: computed(() => activeManager.value.remoteStreams.value),
        sfuConnectionState: computed(() => activeManager.value.sfuConnectionState.value),
        sfuIceState: computed(() => activeManager.value.sfuIceState.value),
        sfuSessionId: computed(() => activeManager.value.sfuSessionId.value),
        networkScore: computed(() => (activeManager.value as any).networkScore?.value ?? 0),
        remoteSfuSessions: legacyManager.remoteSfuSessions,
        remoteSfuTracks: legacyManager.remoteSfuTracks,
        
        // Exposed for legacy signaling logic
        sfuPc: () => activeManager.value.sfuPc(),

        // SDK Initialization (Specific to RealtimeKit path)
        initSDK: sdkManager.initSDK,

        // Common Media Actions
        addLocalStream: (stream: MediaStream | null) => activeManager.value.addLocalStream(stream),
        setLocalStream: (stream: MediaStream | null) => activeManager.value.setLocalStream(stream),
        
        initSFU: (stream: MediaStream | null) => {
            if (useSDK.value) return Promise.resolve(); // SDK initializes via initSDK()
            return legacyManager.initSFU(stream);
        },

        resetSFUSession: (stream: MediaStream | null) => 
            activeManager.value.resetSFUSession(stream),

        replaceTrack: (kind: 'audio' | 'video', newTrack: MediaStreamTrack | null) => 
            activeManager.value.replaceTrack(kind, newTrack),

        publishScreenTrack: (stream?: MediaStream) => 
            activeManager.value.publishScreenTrack(stream as any),

        unpublishScreenTrack: () => 
            activeManager.value.unpublishScreenTrack(),

        removeParticipantStreams: (pid: string) => 
            activeManager.value.removeParticipantStreams(pid),

        removeParticipantTrack: (pid: string, kind: 'audio' | 'video') =>
            (activeManager.value as any).removeParticipantTrack?.(pid, kind),

        // Legacy Signaling Logic Proxies (become NOPs in SDK mode)
        rebroadcastToJoiner: (pid: string) => {
            return activeManager.value.rebroadcastToJoiner(pid);
        },

        pullParticipantTracks: (pid: string, sid?: string, a?: string, v?: string, s?: string) => {
            if (useSDK.value) return Promise.resolve();
            return legacyManager.pullParticipantTracks(pid, sid, a, v, s);
        },

        cleanup: () => {
            legacyManager.cleanup();
            sdkManager.cleanup();
        }
    };
}
