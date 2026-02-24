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
    const chatMessages = ref<any[]>([]);
    const isLocked = ref(false);

    interface ReactionEvent {
        id: string;
        publicId: string;
        emoji: string;
        timestamp: number;
    }
    const activeReactions = ref<ReactionEvent[]>([]);

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
             isLocked.value = !!data.is_locked;
             
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

    async function muteParticipant(publicId: string) {
        if (!meeting.value || !presence.isHost.value) return;

        // Skip backend API call for mock dev participants
        if (!publicId.startsWith('mock-')) {
            try {
                await meetingService.muteParticipant(meeting.value.public_id, publicId);
            } catch (e) {
                log('ERROR', 'Failed to mute participant', e);
                return;
            }
        }

        const p = presence.allParticipants.value.find(x => x.public_id === publicId);
        if (p) p.is_muted_by_host = true;
        signaling.sendSignal('force-mute', { targetId: publicId });
    }

    async function unmuteParticipant(publicId: string) {
        if (!meeting.value || !presence.isHost.value) return;

        if (!publicId.startsWith('mock-')) {
            try {
                await meetingService.unmuteParticipant(meeting.value.public_id, publicId);
            } catch (e) {
                log('ERROR', 'Failed to allow unmute', e);
                return;
            }
        }

        const p = presence.allParticipants.value.find(x => x.public_id === publicId);
        if (p) p.is_muted_by_host = false;
        signaling.sendSignal('allow-unmute', { targetId: publicId });
    }

    async function disableCamera(publicId: string) {
        if (!meeting.value || !presence.isHost.value) return;

        if (!publicId.startsWith('mock-')) {
            try {
                await meetingService.disableCamera(meeting.value.public_id, publicId);
            } catch (e) {
                log('ERROR', 'Failed to disable camera', e);
                return;
            }
        }

        const p = presence.allParticipants.value.find(x => x.public_id === publicId);
        if (p) p.is_camera_disabled_by_host = true;
        signaling.sendSignal('force-camera-off', { targetId: publicId });
    }

    async function allowCamera(publicId: string) {
        if (!meeting.value || !presence.isHost.value) return;

        if (!publicId.startsWith('mock-')) {
            try {
                await meetingService.allowCamera(meeting.value.public_id, publicId);
            } catch (e) {
                log('ERROR', 'Failed to allow camera', e);
                return;
            }
        }

        const p = presence.allParticipants.value.find(x => x.public_id === publicId);
        if (p) p.is_camera_disabled_by_host = false;
        signaling.sendSignal('allow-camera', { targetId: publicId });
    }

    async function kickParticipant(publicId: string) {
        if (!meeting.value || !presence.isHost.value) return;

        if (!publicId.startsWith('mock-')) {
            try {
                await meetingService.kickParticipant(meeting.value.public_id, publicId);
            } catch (e) {
                log('ERROR', 'Failed to kick participant', e);
                return;
            }
        } else {
            presence.removeMockParticipant(publicId);
        }

        presence.removeParticipant(publicId);
        signaling.sendSignal('participant-kicked', { targetId: publicId });
    }

    async function promoteParticipant(publicId: string) {
        if (!meeting.value || !presence.isHost.value) return;

        if (!publicId.startsWith('mock-')) {
            try {
                await meetingService.promoteParticipant(meeting.value.public_id, publicId);
            } catch (e) {
                log('ERROR', 'Failed to promote participant', e);
                return;
            }
        }

        const p = presence.allParticipants.value.find(x => x.public_id === publicId);
        if (p) p.role = 'co-host';
        signaling.sendSignal('role-changed', { targetId: publicId, role: 'co-host' });
    }

    async function demoteParticipant(publicId: string) {
        if (!meeting.value || !presence.isHost.value) return;

        if (!publicId.startsWith('mock-')) {
            try {
                await meetingService.demoteParticipant(meeting.value.public_id, publicId);
            } catch (e) {
                log('ERROR', 'Failed to demote participant', e);
                return;
            }
        }

        const p = presence.allParticipants.value.find(x => x.public_id === publicId);
        if (p) p.role = 'participant';
        signaling.sendSignal('role-changed', { targetId: publicId, role: 'participant' });
    }

    async function toggleLock() {
        if (!meeting.value || !localParticipant.value) return;
        
        // Use same host check as isHost computed
        if (!presence.isHost.value) {
            log('ERROR', 'Only the host can lock/unlock the meeting');
            return;
        }

        try {
            const { toast } = await import('vue-sonner');
            if (isLocked.value) {
                await meetingService.unlockMeeting(meeting.value.public_id);
                isLocked.value = false;
                toast.success('🔓 Meeting unlocked', { description: 'New participants can join.' });
            } else {
                await meetingService.lockMeeting(meeting.value.public_id);
                isLocked.value = true;
                toast.success('🔒 Meeting locked', { description: 'No new participants can join.' });
            }
        } catch (e) {
            log('ERROR', 'Failed to toggle meeting lock', e);
            const { toast } = await import('vue-sonner');
            toast.error('Failed to toggle meeting lock');
        }
    }

    async function endMeeting() {
        if (!meeting.value) return;
        try {
            await meetingService.endMeeting(meeting.value.public_id);
            // The signal handler will call handleMeetingEnded for all participants
            // including the host, so we don't need to call it here.
        } catch (e) {
            log('ERROR', 'Failed to end meeting', e);
            // If the API call failed, still handle locally as fallback
            handleMeetingEnded();
        }
    }

    function handleMeetingEnded() {
        // Stop all local tracks
        stream.localStream?.getTracks().forEach(t => t.stop());
        cleanup();
        // Route to home via window since we don't have router in store
        window.location.href = '/';
    }

    // --- Reactions ---

    function sendReaction(emoji: string) {
        if (!localParticipant.value) return;
        
        signaling.sendSignal('reaction', { emoji });
        
        // Optimistically apply local reaction
        receiveReaction({
            publicId: localParticipant.value.public_id,
            emoji
        });
    }

    function receiveReaction(data: { publicId: string, emoji: string }) {
        const reactionId = Math.random().toString(36).substring(2, 9);
        const reaction = {
            id: reactionId,
            publicId: data.publicId,
            emoji: data.emoji,
            timestamp: Date.now()
        };
        
        activeReactions.value.push(reaction);
        
        // Auto-remove after 4 seconds
        setTimeout(() => {
            const idx = activeReactions.value.findIndex(r => r.id === reactionId);
            if (idx !== -1) {
                activeReactions.value.splice(idx, 1);
            }
        }, 4000);
    }

    // --- Chat ---

    async function fetchMessages() {
        if (!meeting.value) return;
        try {
            const msgs = await meetingService.getMessages(meeting.value.public_id);
            chatMessages.value = msgs;
        } catch (e) {
            log('ERROR', 'Failed to fetch messages', e);
        }
    }

    async function sendMessage(body: string) {
        if (!meeting.value || !localParticipant.value) return;
        try {
            // Note: Optimistic UI updates could be added here, 
            // but we rely on the broadcast to ensure everyone including us gets it.
            await meetingService.sendMessage(meeting.value.public_id, localParticipant.value.public_id, body);
        } catch (e) {
            log('ERROR', 'Failed to send message', e);
            throw e;
        }
    }

    function receiveChatMessage(msg: any) {
        // Prevent strictly duplicate IDs, though usually broadcast logic only sends once
        if (!chatMessages.value.find(m => m.id === msg.id)) {
            chatMessages.value.push(msg);
        }
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
        muteParticipant,
        unmuteParticipant,
        disableCamera,
        allowCamera,
        kickParticipant,
        promoteParticipant,
        demoteParticipant,
        
        // Chat Actions
        chatMessages,
        fetchMessages,
        sendMessage,
        receiveChatMessage,

        // Reactions
        activeReactions,
        sendReaction,
        receiveReaction,

        // Host Actions
        toggleLock, // Exposed toggleLock
        endMeeting,
        handleMeetingEnded,
        
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
