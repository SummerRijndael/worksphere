import type { Ref } from 'vue';
import { createLogger } from './logger';
import { meetingService } from '@/services/meeting.service';
import type { Meeting, MeetingParticipant } from '@/types/models';
import Echo from 'laravel-echo';
import { startEcho } from '@/echo';
import type { ReturnType } from 'vue';
import type { createPresenceManager } from './PresenceManager';
import type { createStreamManager } from './StreamManager';

const log = createLogger('SIGNAL');

export function createSignalingManager(
    meetingRef: Ref<Meeting | null>,
    localParticipantRef: Ref<MeetingParticipant | null>,
    presenceManager: ReturnType<typeof createPresenceManager>,
    streamManager: ReturnType<typeof createStreamManager>,
    onAdmittedCallback: () => void
) {
    let privateChannel: any = null;

    function setupSignaling(meetingId: string) {
        if (privateChannel) return;
        
        let echoInstance = (window as any).Echo as Echo;
        if (!echoInstance) {
            log('SYS', 'Laravel Echo not found on window, starting it now...');
            echoInstance = startEcho() as unknown as Echo;
        }
        if (!echoInstance) {
            log('ERROR', 'Failed to initialize Laravel Echo.');
            return;
        }

        log('CHANNEL', `Joining presence signaling channel: meeting.${meetingId}`);
        privateChannel = echoInstance.join(`meeting.${meetingId}`)
            .listen('.MeetingSignal', async (e: any) => {
                log('RECV', `Received ${e.signal_type} from ${e.sender_participant_public_id}`, e.signal_data);
                handleSignal(e.sender_participant_public_id, e.signal_type, e.signal_data);
            });
    }

    function leaveSignaling() {
        if (privateChannel) {
            log('CHANNEL', `Detaching signaling listener`);
            // Don't call echoInstance.leave() — PresenceManager owns the channel lifecycle
            privateChannel = null;
        }
    }

    async function handleSignal(senderId: string, type: string, data: any) {
        const normalizedSenderId = senderId.toLowerCase();
        if (!localParticipantRef.value || normalizedSenderId === localParticipantRef.value.public_id) {
            // Ignore own signals
            return;
        }
        
        if (type === 'hand-toggle') {
            presenceManager.toggleHandState(normalizedSenderId, data.raised);
            return;
        }

        // Ported from CallApp.vue L1782: When a new participant joins,
        // re-broadcast our media info so they can pull our tracks.
        if (type === 'participant-joined') {
            log('SIGNAL', `Participant ${normalizedSenderId} joined, re-broadcasting our media info`);
            streamManager.rebroadcastToJoiner(normalizedSenderId);
            return;
        }

        if (type === 'screen-share-toggle') {
            const { sharing, mid } = data;
            presenceManager.toggleScreenShareState(normalizedSenderId, sharing);
            
            if (sharing) {
                // Proactively pull the new screen track
                const sessionId = streamManager.remoteSfuSessions.get(normalizedSenderId);
                if (sessionId) {
                    log('SIGNAL', `Proactively pulling screen share for ${normalizedSenderId} with MID: ${mid}`);
                    streamManager.pullParticipantTracks(normalizedSenderId, sessionId, undefined, undefined, mid || "true");
                }
            }
            return;
        }

        if (type === 'participant-admitted') {
            if (data.admitted_participant_id === localParticipantRef.value.public_id) {
                log('SIGNAL', 'You have been admitted to the meeting!');
                localParticipantRef.value.status = 'admitted';
                onAdmittedCallback();
            } else {
                // Another participant was admitted
                const p = presenceManager.participants.value.find(x => x.public_id === data.admitted_participant_id);
                if (p) p.status = 'admitted';
            }
            return;
        }
        
        if (type === 'participant-rejected') {
            if (data.rejected_participant_id === localParticipantRef.value.public_id) {
                 log('SIGNAL', 'You have been rejected from the meeting!');
                 window.location.href = '/dashboard';
            } else {
                 presenceManager.removeParticipant(data.rejected_participant_id);
            }
            return;
        }

        if (type === 'participant-kicked') {
            log('SIGNAL', 'Participant kicked from the meeting');
            if (data.targetId === localParticipantRef.value.public_id) {
                 window.location.href = '/dashboard';
            } else {
                 presenceManager.removeParticipant(data.targetId);
            }
            return;
        }

        if (type === 'force-mute') {
            log('SIGNAL', 'Host forced mute');
            if (data.targetId === localParticipantRef.value.public_id) {
                localParticipantRef.value.is_muted_by_host = true;
            } else {
                const p = presenceManager.participants.value.find(x => x.public_id === data.targetId);
                if (p) p.is_muted_by_host = true;
            }
            return;
        }

        if (type === 'allow-unmute') {
            log('SIGNAL', 'Host allowed unmute');
            if (data.targetId === localParticipantRef.value.public_id) {
                localParticipantRef.value.is_muted_by_host = false;
            } else {
                const p = presenceManager.participants.value.find(x => x.public_id === data.targetId);
                if (p) p.is_muted_by_host = false;
            }
            return;
        }

        if (type === 'chat-message') {
            log('SIGNAL', 'Received chat message', data);
            const { useMeetingStore } = await import('@/stores/meeting');
            const meetingStore = useMeetingStore();
            meetingStore.receiveChatMessage(data);
            return;
        }

        if (type === 'meeting-locked') {
            log('SIGNAL', `Meeting lock state changed: ${data.is_locked}`);
            const { useMeetingStore } = await import('@/stores/meeting');
            const meetingStore = useMeetingStore();
            meetingStore.isLocked = data.is_locked;
            // Show toast feedback for lock state change
            const { toast } = await import('vue-sonner');
            if (data.is_locked) {
                toast.info('🔒 Meeting locked — no new participants can join');
            } else {
                toast.info('🔓 Meeting unlocked — new participants can join');
            }
            return;
        }

        if (type === 'meeting-ended') {
            log('SIGNAL', 'Meeting ended by host');
            const { useMeetingStore } = await import('@/stores/meeting');
            const meetingStore = useMeetingStore();
            meetingStore.handleMeetingEnded();
            return;
        }

        if (type === 'reaction') {
            log('SIGNAL', `Received reaction from ${data.sender_participant_public_id}: ${data.emoji}`);
            const { useMeetingStore } = await import('@/stores/meeting');
            const meetingStore = useMeetingStore();
            // In the new MeetingSignal format, sender is in the outer event, or in data
            // data.sender_participant_public_id should be available if we broadcasted it right, 
            // but let's check the MeetingSignal structure: it sends $signalData.
            meetingStore.receiveReaction({ 
                publicId: data.sender_participant_public_id || data.publicId || 'unknown', 
                emoji: data.emoji 
            });
            return;
        }

        if (type === 'role-changed') {
            log('SIGNAL', `Role changed: ${data.targetId} is now ${data.role}`);
            if (data.targetId === localParticipantRef.value.public_id) {
                localParticipantRef.value.role = data.role;
            } else {
                const p = presenceManager.participants.value.find(x => x.public_id === data.targetId);
                if (p) p.role = data.role;
            }
            return;
        }

        if (type === 'force-camera-off') {
            log('SIGNAL', 'Host forced camera off');
            if (data.targetId === localParticipantRef.value.public_id) {
                localParticipantRef.value.is_camera_disabled_by_host = true;
            } else {
                const p = presenceManager.participants.value.find(x => x.public_id === data.targetId);
                if (p) p.is_camera_disabled_by_host = true;
            }
            return;
        }

        if (type === 'allow-camera') {
            log('SIGNAL', 'Host allowed camera');
            if (data.targetId === localParticipantRef.value.public_id) {
                localParticipantRef.value.is_camera_disabled_by_host = false;
            } else {
                const p = presenceManager.participants.value.find(x => x.public_id === data.targetId);
                if (p) p.is_camera_disabled_by_host = false;
            }
            return;
        }

        // SFU Media Signaling (ported from CallApp.vue L1414-1441)
        if (type === 'signal' && data.type === 'sfu-media-ready') {
            const { sessionId, audioMid, videoMid, screenMid } = data;
            streamManager.remoteSfuSessions.set(normalizedSenderId, sessionId);
            
            // Persist MIDs for reconnection resilience (from CallApp.vue L1428-1432)
            if (audioMid || videoMid) {
                streamManager.remoteSfuTracks.set(normalizedSenderId, {
                    audioMid,
                    videoMid,
                });
            }
            
            // Late joiner sync for screenshares
            if (screenMid) {
                 presenceManager.toggleScreenShareState(normalizedSenderId, true);
            }
            
            streamManager.pullParticipantTracks(normalizedSenderId, sessionId, audioMid, videoMid, screenMid);
        }
    }

    // --- Broadcast senders ---
    async function sendSignal(type: string, data: any) {
        if (!meetingRef.value || !localParticipantRef.value) return;
        try {
            await meetingService.sendSignal(meetingRef.value.public_id, {
                sender_participant_public_id: localParticipantRef.value.public_id,
                signal_type: type,
                signal_data: data
            });
            log('SEND', `Sent ${type} signal`, data);
        } catch (e) {
            log('ERROR', `Failed to send signal ${type}`, e);
        }
    }

    function broadcastHandState(raised: boolean) {
        sendSignal('hand-toggle', { raised });
    }

    function broadcastScreenShareState(sharing: boolean, mid?: string) {
        sendSignal('screen-share-toggle', { sharing, mid });
    }

    function broadcastSfuMediaReady(audioMid?: string, videoMid?: string, screenMid?: string) {
        sendSignal('signal', {
            type: 'sfu-media-ready',
            sessionId: streamManager.sfuSessionId.value,
            audioMid, videoMid, screenMid
        });
    }

    return {
        setupSignaling,
        leaveSignaling,
        sendSignal,
        broadcastHandState,
        broadcastScreenShareState,
        broadcastSfuMediaReady
    };
}
