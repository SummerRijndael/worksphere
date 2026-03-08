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
            })
            .listenForWhisper('laser-move', (data: any) => {
                // Whisper events don't have the same envelope as broadcast events
                if (data.participant_id) {
                    handleSignal(data.participant_id, 'laser-move', data);
                }
            })
            .listenForWhisper('annotation-update', (data: any) => {
                if (data.participant_id) {
                    handleSignal(data.participant_id, 'annotation-update', data);
                }
            })
            .listen('.MeetingParticipantJoined', (e: any) => {
                const joinedId = e.participant?.public_id?.toLowerCase();
                log('RECV', `Participant joined event: ${joinedId}`, e);
                presenceManager.upsertParticipant(e.participant);
                
                // If they bypassed the lobby (directly admitted), rebroadcast our media
                if (e.participant?.status === 'admitted' && localParticipantRef.value && joinedId && joinedId !== localParticipantRef.value.public_id.toLowerCase()) {
                    streamManager.rebroadcastToJoiner(joinedId);
                }
            })
            .listen('.MeetingParticipantAdmitted', (e: any) => {
                const admitId = e.participant_public_id?.toLowerCase();
                log('RECV', `Participant admitted event: ${admitId}`, e);
                
                // Handle local admission
                if (localParticipantRef.value && admitId === localParticipantRef.value.public_id.toLowerCase()) {
                    log('SIGNAL', 'You have been admitted to the meeting!');
                    // Trigger reactivity by creating a new object
                    localParticipantRef.value = {
                        ...localParticipantRef.value,
                        status: 'admitted'
                    };
                    onAdmittedCallback();
                } else if (admitId) {
                    // Update global presence state for others
                    presenceManager.upsertParticipant({ 
                        public_id: admitId, 
                        status: 'admitted' 
                    });
                    
                    // Re-broadcast so the person who just got admitted knows our MIDs
                    streamManager.rebroadcastToJoiner(admitId);
                }
            })
            .listen('.MeetingPollCreated', async (e: any) => {
                log('RECV', '[Poll] Poll created', e.poll);
                const { useMeetingStore } = await import('@/stores/meeting');
                useMeetingStore().handlePollCreated(e.poll);
            })
            .listen('.MeetingPollVoted', async (e: any) => {
                log('RECV', '[Poll] Vote update', e);
                const { useMeetingStore } = await import('@/stores/meeting');
                useMeetingStore().handlePollVoted(e);
            })
            .listen('.MeetingPollEnded', async (e: any) => {
                log('RECV', '[Poll] Poll ended', e);
                const { useMeetingStore } = await import('@/stores/meeting');
                useMeetingStore().handlePollEnded(e);
            })
            .listen('.MeetingPollDeleted', async (e: any) => {
                log('RECV', '[Poll] Poll deleted', e);
                const { useMeetingStore } = await import('@/stores/meeting');
                useMeetingStore().handlePollDeleted(e.pollId);
            });
            // Note: Laser pointer now goes through MeetingSignal (sendSignal)
            // so MeetingLaserPointerMoved HTTP listener is not needed
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
        const myId = localParticipantRef.value?.public_id?.toLowerCase();
        
        // Ignore own signals (by ID).
        // We also ignore signals from our *own* current SFU session to avoid loopbacks,
        // but only if it's not the generic 'realtime-kit' ID used by all Cloudflare SDK participants.
        const isOwnSession = data?.sessionId &&
                            data.sessionId === streamManager.sfuSessionId.value &&
                            streamManager.sfuSessionId.value !== 'realtime-kit';

        if (!localParticipantRef.value || normalizedSenderId === myId || isOwnSession) {
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
            } else {
                // Explicitly clear remote screen tile/UI immediately.
                streamManager.removeParticipantStreams(`${normalizedSenderId}:screen`);
            }
            return;
        }

        if (type === 'camera-toggle') {
            const enabled = !!data.enabled;
            if (!enabled) {
                // Force-remove stale remote camera track so tile falls back to avatar immediately.
                streamManager.removeParticipantTrack?.(normalizedSenderId, 'video');
            } else {
                // Ask for latest media metadata/tracks when camera turns back on.
                const sessionId = streamManager.remoteSfuSessions.get(normalizedSenderId);
                const mids = streamManager.remoteSfuTracks.get(normalizedSenderId) || {};
                if (sessionId) {
                    streamManager.pullParticipantTracks(
                        normalizedSenderId,
                        sessionId,
                        mids.audioMid,
                        mids.videoMid,
                        mids.screenMid
                    );
                }
            }
            return;
        }

        if (type === 'participant-admitted') {
            const admitId = data.admitted_participant_id?.toLowerCase();
            if (localParticipantRef.value && admitId === localParticipantRef.value.public_id.toLowerCase()) {
                log('SIGNAL', 'You have been admitted to the meeting (via signal)!');
                // Trigger reactivity by creating a new object
                localParticipantRef.value = {
                    ...localParticipantRef.value,
                    status: 'admitted'
                };
                onAdmittedCallback();
            } else if (admitId) {
                // Another participant was admitted
                presenceManager.upsertParticipant({ 
                    public_id: admitId, 
                    status: 'admitted' 
                });
                streamManager.rebroadcastToJoiner(admitId);
            }
            return;
        }
        
        if (type === 'participant-rejected') {
            const targetId = data.rejected_participant_id || data.targetId;
            const normalizedTargetId = targetId?.toLowerCase();
            if (localParticipantRef.value && normalizedTargetId === localParticipantRef.value.public_id.toLowerCase()) {
                 log('SIGNAL', 'You have been rejected from the meeting!');
                 const meetingId = meetingRef.value?.public_id;
                 window.location.href = meetingId ? `/m/${meetingId}` : '/dashboard';
            } else if (normalizedTargetId) {
                 presenceManager.removeParticipant(normalizedTargetId);
            }
            return;
        }

        if (type === 'participant-kicked') {
            log('SIGNAL', 'Participant kicked from the meeting');
            const targetId = data.targetId?.toLowerCase();
            if (localParticipantRef.value && targetId === localParticipantRef.value.public_id.toLowerCase()) {
                 const meetingId = meetingRef.value?.public_id;
                 window.location.href = meetingId ? `/m/${meetingId}` : '/dashboard';
            } else if (targetId) {
                 presenceManager.removeParticipant(targetId);
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

        if (type === 'breakout-started') {
            log('SIGNAL', 'Breakout session started', data);
            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleBreakoutStarted(data);
            return;
        }

        if (type === 'breakout-ended') {
            log('SIGNAL', 'Breakout session ended');
            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleBreakoutEnded();
            return;
        }

        if (type === 'breakout-help-request') {
            log('SIGNAL', 'Breakout help requested', data);
            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleBreakoutHelpRequest(data);
            return;
        }

        if (type === 'breakout-move') {
            log('SIGNAL', 'Breakout move signal', data);
            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleBreakoutMove(data);
            return;
        }

        if (type === 'breakout-timer-updated') {
            log('SIGNAL', 'Breakout timer updated', data);
            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleBreakoutTimerUpdated(data);
            return;
        }

        if (type === 'breakout-activity') {
            log('SIGNAL', 'Breakout activity', data);
            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleBreakoutActivity(data);
            return;
        }

        if (type === 'recording-started') {
            log('SIGNAL', 'Recording started signal received', data);
            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleRecordingStarted(data);
            return;
        }

        if (type === 'recording-stopped') {
            log('SIGNAL', 'Recording stopped signal received', data);
            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleRecordingStopped(data);
            return;
        }

        if (type === 'request-media-info') {
            log('SIGNAL', `Participant ${normalizedSenderId} requested our media info`);
            if (streamManager.rebroadcastToJoiner) {
                streamManager.rebroadcastToJoiner(normalizedSenderId);
            }
            return;
        }

        if (type === 'participant-waiting') {
            log('SIGNAL', 'New participant in waiting room', data);
            const { toast } = await import('vue-sonner');
            toast.info(`🔔 ${data.display_name} is waiting to join`, {
                action: {
                    label: 'View',
                    onClick: async () => {
                        // Open participants panel if available
                        const { useMeetingStore } = await import('@/stores/meeting');
                        const meetingStore = useMeetingStore();
                        meetingStore.showParticipantsPanel = true;
                    }
                }
            });
            return;
        }

        if (type === 'reaction') {
            log('SIGNAL', `Received reaction from ${senderId}: ${data.emoji}`);
            const { useMeetingStore } = await import('@/stores/meeting');
            const meetingStore = useMeetingStore();
            // senderId comes from the outer MeetingSignal envelope — always correct
            // Use normalized ID for consistency
            meetingStore.receiveReaction({
                publicId: normalizedSenderId,
                emoji: data.emoji
            });
            return;
        }

        if (type === 'laser-move') {
            // Already checked at top of handleSignal, but being explicit
            if (localParticipantRef.value && senderId === localParticipantRef.value.public_id) return;

            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleLaserMove({
                participant_id: normalizedSenderId,
                target_participant_id: data.target_participant_id,
                x: data.x,
                y: data.y,
            });
            return;
        }

        if (type === 'laser-mode-changed') {
            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleLaserModeChanged(data.mode);
            return;
        }

        if (type === 'annotation-update') {
            const { useMeetingStore } = await import('@/stores/meeting');
            useMeetingStore().handleAnnotationUpdate(data);
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
            
            // SECURITY/OPTIMIZATION: Only pull media if we are in the same room context
            const localParticipant = localParticipantRef.value;
            const myRoomId = (meetingRef.value as any)?.currentRoomId || null; // Fallback to store if available or passed via context
            
            // Extract sender's room from the signal if provided
            const senderRoomId = data.current_room_id !== undefined ? (data.current_room_id === null ? null : String(data.current_room_id)) : null;

            // Sync the sender's room state in our presence manager
            if (senderRoomId !== undefined) {
                presenceManager.upsertParticipant({
                    public_id: normalizedSenderId,
                    current_room_id: senderRoomId
                });
            }

            // Room check logic
            const { useMeetingStore } = await import('@/stores/meeting');
            const meetingStore = useMeetingStore();
            const myCurrentRoom = meetingStore.currentRoomId ? String(meetingStore.currentRoomId) : null;
            
            // Fallback: If the signal doesn't have a room ID, check what we know about this participant
            const knownParticipant = presenceManager.participants.value.find(p => p.public_id === normalizedSenderId);
            const effectiveSenderRoom = (senderRoomId !== null) ? senderRoomId : (knownParticipant?.current_room_id || null);

            if (effectiveSenderRoom !== myCurrentRoom) {
                log('SIGNAL', `Ignoring media-ready from ${normalizedSenderId}: participant is in a different room (${effectiveSenderRoom} vs ${myCurrentRoom}).`);
                return;
            }

            const oldSessionId = streamManager.remoteSfuSessions.get(normalizedSenderId);
            streamManager.remoteSfuSessions.set(normalizedSenderId, sessionId);
            
            // Merge MIDs for reconnection resilience
            // CRITICAL: If the sessionId changed, existing MIDs are for a dead session. Clear them.
            let existingTracks = streamManager.remoteSfuTracks.get(normalizedSenderId) || {};
            if (oldSessionId && oldSessionId !== sessionId) {
                log('SIGNAL', `Session ID changed for ${normalizedSenderId} (${oldSessionId} -> ${sessionId}). Clearing stale MIDs.`);
                existingTracks = {};
            }

            const updatedTracks = {
                ...existingTracks,
                ...(audioMid !== undefined ? { audioMid } : {}),
                ...(videoMid !== undefined ? { videoMid } : {}),
                ...(screenMid !== undefined ? { screenMid } : {}),
            };
            streamManager.remoteSfuTracks.set(normalizedSenderId, updatedTracks);

            // IMPORTANT: null means explicit OFF from sender, clear stale tiles/tracks now.
            if (videoMid !== undefined && !updatedTracks.videoMid) {
                streamManager.removeParticipantTrack?.(normalizedSenderId, 'video');
            }
            if (audioMid !== undefined && !updatedTracks.audioMid) {
                streamManager.removeParticipantTrack?.(normalizedSenderId, 'audio');
            }
            if (screenMid !== undefined && !updatedTracks.screenMid) {
                presenceManager.toggleScreenShareState(normalizedSenderId, false);
                streamManager.removeParticipantStreams(`${normalizedSenderId}:screen`);
            }
            
            // Late joiner sync for screenshares
            if (screenMid || updatedTracks.screenMid) {
                 presenceManager.toggleScreenShareState(normalizedSenderId, true);
            }
            
            // ONLY pull if we actually have something to pull
            if (updatedTracks.audioMid || updatedTracks.videoMid || updatedTracks.screenMid) {
                // Pass the updated known MIDs down to the pull function, so it doesn't rely solely on the signal payload
                streamManager.pullParticipantTracks(normalizedSenderId, sessionId, updatedTracks.audioMid, updatedTracks.videoMid, updatedTracks.screenMid);
            } else {
                log('SIGNAL', `Ignoring media-ready from ${normalizedSenderId}: no track MIDs provided yet.`);
            }
        }
    }

    // --- Broadcast senders ---
    async function sendSignal(type: string, data: any) {
        if (!meetingRef.value || !localParticipantRef.value) return;
        
        // High-frequency events use whispers to avoid rate limits
        if (type === 'laser-move') {
            presenceManager.whisper('laser-move', {
                ...data,
                participant_id: localParticipantRef.value.public_id,
                target_participant_id: data.target_participant_id
            });
            return;
        }

        if (type === 'annotation-update') {
            presenceManager.whisper('annotation-update', {
                ...data,
                participant_id: localParticipantRef.value.public_id
            });
            return;
        }

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

    function broadcastSfuMediaReady(audioMid?: string, videoMid?: string, screenMid?: string, roomId?: string | null) {
        sendSignal('signal', {
            type: 'sfu-media-ready',
            sessionId: streamManager.sfuSessionId.value,
            // Use null (not undefined) so receivers can clear stale track state.
            audioMid: audioMid ?? null,
            videoMid: videoMid ?? null,
            screenMid: screenMid ?? null,
            current_room_id: roomId !== undefined ? roomId : null
        });
    }

    function broadcastRequestMediaInfo() {
        log('SIGNAL', 'Broadcasting proactive media info request to all participants');
        sendSignal('request-media-info', {});
    }

    return {
        setupSignaling,
        leaveSignaling,
        sendSignal,
        broadcastHandState,
        broadcastScreenShareState,
        broadcastSfuMediaReady,
        broadcastRequestMediaInfo
    };
}
