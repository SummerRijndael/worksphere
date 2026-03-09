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
    const networkScore = ref<number>(0); // 0=Good, 1=Fair, 2=Poor
    const networkBitrate = ref<number>(0); // kbps
    const networkPacketLoss = ref<number>(0); // %
    const networkRtt = ref<number>(0); // ms (estimated from jitter when RTT is unavailable)
    let lastScreenshareProfile: string | null = null;

    function normalizeBitrateToKbps(value: number): number {
        if (!Number.isFinite(value) || value <= 0) return 0;
        // SDKs vary: some expose bps, some kbps. Normalize heuristically.
        return value > 100000 ? value / 1000 : value;
    }

    function normalizeJitterToMs(value: number): number {
        if (!Number.isFinite(value) || value <= 0) return 0;
        // WebRTC jitter is usually seconds; convert small values to ms.
        return value <= 2 ? value * 1000 : value;
    }

    const resolveParticipantId = (p: any, isScreen = false) => {
        const baseId = String(p?.customParticipantId || p?.userId || p?.id || '').toLowerCase();
        return isScreen ? `${baseId}:screen` : baseId;
    };

    function syncRemoteParticipantMedia(participant: any) {
        const basePid = resolveParticipantId(participant);
        if (!basePid) return;
        const screenPid = resolveParticipantId(participant, true);

        const videoTrack = participant?.videoEnabled ? (participant?.videoTrack || null) : null;
        const audioTrack = participant?.audioEnabled ? (participant?.audioTrack || null) : null;

        handleRemoteTrack(basePid, videoTrack, 'video');
        handleRemoteTrack(basePid, audioTrack, 'audio');

        if (participant?.screenShareEnabled && participant?.screenShareTracks) {
            handleRemoteTrack(screenPid, participant.screenShareTracks.video || null, 'screen-video');
            handleRemoteTrack(screenPid, participant.screenShareTracks.audio || null, 'screen-audio');
            onScreenShareToggle(basePid, true);
        } else {
            removeParticipantStreams(screenPid);
            onScreenShareToggle(basePid, false);
        }
    }

    function syncLocalTrack(kind: 'audio' | 'video', track: MediaStreamTrack | null) {
        const current = localStream.value ? new MediaStream(localStream.value.getTracks()) : new MediaStream();
        const keepTrackId = track?.id;

        current.getTracks().forEach((t) => {
            if (t.kind === kind && t.id !== keepTrackId) {
                current.removeTrack(t);
                try { t.stop(); } catch {}
            }
        });

        if (!track) {
            current.getTracks().forEach((t) => {
                if (t.kind === kind) {
                    current.removeTrack(t);
                    try { t.stop(); } catch {}
                }
            });
        } else if (!current.getTracks().some((t) => t.id === track.id)) {
            current.addTrack(track);
        }

        // Preserve null when there are truly no local tracks.
        localStream.value = current.getTracks().length > 0 ? current : null;
    }

    function getScreenshareConstraintsByNetwork() {
        // Conservative profiles tuned for stability over sharpness.
        // 0 = good, 1 = fair, 2 = poor
        if (networkScore.value >= 2) {
            return {
                profile: 'poor',
                constraints: {
                    width: { ideal: 960 },
                    height: { ideal: 540 },
                    frameRate: { ideal: 10 }
                }
            };
        }

        if (networkScore.value === 1) {
            return {
                profile: 'fair',
                constraints: {
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                    frameRate: { ideal: 12 }
                }
            };
        }

        return {
            profile: 'good',
            constraints: {
                width: { ideal: 1280 },
                height: { ideal: 720 },
                frameRate: { ideal: 15 }
            }
        };
    }

    async function applyScreenshareConstraints(force = false) {
        if (!cfMeeting || !isSessionActive.value) return;
        if (typeof cfMeeting.self?.updateScreenshareConstraints !== 'function') return;
        if (!cfMeeting.self?.screenShareEnabled) return;

        const { profile, constraints } = getScreenshareConstraintsByNetwork();
        if (!force && lastScreenshareProfile === profile) return;

        try {
            await cfMeeting.self.updateScreenshareConstraints(constraints as any);
            lastScreenshareProfile = profile;
            log('MEDIA', `Applied screenshare constraints profile: ${profile}`, constraints);
        } catch (e) {
            log('ERROR', 'Failed to apply screenshare constraints', e);
        }
    }


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
            removeParticipantStreams(resolveParticipantId(participant));
        });

        // Reconcile streams on map-level refreshes (covers edge cases where video track changes
        // without a clean start/stop toggle event).
        cfMeeting.participants.joined.on('participantsUpdate', () => {
            cfMeeting.participants.joined.toArray().forEach((participant: any) => {
                syncRemoteParticipantMedia(participant);
            });
        });

        cfMeeting.participants.joined.on('participantsCleared', () => {
            remoteStreams.value.forEach((stream) => {
                stream.getTracks().forEach((t) => {
                    try { t.stop(); } catch {}
                });
            });
            remoteStreams.value = new Map();
        });

        // Talking state (Active Speaker)
        cfMeeting.participants.active.on('activeSpeakerChanged', (participant: any) => {
            if (participant) {
                emitTalkingState(resolveParticipantId(participant), true);
            }
        });

        // Local network quality
        cfMeeting.self.on('networkQualityScore', (score: number) => {
            log('NETWORK', `Local network quality score: ${score}`);
            // Cloudflare Score mapping (assuming 0-5 where 5 is best)
            // 5,4 -> 0 (Good)
            // 3   -> 1 (Fair) 
            // 2,1,0 -> 2 (Poor)
            if (score >= 4) networkScore.value = 0;
            else if (score === 3) networkScore.value = 1;
            else networkScore.value = 2;

            // Adapt screen-share quality in-flight when network degrades/improves.
            void applyScreenshareConstraints();
        });

        // Detailed local media quality updates (Realtime Kit mode only).
        cfMeeting.self.on('mediaScoreUpdate', (payload: any) => {
            const scoreStats = payload?.scoreStats;
            if (!scoreStats || payload?.isScreenshare) return;

            const bitrateKbps = normalizeBitrateToKbps(Number(scoreStats.bitrate || 0));
            const packetLoss = Number(scoreStats.packetsLostPercentage || 0);
            const jitterMs = normalizeJitterToMs(Number(scoreStats.jitter || 0));

            if (bitrateKbps >= 0) {
                networkBitrate.value = bitrateKbps;
            }
            if (packetLoss >= 0) {
                networkPacketLoss.value = packetLoss;
            }
            // Realtime Kit currently does not expose RTT directly in this event; use jitter estimate.
            if (jitterMs >= 0) {
                networkRtt.value = jitterMs;
            }
        });

        // Keep local stream in sync even when tracks are stopped externally (device/UI/toolbars).
        cfMeeting.self.on('videoUpdate', (payload: any) => {
            const track = payload?.videoEnabled ? (payload?.videoTrack || cfMeeting?.self?.videoTrack || null) : null;
            syncLocalTrack('video', track);
        });

        cfMeeting.self.on('audioUpdate', (payload: any) => {
            const track = payload?.audioEnabled ? (payload?.audioTrack || cfMeeting?.self?.audioTrack || null) : null;
            syncLocalTrack('audio', track);
        });

        cfMeeting.self.on('screenShareUpdate', (payload: any) => {
            const localId = localParticipantRef.value?.public_id?.toLowerCase();
            if (!localId) return;
            const screenPid = `${localId}:screen`;

            if (payload?.screenShareEnabled && payload?.screenShareTracks?.video) {
                handleRemoteTrack(screenPid, payload.screenShareTracks.video, 'screen-video');
                if (payload.screenShareTracks.audio) {
                    handleRemoteTrack(screenPid, payload.screenShareTracks.audio, 'screen-audio');
                }
                onScreenShareToggle(localId, true);
            } else {
                removeParticipantStreams(screenPid);
                onScreenShareToggle(localId, false);
            }
        });
    }

    function setupParticipantListeners(participant: any) {
        // Handle initial tracks if already available
        syncRemoteParticipantMedia(participant);

        // Listen for Video updates (Webcam)
        participant.on('videoUpdate', (payload: any) => {
            log('TRACK', `Remote videoUpdate from ${participant.id}`, { enabled: payload.videoEnabled });
            syncRemoteParticipantMedia(participant);
        });

        // Listen for Audio updates (Mic)
        participant.on('audioUpdate', (payload: any) => {
            log('TRACK', `Remote audioUpdate from ${participant.id}`, { enabled: payload.audioEnabled });
            syncRemoteParticipantMedia(participant);
        });

        // Listen for Screenshare updates
        participant.on('screenShareUpdate', (payload: any) => {
            log('TRACK', `Remote screenShareUpdate from ${participant.id}`, { enabled: payload.screenShareEnabled });
            syncRemoteParticipantMedia(participant);
        });

        // Some SDK changes update track objects while keeping videoEnabled=true.
        // Wildcard listener ensures we re-bind updated tracks even without explicit toggles.
        participant.on('*', (eventName: string) => {
            if (!eventName) return;
            if (!/video|audio|screen/i.test(eventName)) return;
            syncRemoteParticipantMedia(participant);
        });
    }

    function handleRemoteTrack(pid: string, track: MediaStreamTrack | null, trackKind?: string) {
        log('TRACK', `Handling remote track update: ${trackKind || 'unknown'} for ${pid}`, { hasTrack: !!track });

        const existingStream = remoteStreams.value.get(pid);

        if (!track) {
            // REMOVAL CASE
            if (existingStream) {
                const kind = trackKind?.includes('video') ? 'video' : (trackKind?.includes('audio') ? 'audio' : null);
                if (kind) {
                    existingStream.getTracks().forEach(t => {
                        if (t.kind === kind) {
                            log('TRACK', `Removing ${kind} track from ${pid}`);
                            existingStream.removeTrack(t);
                        }
                    });

                    // If stream is empty, remove it entirely
                    if (existingStream.getTracks().length === 0) {
                        log('TRACK', `Stream empty for ${pid}, removing from map`);
                        const newMap = new Map(remoteStreams.value);
                        newMap.delete(pid);
                        remoteStreams.value = newMap;
                    } else {
                        // Re-trigger reactivity for components watching this specific stream
                        const newMap = new Map(remoteStreams.value);
                        remoteStreams.value = newMap;
                    }
                }
            }
            return;
        }

        // ADDITION / UPDATE CASE
        const endedTrackKind =
            trackKind === 'screen-video' ? 'screen-video' :
            trackKind === 'screen-audio' ? 'screen-audio' :
            track?.kind === 'audio' ? 'audio' : 'video';
        const trackedId = track.id;
        const removeIfStillPresent = () => {
            const current = remoteStreams.value.get(pid);
            const stillPresent = !!current?.getTracks().find((t) => t.id === trackedId);
            if (!stillPresent) return;
            log('TRACK', `Track inactive for ${pid} (${endedTrackKind}) — removing stale track`);
            handleRemoteTrack(pid, null, endedTrackKind);
        };
        track.onended = () => {
            removeIfStillPresent();
        };
        track.onmute = () => {
            // Don't destroy on transient mute; browser/SFU renegotiation (e.g. screen-share toggles)
            // can briefly mute tracks and then recover with the same track object.
            const current = remoteStreams.value.get(pid);
            const stillPresent = !!current?.getTracks().find((t) => t.id === trackedId);
            if (stillPresent) {
                remoteStreams.value = new Map(remoteStreams.value);
            }
        };
        track.onunmute = () => {
            const current = remoteStreams.value.get(pid);
            const stillPresent = !!current?.getTracks().find((t) => t.id === trackedId);
            if (stillPresent) {
                remoteStreams.value = new Map(remoteStreams.value);
            }
        };

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
            // Trigger reactivity
            const newMap = new Map(remoteStreams.value);
            remoteStreams.value = newMap;
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
            syncLocalTrack('audio', newTrack);
        } else {
            if (newTrack) {
                const currentPublishedTrackId = cfMeeting.self?.rawVideoTrack?.id || cfMeeting.self?.videoTrack?.id || null;
                const shouldForceRepublish =
                    !!cfMeeting.self?.videoEnabled &&
                    !!currentPublishedTrackId &&
                    currentPublishedTrackId !== newTrack.id;

                if (shouldForceRepublish) {
                    log(
                        'MEDIA',
                        'Forcing video republish so remote participants/recording receive updated processed track.',
                        { previousTrackId: currentPublishedTrackId, nextTrackId: newTrack.id }
                    );
                    await cfMeeting.self.disableVideo();
                }

                await cfMeeting.self.enableVideo(newTrack);
                const publishedRawId = cfMeeting.self?.rawVideoTrack?.id;
                if (publishedRawId && publishedRawId !== newTrack.id) {
                    log(
                        'MEDIA',
                        'Video publish verification mismatch. Retrying one more enableVideo() to pin custom track.',
                        { expected: newTrack.id, actual: publishedRawId }
                    );
                    await cfMeeting.self.enableVideo(newTrack);
                }
            } else {
                await cfMeeting.self.disableVideo();
            }
            syncLocalTrack('video', newTrack);
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

            // Normalize capture profile after share starts (browser defaults can be too heavy).
            await applyScreenshareConstraints(true);

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
        lastScreenshareProfile = null;

        if (localParticipantRef.value) {
            const pid = localParticipantRef.value.public_id.toLowerCase();
            removeParticipantStreams(`${pid}:screen`);
        }
    }

    function removeParticipantStreams(pid: string) {
        const id = pid.toLowerCase();
        const targets = id.endsWith(':screen') ? [id] : [id, `${id}:screen`];
        let mutated = false;
        const newMap = new Map(remoteStreams.value);

        targets.forEach((target) => {
            const stream = newMap.get(target);
            if (!stream) return;
            newMap.delete(target);
            mutated = true;
        });

        if (mutated) {
            remoteStreams.value = newMap;
        }
    }

    function removeParticipantTrack(pid: string, kind: 'audio' | 'video') {
        const id = pid.toLowerCase();
        const stream = remoteStreams.value.get(id);
        if (!stream) return;

        stream.getTracks()
            .filter((t) => t.kind === kind)
            .forEach((t) => {
                stream.removeTrack(t);
            });

        const newMap = new Map(remoteStreams.value);
        if (stream.getTracks().length === 0) {
            newMap.delete(id);
        } else {
            newMap.set(id, stream);
        }
        remoteStreams.value = newMap;
    }

    function setLocalStream(stream: MediaStream | null) {
        localStream.value = stream;
    }

    function cleanup() {
        if (cfMeeting) {
            cfMeeting.leaveRoom();
            cfMeeting = null;
        }
        remoteStreams.value.forEach((s) => {
            s.getTracks().forEach((t) => {
                try { t.stop(); } catch {}
            });
        });
        remoteStreams.value = new Map();
        localStream.value = null;
    }

    return {
        // State
        localStream,
        remoteStreams,
        sfuConnectionState: ref('connected'),
        sfuSessionId: ref('realtime-kit'),
        networkScore,
        networkBitrate,
        networkPacketLoss,
        networkRtt,

        // Actions
        initSDK,
        addLocalStream,
        setLocalStream,
        replaceTrack,
        publishScreenTrack,
        unpublishScreenTrack,
        removeParticipantStreams,
        removeParticipantTrack,
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
