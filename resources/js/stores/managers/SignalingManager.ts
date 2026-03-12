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

function getClientInstanceId(): string {
    const w = window as any;
    if (!w.__wsClientInstanceId) {
        w.__wsClientInstanceId = `wsi-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
    }
    return w.__wsClientInstanceId;
}

export function createSignalingManager(
    meetingRef: Ref<Meeting | null>,
    localParticipantRef: Ref<MeetingParticipant | null>,
    presenceManager: ReturnType<typeof createPresenceManager>,
    streamManager: ReturnType<typeof createStreamManager>,
    onAdmittedCallback: () => void
) {
    let privateChannel: any = null;
    const clientInstanceId = getClientInstanceId();
    let signalSequence = 0;
    let mediaStateVersion = 0;
    const lastMediaInfoRequestAt = new Map<string, number>();
    const participantSignalQueues = new Map<string, Promise<any>>();

    function enqueueParticipantSignal(participantId: string, task: () => Promise<any>) {
        const currentQueue = participantSignalQueues.get(participantId) || Promise.resolve();
        const nextQueue = currentQueue
            .then(() => task())
            .catch((err) => {
                log('ERROR', `Queue task failed for ${participantId}`, err);
            });
        participantSignalQueues.set(participantId, nextQueue);
        return nextQueue;
    }

    function requestMediaInfoDirect(participantId: string) {
        if (!meetingRef.value || !localParticipantRef.value) return;
        meetingService.sendSignal(meetingRef.value.public_id, {
            sender_participant_public_id: localParticipantRef.value.public_id,
            signal_type: 'request-media-info',
            signal_data: {},
            target_participant_public_id: participantId,
        }).catch((e: any) => {
            log('ERROR', `Failed direct media-info request for ${participantId}`, {
                status: e?.response?.status,
                response: e?.response?.data,
                clientInstanceId,
            });
        });
    }

    function requestMediaInfoDebounced(participantId: string, minIntervalMs = 1200) {
        const now = Date.now();
        const last = lastMediaInfoRequestAt.get(participantId) || 0;
        if (now - last < minIntervalMs) return;
        lastMediaInfoRequestAt.set(participantId, now);
        if (typeof (streamManager as any).requestMediaInfo === 'function') {
            (streamManager as any).requestMediaInfo(participantId);
            return;
        }
        log('SIGNAL', `streamManager.requestMediaInfo unavailable, using direct fallback for ${participantId}`);
        requestMediaInfoDirect(participantId);
    }

    function hasFreshRemotePublication(
        participantId: string,
        kind: 'video' | 'screen',
        expectedMid?: string | null
    ) {
        const publication = (streamManager as any).remotePublications?.get?.(participantId);
        if (!publication) return false;
        const currentMid = kind === 'screen' ? publication.screenMid : publication.videoMid;
        if (!currentMid) return false;
        if (expectedMid && currentMid !== expectedMid) return false;
        if (!publication.lastUpdatedAt) return false;
        return (Date.now() - publication.lastUpdatedAt) < 5000;
    }

    function clearRemotePublicationKind(participantId: string, kind: 'video' | 'screen') {
        if (typeof (streamManager as any).applyRemoteMediaState !== 'function') return;
        const publication = (streamManager as any).remotePublications?.get?.(participantId);
        const sessionId = publication?.sessionId;
        if (!sessionId) return;

        try {
            (streamManager as any).applyRemoteMediaState(participantId, {
                sessionId,
                ...(kind === 'video' ? { videoMid: null } : { screenMid: null }),
            });
        } catch (error) {
            log('ERROR', `Failed to clear remote ${kind} publication for ${participantId}`, error);
        }
    }

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

        log('CHANNEL', `Joining presence signaling channel: meeting.${meetingId}`, {
            clientInstanceId,
            localParticipantId: localParticipantRef.value?.public_id || null,
        });
        privateChannel = echoInstance.join(`meeting.${meetingId}`)
            .listen('.MeetingSignal', async (e: any) => {
                const senderDiag = e.signal_data?._diag || null;
                log('RECV', `Received ${e.signal_type} from ${e.sender_participant_public_id}`, {
                    signalData: e.signal_data,
                    senderDiag,
                    receiverClientInstanceId: clientInstanceId,
                });
                enqueueParticipantSignal(e.sender_participant_public_id, () => handleSignal(e.sender_participant_public_id, e.signal_type, e.signal_data));
            })
            .listenForWhisper('laser-move', (data: any) => {
                // Whisper events don't have the same envelope as broadcast events
                if (data.participant_id) {
                    enqueueParticipantSignal(data.participant_id, () => handleSignal(data.participant_id, 'laser-move', data));
                }
            })
            .listenForWhisper('annotation-update', (data: any) => {
                if (data.participant_id) {
                    enqueueParticipantSignal(data.participant_id, () => handleSignal(data.participant_id, 'annotation-update', data));
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
        const senderDiag = data?._diag || null;
        
        // Ignore own signals (by ID).
        const diagSender = String(data?._diag?.sender_participant_public_id || '').toLowerCase();
        const isSelfById = !!(myId && (normalizedSenderId === myId || diagSender === myId));

        // We also ignore signals from our *own* current SFU session to avoid loopbacks,
        // but only if it's not the generic 'realtime-kit' ID used by all Cloudflare SDK participants.
        const isOwnSession = !!(data?.sessionId &&
                            data.sessionId === streamManager.sfuSessionId.value &&
                            streamManager.sfuSessionId.value !== 'realtime-kit');

        if (!localParticipantRef.value || isSelfById || isOwnSession) {
            // Only log if it's actually us, to avoid noise from early signals before we have localParticipantRef
            if (localParticipantRef.value && (isSelfById || isOwnSession)) {
                log('DEBUG', `Ignoring loopback signal type=${type}`, { normalizedSenderId, diagSender, myId, isOwnSession });
            }
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

        if (type === 'force-stop-screen-share') {
            const targetId = String(data?.targetId || '').toLowerCase();
            if (!targetId || !myId || targetId !== myId) return;

            const senderParticipant = presenceManager.participants.value.find(
                (p: any) => p.public_id === normalizedSenderId
            );
            const senderIsModerator =
                senderParticipant?.role === 'host' || senderParticipant?.role === 'co-host';

            if (!senderIsModerator) {
                log('SIGNAL', `Ignoring force-stop-screen-share from non-moderator ${normalizedSenderId}`);
                return;
            }

            log('SIGNAL', 'Received forced stop for local screen share');
            try {
                await streamManager.unpublishScreenTrack();
            } catch (e) {
                log('ERROR', 'Failed to unpublish local screen share after force-stop', e);
            } finally {
                presenceManager.toggleScreenShareState(myId, false);
                sendSignal('screen-share-toggle', { sharing: false });
            }
            return;
        }

        if (type === 'screen-share-toggle') {
            const { sharing, mid } = data;

            // Single active sharer policy:
            // If another participant starts sharing while we are sharing, stop ours.
            if (sharing && myId && normalizedSenderId !== myId && presenceManager.screenShares.value.has(myId)) {
                log('SIGNAL', `Another participant (${normalizedSenderId}) started sharing; stopping local share`);
                try {
                    await streamManager.unpublishScreenTrack();
                } catch (e) {
                    log('ERROR', 'Failed to stop local share after remote sharer started', e);
                } finally {
                    presenceManager.toggleScreenShareState(myId, false);
                    sendSignal('screen-share-toggle', { sharing: false });
                }
            }

            presenceManager.toggleScreenShareState(normalizedSenderId, sharing);
            
            if (sharing) {
                const cachedPublication = streamManager.remotePublications?.get?.(normalizedSenderId);
                const hasLiveRemoteScreen = !!streamManager.remoteStreams.value
                    .get(`${normalizedSenderId}:screen`)
                    ?.getVideoTracks()
                    .some((track: MediaStreamTrack) => track.readyState === 'live');

                // Avoid immediate blind pulls on toggle; request fresh media info first
                // so we only pull with authoritative mids/session from sfu-media-ready.
                if (hasFreshRemotePublication(normalizedSenderId, 'screen', mid)) {
                    if (!hasLiveRemoteScreen && cachedPublication?.sessionId && cachedPublication.screenMid) {
                        log('SIGNAL', `Screen share toggled ON by ${normalizedSenderId}; publication is fresh but no live remote screen is attached, forcing repair pull`, {
                            sessionId: cachedPublication.sessionId,
                            screenMid: cachedPublication.screenMid,
                        });
                        streamManager.pullParticipantTracks(
                            normalizedSenderId,
                            cachedPublication.sessionId,
                            undefined,
                            undefined,
                            cachedPublication.screenMid ?? undefined
                        );
                    } else {
                        log('SIGNAL', `Screen share toggled ON by ${normalizedSenderId}, but remote screen state is already fresh`, { mid });
                    }
                } else {
                    log('SIGNAL', `Screen share toggled ON by ${normalizedSenderId}; requesting fresh media info`, { mid });
                    requestMediaInfoDebounced(normalizedSenderId);
                }
            } else {
                // Explicitly clear remote screen tile/UI immediately.
                streamManager.removeParticipantStreams(`${normalizedSenderId}:screen`);
                // Do not rely on stale publication metadata; clear the published screen MID now.
                clearRemotePublicationKind(normalizedSenderId, 'screen');
            }
            return;
        }

        if (type === 'camera-toggle') {
            const enabled = !!data.enabled;
            if (!enabled) {
                presenceManager.setCameraState(normalizedSenderId, false);
                // Force-remove stale remote camera track so tile falls back to avatar immediately.
                streamManager.removeParticipantTrack?.(normalizedSenderId, 'video');
                // Also clear cached publication state so stale video MIDs cannot be treated as fresh.
                clearRemotePublicationKind(normalizedSenderId, 'video');
            } else {
                presenceManager.setCameraState(normalizedSenderId, true);

                const cachedPublication = streamManager.remotePublications?.get?.(normalizedSenderId);
                const hasLiveRemoteVideo = !!streamManager.remoteStreams.value
                    .get(normalizedSenderId)
                    ?.getVideoTracks()
                    .some((track: MediaStreamTrack) => track.readyState === 'live');

                // Camera ON events can arrive before mids are stable. Request fresh metadata first,
                // unless we already have a fresh published camera state for this participant.
                if (!hasFreshRemotePublication(normalizedSenderId, 'video')) {
                    requestMediaInfoDebounced(normalizedSenderId);
                } else if (!hasLiveRemoteVideo && cachedPublication?.sessionId && cachedPublication.videoMid) {
                    log('SIGNAL', `Camera toggled ON by ${normalizedSenderId}; publication is fresh but no live remote track is attached, forcing repair pull`, {
                        sessionId: cachedPublication.sessionId,
                        videoMid: cachedPublication.videoMid,
                    });
                    streamManager.pullParticipantTracks(
                        normalizedSenderId,
                        cachedPublication.sessionId,
                        undefined,
                        cachedPublication.videoMid ?? undefined,
                        undefined
                    );
                } else {
                    log('SIGNAL', `Camera toggled ON by ${normalizedSenderId}, but remote video state is already fresh`);
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

        if (type === 'chat-message-pinned' || type === 'chat-message-unpinned') {
            log('SIGNAL', `Received ${type}`, data);
            const payload = data?.message ?? data;
            const { useMeetingStore } = await import('@/stores/meeting');
            const meetingStore = useMeetingStore();
            meetingStore.receiveChatMessage(payload);
            return;
        }

        if (type === 'chat-message-edited' || type === 'chat-message-deleted' || type === 'chat-message-reaction') {
            log('SIGNAL', `Received ${type}`, data);
            const payload = data?.message ?? data;
            const { useMeetingStore } = await import('@/stores/meeting');
            const meetingStore = useMeetingStore();
            meetingStore.receiveChatMessage(payload);
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
            const payloadVersion = Number(data.media_state_version ?? senderDiag?.sequence ?? 0);

            if (!sessionId) {
                log('SIGNAL', `Ignoring media-ready from ${normalizedSenderId}: missing sessionId`, {
                    payload: { audioMid, videoMid, screenMid },
                    senderDiag,
                    receiverClientInstanceId: clientInstanceId,
                });
                return;
            }
            
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
                log('SIGNAL', `Ignoring media-ready from ${normalizedSenderId}: participant is in a different room (${effectiveSenderRoom} vs ${myCurrentRoom}).`, {
                    senderDiag,
                    receiverClientInstanceId: clientInstanceId,
                });
                return;
            }

            const mediaState = streamManager.applyRemoteMediaState(normalizedSenderId, {
                sessionId,
                audioMid,
                videoMid,
                screenMid,
                mediaStateVersion: payloadVersion,
            });

            if (mediaState.status === 'stale') {
                log('SIGNAL', `Ignoring stale media-ready from ${normalizedSenderId}`, {
                    sessionId,
                    payloadVersion,
                    senderDiag,
                    receiverClientInstanceId: clientInstanceId,
                });
                return;
            }

            // IMPORTANT: null means explicit OFF from sender, clear stale tiles/tracks now.
            if (mediaState.explicitClears.video) {
                presenceManager.setCameraState(normalizedSenderId, false);
                streamManager.removeParticipantTrack?.(normalizedSenderId, 'video');
            } else if (mediaState.videoMid) {
                presenceManager.setCameraState(normalizedSenderId, true);
            }
            if (mediaState.explicitClears.audio) {
                streamManager.removeParticipantTrack?.(normalizedSenderId, 'audio');
            }
            if (mediaState.explicitClears.screen) {
                presenceManager.toggleScreenShareState(normalizedSenderId, false);
                streamManager.removeParticipantStreams(`${normalizedSenderId}:screen`);
            }
            
            // Late joiner sync for screenshares
            if (mediaState.screenMid) {
                 presenceManager.toggleScreenShareState(normalizedSenderId, true);
            }
            
            if (mediaState.shouldPull) {
                const requestedAudioMid = mediaState.changedKinds.audio ? (mediaState.audioMid ?? undefined) : undefined;
                const requestedVideoMid = mediaState.changedKinds.video ? (mediaState.videoMid ?? undefined) : undefined;
                const requestedScreenMid = mediaState.changedKinds.screen ? (mediaState.screenMid ?? undefined) : undefined;

                streamManager.pullParticipantTracks(
                    normalizedSenderId,
                    mediaState.sessionId || undefined,
                    requestedAudioMid,
                    requestedVideoMid,
                    requestedScreenMid
                );
            } else if (!mediaState.audioMid && !mediaState.videoMid && !mediaState.screenMid) {
                log('SIGNAL', `Ignoring media-ready from ${normalizedSenderId}: no track MIDs provided yet.`, {
                    sessionId: mediaState.sessionId,
                    payload: { audioMid, videoMid, screenMid },
                    cachedTracks: {
                        audioMid: mediaState.audioMid,
                        videoMid: mediaState.videoMid,
                        screenMid: mediaState.screenMid,
                    },
                    senderRoom: effectiveSenderRoom,
                    myRoom: myCurrentRoom,
                    senderDiag,
                    receiverClientInstanceId: clientInstanceId,
                });
            } else {
                log('SIGNAL', `Remote media state unchanged for ${normalizedSenderId}; skipping duplicate pull`, {
                    sessionId: mediaState.sessionId,
                    mids: {
                        audioMid: mediaState.audioMid,
                        videoMid: mediaState.videoMid,
                        screenMid: mediaState.screenMid,
                    },
                    senderDiag,
                    receiverClientInstanceId: clientInstanceId,
                });
            }
        }
    }

    // --- Broadcast senders ---
    async function sendSignal(type: string, data: any) {
        if (!meetingRef.value || !localParticipantRef.value) return;
        const seq = ++signalSequence;
        const enrichedData = (data && typeof data === 'object')
            ? {
                ...data,
                _diag: {
                    ...(data._diag || {}),
                    client_instance_id: clientInstanceId,
                    sender_participant_public_id: localParticipantRef.value.public_id,
                    sender_sfu_session_id: streamManager.sfuSessionId.value || null,
                    sequence: seq,
                    sent_at_ms: Date.now(),
                },
            }
            : data;
        
        // High-frequency events use whispers to avoid rate limits
        if (type === 'laser-move') {
            presenceManager.whisper('laser-move', {
                ...enrichedData,
                participant_id: localParticipantRef.value.public_id,
                target_participant_id: data?.target_participant_id
            });
            return;
        }

        if (type === 'annotation-update') {
            presenceManager.whisper('annotation-update', {
                ...enrichedData,
                participant_id: localParticipantRef.value.public_id
            });
            return;
        }

        try {
            await meetingService.sendSignal(meetingRef.value.public_id, {
                sender_participant_public_id: localParticipantRef.value.public_id,
                signal_type: type,
                signal_data: enrichedData
            });
            log('SEND', `Sent ${type} signal`, {
                signalData: enrichedData,
                clientInstanceId,
                sequence: seq,
            });
        } catch (e: any) {
            log('ERROR', `Failed to send signal ${type}`, {
                status: e?.response?.status,
                response: e?.response?.data,
                sender: localParticipantRef.value.public_id,
                payload: enrichedData,
                clientInstanceId,
                sequence: seq,
            });
        }
    }

    function broadcastHandState(raised: boolean) {
        sendSignal('hand-toggle', { raised });
    }

    function broadcastScreenShareState(sharing: boolean, mid?: string) {
        sendSignal('screen-share-toggle', { sharing, mid });
    }

    function broadcastSfuMediaReady(audioMid?: string, videoMid?: string, screenMid?: string, roomId?: string | null) {
        mediaStateVersion += 1;
        sendSignal('signal', {
            type: 'sfu-media-ready',
            sessionId: streamManager.sfuSessionId.value,
            media_state_version: mediaStateVersion,
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
