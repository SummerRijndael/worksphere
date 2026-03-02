import { ref, type Ref } from 'vue';
import { createLogger } from './logger';
import { meetingService } from '@/services/meeting.service';
import type { Meeting, MeetingParticipant } from '@/types/models';

const log = createLogger('SFU');

export function createStreamManager(
    meetingRef: Ref<Meeting | null>,
    localParticipantRef: Ref<MeetingParticipant | null>,
    iceServersRef: Ref<any[]>,
    currentRoomIdRef: Ref<string | null>,
    emitTalkingState: (participantId: string, isTalking: boolean) => void,
    onSfuMediaReady: (audioMid?: string, videoMid?: string, screenMid?: string) => void,
    onSFUError: (err: any) => void
) {
    // Media State
    const localStream = ref<MediaStream | null>(null);
    const localScreenStream = ref<MediaStream | null>(null);
    const remoteStreams = ref<Map<string, MediaStream>>(new Map());
    const remoteSfuSessions = new Map<string, string>();
    const remoteSfuTracks = new Map<string, { audioMid?: string; videoMid?: string }>();
    
    // SFU Connection State
    const sfuIceState = ref("new");
    const sfuConnectionState = ref<string>('new');
    const isInitializingSFU = ref(false);
    let sfuPc: RTCPeerConnection | null = null;
    const sfuSessionId = ref<string | null>(null);
    let sfuGeneration = Date.now();
    
    // SFU API specific maps (ported from CallApp.vue)
    const sfuTransceiverMap = new Map<RTCRtpTransceiver, { participantId: string, trackName: string }>();
    const midToParticipantMap = new Map<string, string>();
    const participantPullAttempts = new Map<string, number>();
    const visibleParticipantIds = ref<Set<string>>(new Set());
    // Persist MIDs per participant to help "Sticky MIDs" logic
    const remoteParticipantMids = new Map<string, { audio?: string; video?: string; screen?: string }>();
    
    // Sticky Slots: Maintain a consistent order of participants to keep MIDs stable across resets
    const participantSlots = ref<string[]>([]);
    
    const pendingTrackEvents: { track: MediaStreamTrack, mid: string, transceiver: RTCRtpTransceiver, streams: readonly MediaStream[] }[] = [];
    // Queue for signals that arrive before SFU session is established
    const pendingPullSignals: { participantPublicId: string, remoteSessionId?: string, audioMid?: string, videoMid?: string, screenMid?: string }[] = [];
    
    // Desync Guard (Self-Healing)
    let healthCheckInterval: number | null = null;
    
    // SFU Queue
    let sfuQueue: Promise<void> = Promise.resolve();
    function runInSFUQueue<T>(fn: () => Promise<T>): Promise<T> {
        const result = sfuQueue.then(fn);
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

    // Cloudflare Calls API often returns sessionDescription without a 'type' field.
    // RTCSessionDescription requires a valid type ('answer') for setRemoteDescription.
    function toSdpAnswer(sd: any): RTCSessionDescriptionInit {
        return { type: sd?.type || 'answer', sdp: sd?.sdp || '' };
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
                
                // Attach handler for when the track becomes unmuted (active)
                evt.track.onunmute = handleActive;
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

    function getLocalTrackMids() {
        if (!sfuPc) return { audioMid: undefined, videoMid: undefined, screenMid: undefined };
        const localId = 'self'; // Always use 'self' for local track identification internally
        // Only include MIDs for transceivers that have an ACTIVE sender track
        // Prevents broadcasting empty placeholder MIDs that can't be pulled
        let audioMid: string | undefined;
        let videoMid: string | undefined;
        let screenMid: string | undefined;

        for (const tc of sfuPc.getTransceivers()) {
            if (!tc.mid) continue;
            const assoc = sfuTransceiverMap.get(tc);
            if (!assoc || assoc.participantId !== localId) continue;

            // Only include MID if sender has a real track
            const hasTrack = tc.sender.track && tc.sender.track.readyState === 'live';
            if (assoc.trackName === 'audio' && hasTrack) audioMid = tc.mid;
            else if (assoc.trackName === 'video' && hasTrack) videoMid = tc.mid;
            else if (assoc.trackName === 'screen' && hasTrack) screenMid = tc.mid;
        }

        return { audioMid, videoMid, screenMid };
    }

    function broadcastMediaMids() {
        if (!sfuPc || !localParticipantRef.value) return;
        const { audioMid, videoMid, screenMid } = getLocalTrackMids();
        log('SIGNAL', 'Triggering SFU Media Ready broadcast', { audioMid, videoMid, screenMid });
        onSfuMediaReady(audioMid, videoMid, screenMid);
    }

    // Ported from CallApp.vue L1782-1818: When a new participant joins,
    // re-send our sfu-media-ready signal so late joiners can pull our tracks.
    function rebroadcastToJoiner(joinerPublicId: string) {
        if (!sfuSessionId.value || !sfuPc || !localParticipantRef.value) return;
        const { audioMid, videoMid, screenMid } = getLocalTrackMids();
        log('SIGNAL', `Re-broadcasting media info to new joiner ${joinerPublicId}`, { audioMid, videoMid, screenMid });
        
        meetingService.sendSignal(meetingRef.value!.public_id, {
            sender_participant_public_id: localParticipantRef.value.public_id,
            signal_type: 'signal',
            signal_data: {
                type: 'sfu-media-ready', // CRITICAL: Added type so receiver recognizes this signal
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

    function requestMediaInfo(participantPublicId: string) {
        if (!localParticipantRef.value || !meetingRef.value) return;
        log('SIGNAL', `Requesting media info from ${participantPublicId}`);
        meetingService.sendSignal(meetingRef.value.public_id, {
            sender_participant_public_id: localParticipantRef.value.public_id,
            signal_type: 'request-media-info',
            signal_data: {},
            target_participant_public_id: participantPublicId
        }).catch(() => {});
    }

    function startHealthCheck() {
        if (healthCheckInterval) return;
        log('HEALTH', 'Starting SFU Desync Guard (60s check)');
        healthCheckInterval = window.setInterval(async () => {
            if (!sfuPc || !sfuSessionId.value || !meetingRef.value) return;

            const participants = meetingRef.value.participants || [];
            const localId = localParticipantRef.value?.public_id.toLowerCase();

            for (const p of participants) {
                const pid = p.public_id.toLowerCase();
                if (pid === localId || p.status !== 'admitted') continue;

                const stream = remoteStreams.value.get(pid);
                const sessionId = remoteSfuSessions.get(pid);
                
                // --- ROOM FILTERING ---
                // Only "repair" participants who are in the same room as us.
                // This prevents pulling tracks for people in different breakout rooms.
                const pRoomId = p.current_room_id ? String(p.current_room_id) : null;
                const myRoomId = currentRoomIdRef.value ? String(currentRoomIdRef.value) : null;

                if (pRoomId !== myRoomId) {
                    // If they are in a different room but we still have their stream, clean it up
                    if (stream) {
                        log('HEALTH', `Cleaning up out-of-room stream for ${pid} (${pRoomId} vs ${myRoomId})`);
                        removeParticipantStreams(pid);
                    }
                    continue;
                }

                // Case 1: No session info yet (missed sfu-media-ready)
                if (!sessionId) {
                    log('HEALTH', `No session for ${pid}. Requesting media info.`);
                    requestMediaInfo(pid);
                }
                // Case 2: Have session but no stream or broken stream
                else if (!stream || (stream.getVideoTracks().length === 0 && stream.getAudioTracks().length === 0)) {
                    log('HEALTH', `Broken/Missing stream for ${pid}. Triggering repair pull.`);
                    pullParticipantTracks(pid, sessionId);
                }
                // Case 3: "Zombie" check - checks if tracks are ended
                else {
                    const allEnded = stream.getTracks().every(t => t.readyState === 'ended');
                    if (allEnded && stream.getTracks().length > 0) {
                        log('HEALTH', `Zombie stream detected for ${pid}. Cleaning up for repair.`);
                        removeParticipantStreams(pid);
                        pullParticipantTracks(pid, sessionId);
                    }
                }
            }
        }, 90000); // Relaxed for high-latency stability
    }

    async function initSFU(stream: MediaStream | null) {
        if (!meetingRef.value || !localParticipantRef.value) return;
        return runInSFUQueue(async () => {
            if (!meetingRef.value || !localParticipantRef.value) return;
            
            // Optimization: If session already exists and is healthy, don't restart it
            if (sfuPc && sfuSessionId.value && (sfuConnectionState.value === 'connected' || sfuConnectionState.value === 'connecting')) {
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
            if (!participantId) {
                const assoc = sfuTransceiverMap.get(event.transceiver);
                if (assoc) {
                    participantId = assoc.participantId;
                    if (assoc.trackName === 'screen') participantId += ':screen';
                    log("TRACK", `Resolved ${mid} via transceiver association to ${participantId}`);
                }
            } else {
                log("TRACK", `Resolved ${mid} via MID map to ${participantId}`);
            }

            if (participantId) {
                const pid = participantId.toLowerCase();
                log("TRACK", `Final Match: ${track.kind} (${mid}) for ${pid}`);
                let s = event.streams[0] || new MediaStream([track]);
                
                const handleActive = () => {
                    const existingStream = remoteStreams.value.get(pid);
                    if (existingStream) {
                        // Prune dead tracks of same kind to avoid UI freezes/zombie tiles
                        // Only remove tracks of the same kind that ARE NOT the new track
                        existingStream.getTracks()
                            .filter(t => t.kind === track.kind && t.id !== track.id)
                            .forEach(t => {
                                log('TRACK', `Pruning old ${t.kind} track ${t.id} for ${pid}`);
                                existingStream.removeTrack(t);
                            });

                        if (!existingStream.getTracks().find(t => t.id === track.id)) {
                            existingStream.addTrack(track);
                            log('TRACK', `Linked ${track.kind} track ${track.id} to existing stream for ${pid}`);
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
                
                track.onunmute = handleActive;
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

        const trackObjects = sfuPc.getTransceivers()
            .filter((t) => t.mid !== null && (t.receiver.track.kind === "audio" || t.receiver.track.kind === "video"))
            .map((t) => ({
                location: "local",
                mid: t.mid,
                trackName: t.receiver.track.kind, // "audio" or "video"
            }));

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

                await iceConnectedPromise;
                
                // Deterministic Handshake Guard:
                // Wait a tiny bit for the browser to fully settle MIDs in the PC 
                await new Promise(r => setTimeout(r, 100));
                
                broadcastMediaMids();
                startHealthCheck();

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
    async function pullParticipantTracks(participantPublicId: string, remoteSessionId?: string, audioMid?: string, videoMid?: string, screenMid?: string) {
        // Queue if session not ready yet (signal arrived before initSFU completed)
        if (!sfuPc || !sfuSessionId.value) {
            log('SFU', `Session not ready, queuing signal for ${participantPublicId}`);
            pendingPullSignals.push({ participantPublicId, remoteSessionId, audioMid, videoMid, screenMid });
            return;
        }

        const normalizedId = participantPublicId.toLowerCase();
        const targetSessionId = remoteSessionId || remoteSfuSessions.get(normalizedId);
        if (!targetSessionId) {
            log('SFU', `No session ID for ${participantPublicId}, cannot pull tracks.`);
            return;
        }

        const knownTracks = remoteSfuTracks.get(normalizedId);
        const actualAudioMid = audioMid || knownTracks?.audioMid;
        const actualVideoMid = videoMid || knownTracks?.videoMid;
        const actualScreenMid = screenMid || knownTracks?.screenMid;

        if (audioMid || videoMid || screenMid) {
            remoteSfuTracks.set(normalizedId, {
                audioMid: actualAudioMid,
                videoMid: actualVideoMid,
                screenMid: actualScreenMid
            });
        }

        // Retry logic with backoff (from CallApp.vue L2799-2812)
        const currentAttempts = (participantPullAttempts.get(normalizedId) || 0) + 1;
        participantPullAttempts.set(normalizedId, currentAttempts);
        const retryDelays = [1000, 1500, 2000, 3000, 5000];
        if (currentAttempts > retryDelays.length) {
            log('ERROR', `Failed to pull tracks for ${normalizedId} after ${retryDelays.length} attempts. Giving up.`);
            participantPullAttempts.delete(normalizedId);
            return;
        }

        // Case-specific track requests:
        const existingStream = remoteStreams.value.get(normalizedId);
        
        // NOTE: Visibility gating (selective subscribing) is DISABLED for now.
        // The visibleParticipantIds set was not being populated correctly due to
        // a timing issue with meetingStore.stream initialization, causing a deadlock
        // where tracks couldn't be pulled because participants weren't "visible",
        // but participants couldn't become visible without their tracks.
        // TODO: Re-implement selective subscribing once the visibility watcher is fixed.
        const needsAudio = !existingStream || existingStream.getAudioTracks().length === 0 || !!audioMid;
        const needsVideo = !existingStream || existingStream.getVideoTracks().length === 0 || !!videoMid;
        const needsScreen = !existingStream || !remoteStreams.value.has(`${normalizedId}:screen`) || !!screenMid;

        const trackReqs: any[] = [];
        if (needsAudio) trackReqs.push({ location: "remote", sessionId: targetSessionId, trackName: "audio" });
        if (needsVideo) trackReqs.push({ location: "remote", sessionId: targetSessionId, trackName: "video" });
        if (needsScreen || (currentAttempts === 1 && participantPublicId.includes(':screen'))) {
             trackReqs.push({ location: "remote", sessionId: targetSessionId, trackName: "screen" });
        }

        if (trackReqs.length === 0) {
            log('SFU', `No tracks to pull for ${participantPublicId}, skipping.`);
            return;
        }

        // QUEUE the handshake (from CallApp.vue L2839)
        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return;

            try {
                // Ensure transceivers exist before mapping MIDs
                let audioTransceiver = sfuPc.getTransceivers().find(t =>
                    t.direction === "recvonly" &&
                    sfuTransceiverMap.get(t)?.participantId === participantPublicId.toLowerCase() &&
                    sfuTransceiverMap.get(t)?.trackName === "audio"
                );
                let videoTransceiver = sfuPc.getTransceivers().find(t =>
                    t.direction === "recvonly" &&
                    sfuTransceiverMap.get(t)?.participantId === participantPublicId.toLowerCase() &&
                    sfuTransceiverMap.get(t)?.trackName === "video"
                );
                let screenTransceiver = sfuPc.getTransceivers().find(t =>
                    t.direction === "recvonly" &&
                    sfuTransceiverMap.get(t)?.participantId === participantPublicId.toLowerCase() &&
                    sfuTransceiverMap.get(t)?.trackName === "screen"
                );

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
                    
                    // CRITICAL: Cloudflare needs the local MID to know which transceiver to associate the track with!
                    if (t?.mid) {
                        return { ...req, mid: t.mid };
                    }
                    return req;
                });

                log('SFU', `Attempt ${currentAttempts}: Pulling tracks for ${participantPublicId} [Audio: ${audioTransceiver?.mid || 'None'}, Video: ${videoTransceiver?.mid || 'None'}, Screen: ${screenTransceiver?.mid || 'None'}] using local session ${sfuSessionId.value}, remote session ${targetSessionId}...`);

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

                const foundAny = Array.isArray(res.tracks) && res.tracks.some((t: any) => t.mid && !t.errorCode);

                if (foundAny && res.sessionDescription) {
                    log('SFU', `Track pull success on attempt ${currentAttempts} for ${participantPublicId}`);
                    // Moved deletion to after track is confirmed and settled to prevent early retry reset

                    // Normalizing ID for consistency in internal maps
                    const normalizedId = participantPublicId.toLowerCase();

                    // Map MIDs to participant
                    if (Array.isArray(res.tracks)) {
                        res.tracks.forEach((track: any) => {
                            if (track.mid) {
                                const mapKey = track.trackName === 'screen' ? `${normalizedId}:screen` : normalizedId;
                                midToParticipantMap.set(track.mid, mapKey);
                                
                                const t = sfuPc!.getTransceivers().find(tr => tr.mid === track.mid);
                                if (t) {
                                    sfuTransceiverMap.set(t, { participantId: normalizedId, trackName: track.trackName });
                                }
                            }
                        });
                    }

                    flushPendingTracks();

                    // Server Offer → Client Answer flow (from CallApp.vue L2942-2955)
                    log('SFU', `Processing Server Offer for tracks from ${participantPublicId}`);
                    try {
                        await sfuPc!.setRemoteDescription(toSdpAnswer(res.sessionDescription));
                    } catch (sdpErr) {
                        log('ERROR', `setRemoteDescription failed for ${participantPublicId}:`, sdpErr);
                        throw sdpErr; // bubble up to try-catch for retry
                    }

                    // Map MIDs to participant AFTER setRemoteDescription so tr.mid is available
                    if (Array.isArray(res.tracks)) {
                        res.tracks.forEach((track: any) => {
                            if (track.mid) {
                                const mapKey = track.trackName === 'screen' ? `${normalizedId}:screen` : normalizedId;
                                midToParticipantMap.set(track.mid, mapKey);
                                
                                const t = sfuPc!.getTransceivers().find(tr => tr.mid === track.mid);
                                if (t) {
                                    sfuTransceiverMap.set(t, { participantId: normalizedId, trackName: track.trackName });
                                }
                            }
                        });
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

                    participantTransceivers.set(normalizedId, {
                        audioMid: res.tracks?.find((t: any) => t.trackName === 'audio')?.mid || '',
                        videoMid: res.tracks?.find((t: any) => t.trackName === 'video')?.mid || '',
                        screenMid: res.tracks?.find((t: any) => t.trackName === 'screen')?.mid || ''
                    });

                    // CRITICAL: Settling Delay for High Latency (1500ms)
                    // Gives the PeerConnection a moment to "digest" the new tracks before new signaling
                    log('SFU', `Settling handshake for ${participantPublicId}...`);
                    await new Promise(r => setTimeout(r, 1000));
                    
                    // Only delete attempt counter after successful settlement
                    participantPullAttempts.delete(participantPublicId);
                } else {
                    // Retry with backoff (from CallApp.vue L2968-2985)
                    log('SFU', `Pull attempt ${currentAttempts} for ${participantPublicId} returned no valid tracks. Rescheduling...`);
                    const delay = retryDelays[currentAttempts - 1] || 5000;
                    setTimeout(() => {
                        pullParticipantTracks(participantPublicId, targetSessionId, actualAudioMid, actualVideoMid, screenMid);
                    }, delay);
                }
            } catch (error: any) {
                if (error?.response?.status === 406) {
                    log('ERROR', '406 Not Acceptable during pull. Triggering Rescue.');
                    await handleSFU406Rescue();
                    return;
                }
                // Retry on server/network errors
                const delay = retryDelays[currentAttempts - 1] || 5000;
                log('ERROR', `Failed to pull tracks (attempt ${currentAttempts}), retrying in ${delay}ms...`, error);
                setTimeout(() => {
                    pullParticipantTracks(participantPublicId, targetSessionId, actualAudioMid, actualVideoMid, screenMid);
                }, delay);
            }
        });
    }

    async function replaceTrack(kind: 'audio' | 'video', newTrack: MediaStreamTrack | null) {
        if (!sfuPc || !sfuSessionId.value) return;

        log('MEDIA', `Replacing ${kind} track`, { hasNewTrack: !!newTrack });

        // Wrap in SFU queue to prevent race conditions with track pulls
        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return;

            const tc = sfuPc.getTransceivers().find(t => 
                sfuTransceiverMap.get(t)?.trackName === kind && 
                sfuTransceiverMap.get(t)?.participantId === 'self'
            );
            
            if (tc) {
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

                if (needsRenegotiation) {
                    log('MEDIA', `Renegotiating ${kind} due to direction change`);
                    const offer = await sfuPc.createOffer();
                    await sfuPc.setLocalDescription(offer);

                    const trackObjects = sfuPc.getTransceivers()
                        .filter(t => t.mid !== null && (t.receiver.track.kind === 'audio' || t.receiver.track.kind === 'video'))
                        .map(t => ({
                            location: "local",
                            mid: t.mid,
                            trackName: t.receiver.track.kind
                        }));

                    try {
                        const res = await meetingService.sfuSessionTracks(
                            meetingRef.value!.public_id,
                            sfuSessionId.value!,
                            trackObjects, // Pass trackObjects here for the renegotiation
                            mungeSdp(sfuPc.localDescription!.sdp)
                        );
                        await sfuPc.setRemoteDescription(toSdpAnswer(res.sessionDescription));
                        broadcastMediaMids(); // Broadcast after successful renegotiation
                    } catch (error: any) {
                        if (error?.response?.status === 406) {
                            log('ERROR', '406 Not Acceptable caught during replaceTrack renegotiation. Triggering Rescue state.');
                            await handleSFU406Rescue();
                            return; // Don't broadcast after rescue
                        } else {
                            log('ERROR', 'Failed to renegotiate backend track direction', error);
                        }
                    }
                }

                // Always broadcast MIDs after any track change (not just direction changes)
                // so others know when our media becomes available or goes away
                broadcastMediaMids();
            }
        });
    }

    async function publishScreenTrack(screenStream: MediaStream): Promise<{ mid: string, stream: MediaStream } | null> {
        if (!sfuPc || !sfuSessionId.value) return null;

        localScreenStream.value = screenStream;
        log('MEDIA', `Publishing screen share track (Queued)`);

        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return null;

            let screenTc = sfuPc.getTransceivers().find(t => 
                sfuTransceiverMap.get(t)?.trackName === 'screen' && 
                sfuTransceiverMap.get(t)?.participantId === 'self'
            );
            
            const videoTrack = screenStream.getVideoTracks()[0];
            const maxRetries = 5;
            let attempt = 0;

            const executePublish = async (): Promise<{ mid: string, stream: MediaStream } | null> => {
                attempt++;
                try {
                    if (!screenTc) {
                        log('MEDIA', `Creating new transceiver for screen share (Attempt ${attempt})`);
                        screenTc = sfuPc!.addTransceiver(videoTrack, {
                            direction: 'sendonly',
                            streams: [screenStream],
                            sendEncodings: [
                                { rid: "h", active: true, maxBitrate: 2500000, scaleResolutionDownBy: 1 }
                            ]
                        });
                        sfuTransceiverMap.set(screenTc, { participantId: 'self', trackName: 'screen' });
                        
                        // Renegotiate with SFU for NEW transceiver
                        const offer = await sfuPc!.createOffer();
                        await sfuPc!.setLocalDescription(offer);

                        const trackObjects = sfuPc!.getTransceivers()
                            .filter(t => t.mid !== null && (t.receiver.track.kind === 'audio' || t.receiver.track.kind === 'video'))
                            .map(t => ({
                                location: "local",
                                mid: t.mid,
                                trackName: sfuTransceiverMap.get(t)?.trackName || t.receiver.track.kind
                            }));

                        const res = await meetingService.sfuSessionTracks(
                            meetingRef.value!.public_id,
                            sfuSessionId.value!,
                            trackObjects,
                            mungeSdp(sfuPc!.localDescription!.sdp)
                        );
                        await sfuPc!.setRemoteDescription(toSdpAnswer(res.sessionDescription));
                        broadcastMediaMids();
                        return { mid: screenTc.mid || '', stream: screenStream };
                    } else {
                        log('MEDIA', `Reusing inactive screen transceiver (Attempt ${attempt})`);
                        await screenTc.sender.replaceTrack(videoTrack);
                        broadcastMediaMids();
                        return { mid: screenTc.mid || '', stream: screenStream };
                    }
                } catch (error: any) {
                    const isTooEarly = error?.response?.status === 425 || error?.message?.includes('425');
                    
                    if (isTooEarly && attempt < maxRetries) {
                        const delay = 500 * attempt;
                        log('SFU', `Backend reported 425 (Too Early) on publish attempt ${attempt}. Retrying in ${delay}ms...`);
                        await new Promise(r => setTimeout(r, delay));
                        return executePublish();
                    }

                    if (error?.response?.status === 406) {
                        log('ERROR', '406 Not Acceptable during publish screen track. Rescuing.');
                        await handleSFU406Rescue();
                    } else {
                        log('ERROR', `Failed to publish screen track after ${attempt} attempts`, error);
                    }
                    return null;
                }
            };

            return executePublish();
        });
    }

    async function unpublishScreenTrack() {
        if (!sfuPc || !sfuSessionId.value) return;

        localScreenStream.value = null;
        log('MEDIA', 'Unpublishing screen track (Queued)');

        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return;

            const screenTc = sfuPc.getTransceivers().find(t => 
                sfuTransceiverMap.get(t)?.trackName === 'screen' && 
                sfuTransceiverMap.get(t)?.participantId === 'self'
            );
            
            if (screenTc) {
                log('MEDIA', 'Unpublishing screen track natively');
                await screenTc.sender.replaceTrack(null);
                // We avoid re-negotiating to INACTIVE to prevent 406 errors
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

    function removeParticipantStreams(publicId: string) {
        const newMap = new Map(remoteStreams.value);
        newMap.delete(publicId);
        newMap.delete(`${publicId}:screen`);
        remoteStreams.value = newMap;
        stopAudioAnalysis(publicId);
    }

    function cleanup() {
        if (healthCheckInterval) {
            window.clearInterval(healthCheckInterval);
            healthCheckInterval = null;
        }
        if (sfuPc) sfuPc.close();
        remoteStreams.value.clear();
        participantSlots.value = [];
        remoteSfuSessions.clear();
        remoteSfuTracks.clear();
        sfuTransceiverMap.clear();
        midToParticipantMap.clear();
        participantPullAttempts.clear();
        pendingTrackEvents.length = 0;
        pendingPullSignals.length = 0;
        audioAnalysers.forEach(x => {
            window.clearInterval(x.interval);
            x.context.close().catch(()=>{});
        });
        audioAnalysers.clear();
    }

    function setVisibleParticipants(ids: string[]) {
        const newSet = new Set(ids.map(id => id.toLowerCase()));
        const added = [...newSet].filter(id => !visibleParticipantIds.value.has(id));
        const removed = [...visibleParticipantIds.value].filter(id => !newSet.has(id));

        visibleParticipantIds.value = newSet;

        if (added.length > 0) {
            log('SFU', `Visibility updated: Added ${added.join(', ')}`);
            // Proactively pull tracks for newly visible participants if we have their session info
            added.forEach(id => {
                const sessionId = remoteSfuSessions.get(id);
                if (sessionId) {
                    pullParticipantTracks(id, sessionId);
                }
            });
        }

        if (removed.length > 0) {
            log('SFU', `Visibility updated: Removed ${removed.length} participants from active view.`);
            // In a future update, we can call unsubscribeTracks(removed) here to save bandwidth
            // but for now we keep the tracks to avoid signaling overhead on every scroll.
        }
    }

    async function unsubscribeTracks(participantPublicIds: string[]) {
        if (!sfuPc || !sfuSessionId.value) return;

        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return;
            log('SFU', `Unsubscribing tracks for: ${participantPublicIds.join(', ')}`);
            // Implementation would involve setting transceiver direction to 'inactive'
            // and potentially calling a server endpoint if supported.
        });
    }

    return {
        localStream,
        remoteStreams,
        remoteSfuSessions,
        remoteSfuTracks,
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
        rebroadcastToJoiner,
        replaceTrack,
        publishScreenTrack,
        unpublishScreenTrack,
        removeParticipantStreams,
        cleanup
    };
}
