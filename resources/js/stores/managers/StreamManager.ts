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
    const requestedRemoteTracks = new Set<string>();
    const participantTransceivers = new Map<string, { audioMid?: string; videoMid?: string }>();
    const participantPullAttempts = new Map<string, number>();
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
                    if (assoc.trackName === 'screen') participantId += ':screen';
                }
            }

            if (participantId) {
                log('TRACK', `Resolved buffered track ${evt.track.kind} (${evt.mid}) for ${participantId}`);
                let s = evt.streams[0] || new MediaStream([evt.track]);
                
                const handleActive = () => {
                    const pid = participantId!;
                    const existingStream = remoteStreams.value.get(pid);
                    if (existingStream) {
                        if (!existingStream.getTracks().find(t => t.id === evt.track.id)) {
                            existingStream.addTrack(evt.track);
                        }
                    } else {
                        const newMap = new Map(remoteStreams.value);
                        newMap.set(pid, s);
                        remoteStreams.value = newMap;
                        if (!pid.endsWith(':screen')) {
                            startAudioAnalysis(pid, s);
                        }
                    }
                };
                
                evt.track.onunmute = handleActive;
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
        const localId = localParticipantRef.value?.public_id || 'self';
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
        }, 60000);
    }

    async function initSFU(stream: MediaStream | null) {
        if (!meetingRef.value || !localParticipantRef.value) return;
        if (isInitializingSFU.value) {
            log('SFU', 'SFU initialization already in progress, skipping');
            return;
        }

        isInitializingSFU.value = true;
        try {
            await doInitSFU(stream);
        } finally {
            isInitializingSFU.value = false;
        }
    }

    async function doInitSFU(stream: MediaStream | null) {
        if (sfuPc) sfuPc.close();

        log('SFU', 'Initializing RTCPeerConnection (Cold Start)', { hasStream: !!stream });
        sfuPc = new RTCPeerConnection({
            iceServers: iceServersRef.value.length > 0 ? iceServersRef.value : [{ urls: 'stun:stun.cloudflare.com:3478' }],
            bundlePolicy: 'max-bundle',
        });

        const iceConnectedPromise = new Promise((resolve) => {
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
        });

        sfuPc.ontrack = (event) => {
            const track = event.track;
            const mid = event.transceiver.mid;
            if (!mid) return;

            let participantId = midToParticipantMap.get(mid);
            if (!participantId) {
                const assoc = sfuTransceiverMap.get(event.transceiver);
                if (assoc) {
                    participantId = assoc.participantId;
                    if (assoc.trackName === 'screen') participantId += ':screen';
                }
            }

            if (participantId) {
                log("TRACK", `Active: ${track.kind} (${mid}) for ${participantId}`);
                let s = event.streams[0] || new MediaStream([track]);
                
                const handleActive = () => {
                    const pid = participantId!;
                    const existingStream = remoteStreams.value.get(pid);
                    if (existingStream) {
                        if (!existingStream.getTracks().find(t => t.id === track.id)) {
                            existingStream.addTrack(track);
                        }
                    } else {
                        const newMap = new Map(remoteStreams.value);
                        newMap.set(pid, s);
                        remoteStreams.value = newMap;
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
        sfuTransceiverMap.set(atc, { participantId: localParticipantRef.value?.public_id || 'self', trackName: 'audio' });
        sfuTransceiverMap.set(vtc, { participantId: localParticipantRef.value?.public_id || 'self', trackName: 'video' });

        if (stream) {
            stream.getTracks().forEach((track) => {
                let tc = sfuPc!.getTransceivers().find(
                    (t) => t.receiver.track.kind === track.kind && t.direction === "sendonly" && !t.sender.track
                );

                if (tc) {
                    log('SFU', `Reserving placeholder transceiver for local ${track.kind}`);
                    tc.sender.replaceTrack(track);
                    try {
                        const params = tc.sender.getParameters();
                        if (params.encodings && params.encodings.length > 0) {
                            params.encodings.forEach(e => e.active = true);
                            tc.sender.setParameters(params).catch(() => {});
                        }
                    } catch (e) {}
                } else {
                    const ntc = sfuPc!.addTransceiver(track, { direction: "sendonly", streams: [stream] });
                    sfuTransceiverMap.set(ntc, { participantId: localParticipantRef.value?.public_id || 'self', trackName: track.kind });
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

        // Resolve sessionId and MIDs from state if not provided (Rescue Path)
        const targetSessionId = remoteSessionId || remoteSfuSessions.get(participantPublicId);
        const persistedTracks = remoteSfuTracks.get(participantPublicId);
        const actualAudioMid = audioMid || persistedTracks?.audioMid;
        const actualVideoMid = videoMid || persistedTracks?.videoMid;

        if (!targetSessionId) {
            log('ERROR', `Cannot pull tracks for ${participantPublicId}: session ID unknown yet`);
            return;
        }

        // DEDUP: Only skip if we already have camera transceivers AND no new screen track
        // (Allow re-pulling when screen share starts even if camera was already pulled)
        const existingTc = participantTransceivers.get(participantPublicId);
        if (existingTc && !screenMid) {
            log('SFU', `Already have transceivers for ${participantPublicId}, skipping redundant pull`);
            return;
        }

        // Retry logic with backoff (from CallApp.vue L2799-2812)
        const currentAttempts = (participantPullAttempts.get(participantPublicId) || 0) + 1;
        participantPullAttempts.set(participantPublicId, currentAttempts);
        const retryDelays = [1000, 1500, 2000, 3000, 5000];
        if (currentAttempts > retryDelays.length) {
            log('ERROR', `Failed to pull tracks for ${participantPublicId} after ${retryDelays.length} attempts. Giving up.`);
            participantPullAttempts.delete(participantPublicId);
            return;
        }

        // Build track requests (from CallApp.vue L2814-2836)
        const trackReqs: any[] = [];
        if (actualAudioMid || currentAttempts === 1) {
            trackReqs.push({ location: "remote", sessionId: targetSessionId, trackName: "audio" });
        }
        if (actualVideoMid || currentAttempts === 1) {
            trackReqs.push({ location: "remote", sessionId: targetSessionId, trackName: "video" });
        }
        if (screenMid) {
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
                log('SFU', `Attempt ${currentAttempts}: Pulling tracks for ${participantPublicId}...`);

                // Pre-create recvonly transceivers per participant (from CallApp.vue L2848-2884)
                let audioTransceiver = sfuPc.getTransceivers().find(t =>
                    t.direction === "recvonly" &&
                    sfuTransceiverMap.get(t)?.participantId === participantPublicId &&
                    sfuTransceiverMap.get(t)?.trackName === "audio"
                );
                let videoTransceiver = sfuPc.getTransceivers().find(t =>
                    t.direction === "recvonly" &&
                    sfuTransceiverMap.get(t)?.participantId === participantPublicId &&
                    sfuTransceiverMap.get(t)?.trackName === "video"
                );

                if (!audioTransceiver && trackReqs.some(r => r.trackName === 'audio')) {
                    audioTransceiver = sfuPc.addTransceiver("audio", { direction: "recvonly" });
                    sfuTransceiverMap.set(audioTransceiver, { participantId: participantPublicId, trackName: "audio" });
                }
                if (!videoTransceiver && trackReqs.some(r => r.trackName === 'video')) {
                    videoTransceiver = sfuPc.addTransceiver("video", { direction: "recvonly" });
                    sfuTransceiverMap.set(videoTransceiver, { participantId: participantPublicId, trackName: "video" });
                }
                if (trackReqs.some(r => r.trackName === 'screen')) {
                    let screenTc = sfuPc.getTransceivers().find(t =>
                        t.direction === "recvonly" &&
                        sfuTransceiverMap.get(t)?.participantId === participantPublicId &&
                        sfuTransceiverMap.get(t)?.trackName === "screen"
                    );
                    if (!screenTc) {
                        screenTc = sfuPc.addTransceiver("video", { direction: "recvonly" });
                        sfuTransceiverMap.set(screenTc, { participantId: participantPublicId, trackName: "screen" });
                    }
                }

                // SERVER-INITIATED OFFER FLOW (from CallApp.vue L2895-2955)
                // Key difference from old code: NO client offer SDP sent.
                // Cloudflare returns a server offer, we create an answer.
                const res = await meetingService.sfuSessionTracks(
                    meetingRef.value!.public_id,
                    sfuSessionId.value!,
                    trackReqs,
                    undefined  // No client offer — server-initiated flow
                );

                const foundAny = Array.isArray(res.tracks) && res.tracks.some((t: any) => t.mid && !t.errorCode);

                if (foundAny && res.sessionDescription) {
                    log('SFU', `Track pull success on attempt ${currentAttempts} for ${participantPublicId}`);
                    participantPullAttempts.delete(participantPublicId);

                    // Map MIDs to participant
                    if (Array.isArray(res.tracks)) {
                        res.tracks.forEach((track: any) => {
                            if (track.mid) {
                                midToParticipantMap.set(track.mid, track.trackName === 'screen' ? `${participantPublicId}:screen` : participantPublicId);
                                const t = sfuPc!.getTransceivers().find(tr => tr.mid === track.mid);
                                if (t) {
                                    sfuTransceiverMap.set(t, { participantId: participantPublicId, trackName: track.trackName });
                                }
                            }
                        });
                    }

                    flushPendingTracks();

                    // Server Offer → Client Answer flow (from CallApp.vue L2942-2955)
                    log('SFU', `Processing Server Offer for tracks from ${participantPublicId}`);
                    await sfuPc!.setRemoteDescription(toSdpAnswer(res.sessionDescription));

                    const answer = await sfuPc!.createAnswer();
                    await sfuPc!.setLocalDescription(answer);

                    await meetingService.sfuSessionRenegotiate(
                        meetingRef.value!.public_id,
                        sfuSessionId.value!,
                        mungeSdp(answer.sdp!),
                        'answer',
                        'PUT'
                    );

                    // Record transceivers for dedup (from CallApp.vue L2957-2966)
                    participantTransceivers.set(participantPublicId, {
                        audioMid: res.tracks?.find((t: any) => t.trackName === 'audio')?.mid || '',
                        videoMid: res.tracks?.find((t: any) => t.trackName === 'video')?.mid || '',
                    });
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

            const tc = sfuPc.getTransceivers().find(t => sfuTransceiverMap.get(t)?.trackName === kind && sfuTransceiverMap.get(t)?.participantId === (localParticipantRef.value?.public_id || 'self'));
            
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

                    try {
                        const res = await meetingService.sfuSessionTracks(
                            meetingRef.value!.public_id,
                            sfuSessionId.value!,
                            [],
                            mungeSdp(sfuPc.localDescription!.sdp)
                        );
                        await sfuPc.setRemoteDescription(toSdpAnswer(res.sessionDescription));
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

    async function publishScreenTrack(screenStream: MediaStream): Promise<{ mid: string } | null> {
        if (!sfuPc || !sfuSessionId.value) return null;

        log('MEDIA', `Publishing screen share track (Queued)`);

        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return null;

            let screenTc = sfuPc.getTransceivers().find(t => 
                sfuTransceiverMap.get(t)?.trackName === 'screen' && 
                sfuTransceiverMap.get(t)?.participantId === (localParticipantRef.value?.public_id || 'self')
            );
            
            const videoTrack = screenStream.getVideoTracks()[0];
            const maxRetries = 5;
            let attempt = 0;

            const executePublish = async (): Promise<{ mid: string } | null> => {
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
                        sfuTransceiverMap.set(screenTc, { participantId: localParticipantRef.value?.public_id || 'self', trackName: 'screen' });
                        
                        // Renegotiate with SFU for NEW transceiver
                        const offer = await sfuPc!.createOffer();
                        await sfuPc!.setLocalDescription(offer);

                        const res = await meetingService.sfuSessionTracks(
                            meetingRef.value!.public_id,
                            sfuSessionId.value!,
                            [{ location: "local", mid: screenTc.mid, trackName: "screen" }],
                            mungeSdp(sfuPc!.localDescription!.sdp)
                        );
                        await sfuPc!.setRemoteDescription(toSdpAnswer(res.sessionDescription));
                        broadcastMediaMids();
                        return { mid: screenTc.mid || '' };
                    } else {
                        log('MEDIA', `Reusing inactive screen transceiver (Attempt ${attempt})`);
                        await screenTc.sender.replaceTrack(videoTrack);
                        broadcastMediaMids();
                        return { mid: screenTc.mid || '' };
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

        log('MEDIA', 'Unpublishing screen track (Queued)');

        return runInSFUQueue(async () => {
            if (!sfuPc || !sfuSessionId.value) return;

            const screenTc = sfuPc.getTransceivers().find(t => 
                sfuTransceiverMap.get(t)?.trackName === 'screen' && 
                sfuTransceiverMap.get(t)?.participantId === (localParticipantRef.value?.public_id || 'self')
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
        if (isInitializingSFU.value) {
            log('SFU', 'Reset requested while already initializing, skipping');
            return;
        }

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
        await initSFU(activeLocalStream);
    }

    function addLocalStream(stream: MediaStream | null) {
        localStream.value = stream;
        resetSFUSession(stream);
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
        audioAnalysers.forEach(x => {
            window.clearInterval(x.interval);
            x.context.close().catch(()=>{});
        });
        audioAnalysers.clear();
    }

    return {
        localStream,
        remoteStreams,
        remoteSfuSessions,
        remoteSfuTracks,
        sfuIceState,
        sfuConnectionState,
        sfuSessionId,
        sfuPc: () => sfuPc, // Expose as a getter function since it can be reassigned

        addLocalStream,
        setLocalStream,
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
