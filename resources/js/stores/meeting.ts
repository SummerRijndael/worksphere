import { defineStore } from 'pinia';
import { ref } from 'vue';
import { meetingService } from '@/services/meeting.service';
import type { Meeting, MeetingParticipant } from '@/types/models';
import { createLogger } from './managers/logger';
import { useAuthStore } from '@/stores/auth';

import { createPresenceManager } from './managers/PresenceManager';
import { createStreamManager } from './managers/StreamManager';
import { createSignalingManager } from './managers/SignalingManager';
import { createLayoutManager } from './managers/LayoutManager';

const log = createLogger('STORE');

export const useMeetingStore = defineStore('meeting', () => {
    // 1. Core State
    const meeting = ref<Meeting | null>(null);
    const localParticipant = ref<MeetingParticipant | null>(null);
    const iceServers = ref<any[]>([]);
    const isDevMode = ref(false);

    // 2. Initialize Sub-Managers
    const layout = createLayoutManager(meeting, localParticipant);
    
    const presence = createPresenceManager(meeting, localParticipant);
    
    const stream = createStreamManager(
        meeting, 
        localParticipant, 
        iceServers,
        (id, isTalking) => {
            presence.setTalking(id, isTalking);
            if (isTalking) layout.setActiveSpeaker(id);
        },
        (audioMid, videoMid, screenMid) => {
            signaling.broadcastSfuMediaReady(audioMid, videoMid, screenMid);
        },
        (err) => {
            log('ERROR', 'SFU Error encountered', err);
        }
    );

    const signaling = createSignalingManager(
        meeting, 
        localParticipant, 
        presence, 
        stream,
        () => {
            // onAdmitted callback: initialize WebRTC when host lets them in
            stream.initSFU(stream.localStream.value);
        }
    );

    // 3. High Level Orchestrator Methods
    async function initializeMeeting(meetingId: string, participantPublicId: string) {
         try {
             log('SYS', `Initializing meeting ${meetingId} for participant ${participantPublicId}`);
             const data = await meetingService.getMeeting(meetingId) as any;
             // MeetingResource has $wrap = null, so the response IS the meeting object
             // with participants nested inside it.
             const participants = data.participants || [];
             meeting.value = data;
             
             // Normalize all incoming participant IDs
             participants.forEach((p: any) => {
                 p.public_id = p.public_id.toLowerCase();
             });

             // Find local participant
             const normalizedParticipantId = participantPublicId.toLowerCase();
             const found = participants.find((p: any) => p.public_id === normalizedParticipantId) || null;
             
             const authStore = useAuthStore();
             const currentPublicId = authStore.user?.public_id || 'Guest';
             const recordUserPublicId = found?.user?.public_id || 'Guest';

             if (found?.user_id && recordUserPublicId !== currentPublicId) {
                 log('SECURITY', 'IDENTITY MISMATCH: Rejecting token.');
                 localParticipant.value = null;
                 throw new Error("Identity Mismatch");
             } else {
                 localParticipant.value = found;
             }

             presence.participants.value = participants;

             // Fetch ICE Servers separately since getMeeting doesn't return them
             const turnCreds = await meetingService.getTurnCredentials(meetingId);
             iceServers.value = turnCreds.ice_servers || [];
             
             // Setup communication channels
             signaling.setupSignaling(meetingId);
             presence.setupEcho(meetingId);
             
             // Note: We do NOT call initSFU here. MeetingRoomView.vue will call
             // addLocalStream() next, which triggers resetSFUSession → initSFU with
             // the actual local stream. Calling it here would cause a double-init.
             if (localParticipant.value?.status === 'admitted') {
                 log('SYS', 'Participant admitted, SFU will start when addLocalStream is called');
             } else {
                 log('SYS', 'Participant in waiting room');
             }
         } catch (e) {
             log('ERROR', 'Failed to initialize meeting', e);
             throw e;
         }
    }

    function cleanup() {
         log('SYS', 'Cleaning up meeting store');
         signaling.leaveSignaling();
         presence.leaveEcho();
         stream.cleanup();
         layout.clearSpotlight();
         meeting.value = null;
         localParticipant.value = null;
    }

    function toggleHand() {
        const pId = localParticipant.value?.public_id;
        if (!pId) return;
        const isRaised = !presence.raisedHands.value.has(pId);
        presence.toggleHandState(pId, isRaised);
        signaling.broadcastHandState(isRaised);
    }

    async function publishScreenTrack(s: MediaStream) {
        const result = await stream.publishScreenTrack(s);
        if (result && result.mid) {
            presence.toggleScreenShareState(localParticipant.value!.public_id, true);
            signaling.broadcastScreenShareState(true, result.mid);
        }
    }

    async function unpublishScreenTrack() {
        await stream.unpublishScreenTrack();
        presence.toggleScreenShareState(localParticipant.value!.public_id, false);
        signaling.broadcastScreenShareState(false);
    }

    function setStream(kind: 'audio' | 'video', track: MediaStreamTrack | null) {
        stream.replaceTrack(kind, track);
    }

    function toggleDevMode() { 
        isDevMode.value = !isDevMode.value; 
    }
    
    // 4. Expose unified API to Vue components
    return {
        // State
        meeting,
        localParticipant,
        isDevMode,
        
        // Presence Manager
        participants: presence.participants,
        allParticipants: presence.allParticipants,
        waitingParticipants: presence.waitingParticipants,
        activeParticipantIds: presence.activeParticipantIds,
        raisedHands: presence.raisedHands,
        screenShares: presence.screenShares,
        talkingParticipants: presence.talkingParticipants,
        mockParticipants: presence.mockParticipants,
        simulatedRole: presence.simulatedRole,
        isHost: presence.isHost,
        
        // Stream Manager
        remoteStreams: stream.remoteStreams,
        localStream: stream.localStream,
        sfuConnectionState: stream.sfuConnectionState,
        sfuIceState: stream.sfuIceState,
        
        // Layout Manager
        pinnedParticipantId: layout.pinnedParticipantId,
        activeSpeakerId: layout.activeSpeakerId,

        // High-level Actions
        initializeMeeting,
        cleanup,
        addLocalStream: stream.addLocalStream,
        setStream,
        toggleHand,
        replaceTrack: stream.replaceTrack,
        publishScreenTrack,
        unpublishScreenTrack,
        
        // Host Action proxies
        admitParticipant: presence.admitParticipant,
        rejectParticipant: presence.rejectParticipant,
        removeParticipant: presence.removeParticipant,
        
        // Layout Action proxies
        setSpotlight: layout.setSpotlight,
        clearSpotlight: layout.clearSpotlight,

        // Dev tool wrappers
        addMockParticipant: presence.addMockParticipant,
        removeMockParticipant: presence.removeMockParticipant,
        resetSimulation: presence.resetSimulation,
        setSimulatedRole: (r: any) => { presence.simulatedRole.value = r; },
        toggleDevMode
    };
});
