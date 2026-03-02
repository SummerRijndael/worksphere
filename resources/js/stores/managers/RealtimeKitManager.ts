import { ref, type Ref } from 'vue';
import RTKClient from '@cloudflare/realtimekit';
import { createLogger } from './logger';
import { meetingService } from '@/services/meeting.service';
import type { Meeting, MeetingParticipant } from '@/types/models';

const log = createLogger('RealtimeKit');

export function createRealtimeKitManager(
    meetingRef: Ref<Meeting | null>,
    localParticipantRef: Ref<MeetingParticipant | null>,
    emitTalkingState: (id: string, talking: boolean) => void,
    onScreenShareToggle: (id: string, sharing: boolean) => void,
    onSfuError: (err: any) => void
) {
    // Media State (Mirrors StreamManager interface)
    const localStream = ref<MediaStream | null>(null);
    const remoteStreams = ref<Map<string, MediaStream>>(new Map());
    
    let cfMeeting: any = null;
    const isInitializing = ref(false);
    const isSessionActive = ref(false);

    /**
     * Join the Realtime Kit meeting using a JWT token from our backend.
     */
    async function initSDK(token: string, initialStream: MediaStream | null, isRetrying = false) {
        if (cfMeeting && !isRetrying) return;
        
        isInitializing.value = true;
        log('SDK', isRetrying ? 'Retrying Realtime Kit SDK...' : 'Initializing Realtime Kit SDK...');

        try {
            log('SDK', 'Connecting to Cloudflare Realtime...', { tokenLength: token?.length });

            // 1. Initialize the client with the auth token
            cfMeeting = await RTKClient.init({
                authToken: token,
                defaults: {
                    video: false,
                    audio: false,
                },
            });

            // 2. Setup event listeners
            setupEventListeners();

            // 3. Join the room
            await cfMeeting.joinRoom();

            // 4. Force ACTIVE_GRID view mode to tell the SDK to subscribe to active speakers
            if (typeof cfMeeting.participants.setViewMode === 'function') {
                log('SDK', 'Setting view mode to ACTIVE_GRID');
                await cfMeeting.participants.setViewMode('ACTIVE_GRID');
                await cfMeeting.participants.setPage(0);
            }

            // 5. Handle initial media if provided
            if (initialStream) {
                log('SDK', 'Publishing initial local stream after join');
                await addLocalStream(initialStream);
            }

            isSessionActive.value = true;
            log('SDK', 'Successfully joined Realtime Kit session and marked as active');
        } catch (e: any) {
            log('ERROR', 'Failed to init Realtime Kit SDK', e);
            
            // Handle transient timeout (ClientError 0002)
            if (e.code === '0002' && !isRetrying) {
                log('SDK', 'Detected session timeout (0002). Retrying in 2 seconds...');
                setTimeout(() => {
                    cfMeeting = null; // Reset and try again
                    initSDK(token, initialStream, true);
                }, 2000);
                return;
            }

            onSFUError(e);
        } finally {
            if (!isRetrying) isInitializing.value = false;
        }
    }

    function setupEventListeners() {
        if (!cfMeeting) return;

        // Remote participant joined
        cfMeeting.participants.joined.on('participantJoined', (participant: any) => {
            log('PARTICIPANT', `Remote participant joined: ${participant.id}`);
            setupParticipantListeners(participant);
        });

        // Current participants (for those already in when we join)
        cfMeeting.participants.joined.toArray().forEach((participant: any) => {
            setupParticipantListeners(participant);
        });

        // Remote participant left
        cfMeeting.participants.joined.on('participantLeft', (participant: any) => {
            log('PARTICIPANT', `Remote participant left: ${participant.id}`);
            removeParticipantStreams(participant.id);
        });

        // Talking state (Active Speaker)
        cfMeeting.participants.active.on('activeSpeakerChanged', (participant: any) => {
            if (participant) {
                emitTalkingState(participant.id.toLowerCase(), true);
            }
        });
    }

    function setupParticipantListeners(participant: any) {
        const resolvePid = (p: any, isScreen = false) => {
            const baseId = (p.customParticipantId || p.id).toLowerCase();
            return isScreen ? `${baseId}:screen` : baseId;
        };

        // Handle initial tracks if already available
        if (participant.videoEnabled) handleRemoteTrack(resolvePid(participant), participant.videoTrack, 'video');
        if (participant.audioEnabled) handleRemoteTrack(resolvePid(participant), participant.audioTrack, 'audio');
        if (participant.screenShareEnabled && participant.screenShareTracks) {
            handleRemoteTrack(resolvePid(participant, true), participant.screenShareTracks.video, 'screen-video');
            handleRemoteTrack(resolvePid(participant, true), participant.screenShareTracks.audio, 'screen-audio');
        }

        // Listen for Video updates (Webcam)
        participant.on('videoUpdate', (payload: any) => {
            log('TRACK', `Remote videoUpdate from ${participant.id}`, { enabled: payload.videoEnabled });
            if (payload.videoEnabled && payload.videoTrack) {
                handleRemoteTrack(resolvePid(participant), payload.videoTrack, 'video');
            } else if (!payload.videoEnabled && !participant.audioEnabled) {
                // If both are disabled, safely clean up (or let audio keep the stream alive)
                log('TRACK', `Remote video disabled for ${participant.id}`);
            }
        });

        // Listen for Audio updates (Mic)
        participant.on('audioUpdate', (payload: any) => {
            log('TRACK', `Remote audioUpdate from ${participant.id}`, { enabled: payload.audioEnabled });
            if (payload.audioEnabled && payload.audioTrack) {
                handleRemoteTrack(resolvePid(participant), payload.audioTrack, 'audio');
            }
        });

        // Listen for Screenshare updates
        participant.on('screenShareUpdate', (payload: any) => {
            log('TRACK', `Remote screenShareUpdate from ${participant.id}`, { enabled: payload.screenShareEnabled });
            const screenPid = resolvePid(participant, true);
            const basePid = resolvePid(participant);

            if (payload.screenShareEnabled && payload.screenShareTracks) {
                if (payload.screenShareTracks.video) handleRemoteTrack(screenPid, payload.screenShareTracks.video, 'screen-video');
                if (payload.screenShareTracks.audio) handleRemoteTrack(screenPid, payload.screenShareTracks.audio, 'screen-audio');
                
                // Notify PresenceManager to update UI state
                onScreenShareToggle(basePid, true);
            } else {
                removeParticipantStreams(screenPid);
                onScreenShareToggle(basePid, false);
            }
        });
    }

    function handleRemoteTrack(pid: string, track: MediaStreamTrack | null, trackKind?: string) {
        if (!track) return;
        
        log('TRACK', `Handling remote track: ${track.kind} (${track.id}) for ${pid}`, { trackKind });

        const existingStream = remoteStreams.value.get(pid);

        if (existingStream) {
            // Check if track already exists (avoid duplicates)
            const tracks = existingStream.getTracks();
            const sameKindTrack = tracks.find(t => t.kind === track.kind);
            
            if (sameKindTrack && sameKindTrack.id !== track.id) {
                log('TRACK', `Replacing existing ${track.kind} track for ${pid}`);
                existingStream.removeTrack(sameKindTrack);
                existingStream.addTrack(track);
            } else if (!sameKindTrack) {
                log('TRACK', `Adding ${track.kind} track to existing stream for ${pid}`);
                existingStream.addTrack(track);
            }
        } else {
            log('TRACK', `Creating new MediaStream for ${pid} with ${track.kind} track`);
            const newStream = new MediaStream([track]);
            const newMap = new Map(remoteStreams.value);
            newMap.set(pid, newStream);
            remoteStreams.value = newMap;
        }
    }

    async function addLocalStream(stream: MediaStream | null) {
        if (!cfMeeting || !stream) return;
        
        localStream.value = stream;
        const videoTrack = stream.getVideoTracks()[0];
        const audioTrack = stream.getAudioTracks()[0];

        // Only publish tracks during initial join if they are actively enabled by the user in the lobby.
        // This prevents the SDK from automatically turning on a hardware camera that was toggled off.
        if (audioTrack && audioTrack.enabled) {
            log('SDK', 'Auto-publishing enabled local audio track');
            await cfMeeting.self.enableAudio(audioTrack);
        }
        if (videoTrack && videoTrack.enabled) {
            log('SDK', 'Auto-publishing enabled local video track');
            await cfMeeting.self.enableVideo(videoTrack);
        }
    }

    async function replaceTrack(kind: 'audio' | 'video', newTrack: MediaStreamTrack | null) {
        if (!cfMeeting) return;

        log('MEDIA', `Replacing ${kind} track`, { hasNewTrack: !!newTrack });

        if (kind === 'audio') {
            if (newTrack) {
                await cfMeeting.self.enableAudio(newTrack);
            } else {
                await cfMeeting.self.disableAudio();
            }
        } else {
            if (newTrack) {
                await cfMeeting.self.enableVideo(newTrack);
            } else {
                await cfMeeting.self.disableVideo();
            }
        }
    }

    async function publishScreenTrack(screenStream?: MediaStream) {
        if (!cfMeeting || !isSessionActive.value) {
            log('ERROR', 'Cannot enable screenshare: SDK not joined or inactive');
            return null;
        }
        log('MEDIA', 'Publishing screen share via SDK');
        
        try {
            if (screenStream) {
                await cfMeeting.self.enableScreenShare(screenStream);
            } else {
                await cfMeeting.self.enableScreenShare();
            }

            log('MEDIA', 'Screen share published successfully');

            // Obtain the track that was just enabled 
            const track = cfMeeting.self.screenShareTracks?.video;
            const stream = track ? new MediaStream([track]) : null;

            // Map it locally for UI consistency (local user sees their own screen tile)
            if (stream && localParticipantRef.value) {
                const pid = localParticipantRef.value.public_id.toLowerCase();
                const newMap = new Map(remoteStreams.value);
                newMap.set(`${pid}:screen`, stream);
                remoteStreams.value = newMap;
                log('MEDIA', `Local screenshare stream mapped to ${pid}:screen`);
            } else {
                log('ERROR', 'No screen share track was returned from the SDK.');
            }

            return { mid: 'screen', stream };
        } catch (e) {
            log('ERROR', 'Failed to publish screenshare', e);
            throw e;
        }
    }

    async function unpublishScreenTrack() {
        if (!cfMeeting) return;
        log('MEDIA', 'Unpublishing screen share via SDK');
        
        await cfMeeting.self.disableScreenShare();

        if (localParticipantRef.value) {
            const pid = localParticipantRef.value.public_id.toLowerCase();
            removeParticipantStreams(`${pid}:screen`);
        }
    }

    function removeParticipantStreams(pid: string) {
        const id = pid.toLowerCase();
        if (remoteStreams.value.has(id)) {
            const newMap = new Map(remoteStreams.value);
            newMap.delete(id);
            remoteStreams.value = newMap;
        }
    }

    function setLocalStream(stream: MediaStream | null) {
        localStream.value = stream;
    }

    function cleanup() {
        if (cfMeeting) {
            cfMeeting.leaveRoom();
            cfMeeting = null;
        }
        remoteStreams.value.clear();
    }

    return {
        // State
        localStream,
        remoteStreams,
        sfuConnectionState: ref('connected'),
        sfuSessionId: ref('realtime-kit'),

        // Actions
        initSDK,
        addLocalStream,
        setLocalStream,
        replaceTrack,
        publishScreenTrack,
        unpublishScreenTrack,
        removeParticipantStreams,
        cleanup,
        
        // Legacy SFU compatibility mocks
        sfuPc: () => null,
        sfuIceState: ref('connected'),
        resetSFUSession: async () => {},
        pullParticipantTracks: async () => {},
        rebroadcastToJoiner: (joinerPublicId: string) => {
            if (!cfMeeting || !localParticipantRef.value || !meetingRef.value) return;

            // 1. Re-broadcast current screenshare state if we are the ones sharing
            const hasScreenTrack = !!cfMeeting.self.screenShareTracks?.video;
            if (hasScreenTrack) {
                log('SIGNAL', `Re-broadcasting screen share to new joiner ${joinerPublicId}`);
                meetingService.sendSignal(meetingRef.value.public_id, {
                    sender_participant_public_id: localParticipantRef.value.public_id,
                    signal_type: 'screen-share-toggle',
                    signal_data: { sharing: true, mid: 'screen' },
                    target_participant_public_id: joinerPublicId,
                }).catch((e) => log('ERROR', 'Failed to rebroadcast screenshare', e));
            }

            // 2. The Cloudflare SDK handles remote track events automatically for the joining client,
            // so we don't need a separate 'sfu-media-ready' equivalent like the legacy SFU.
            // The joiner will receive 'participantJoined' -> 'videoUpdate' immediately upon connecting.
        },
    };
}
