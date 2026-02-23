import { defineStore } from 'pinia';
import { ref, reactive, computed } from 'vue';
import { meetingService, type Meeting, type MeetingParticipant } from '@/services/meeting.service';
import { startEcho, stopEcho } from '@/echo';
import { toast } from 'vue-sonner';
import { useAuthStore } from '@/stores/auth';

export const useMeetingStore = defineStore('meeting', () => {
    const authStore = useAuthStore();
    const meetings = ref<Meeting[]>([]);
    const meeting = ref<Meeting | null>(null);
    const participants = ref<MeetingParticipant[]>([]);
    const localParticipant = ref<MeetingParticipant | null>(null);
    const localStream = ref<MediaStream | null>(null);
    const iceServers = ref<RTCIceServer[]>([]);
    const echoChannel = ref<any>(null);
    
    // SFU state
    let sfuPc: RTCPeerConnection | null = null;
    const sfuSessionId = ref<string | null>(null);
    const remoteStreams = reactive(new Map<string, MediaStream>());
    const remoteSfuSessions = reactive(new Map<string, string>());
    const raisedHands = ref<Set<string>>(new Set());
    const screenShares = ref<Set<string>>(new Set());
    const audioAnalysers = new Map<string, { context: AudioContext, source: MediaStreamAudioSourceNode, analyser: AnalyserNode, interval: ReturnType<typeof setInterval> }>();
    const talkingParticipants = ref<Set<string>>(new Set());

    // SFU Connection Monitoring
    const sfuConnectionState = ref<RTCPeerConnectionState>('new');
    const sfuIceState = ref<RTCIceConnectionState>('new');

    // SFU Maps for track attribution
    const sfuTransceiverMap = new Map<RTCRtpTransceiver, { participantId: string, trackName: string }>();
    const midToParticipantMap = new Map<string, string>();
    const pendingTrackEvents: {
        track: MediaStreamTrack;
        mid: string;
        transceiver: RTCRtpTransceiver;
        streams: readonly MediaStream[];
    }[] = [];

    // Real-time Presence
    const activeParticipantIds = ref<Set<string>>(new Set());
    
    // Renegotiation Queue
    let sfuQueue = Promise.resolve();
    function runInSFUQueue<T>(fn: () => Promise<T>): Promise<T> {
        const result = sfuQueue.then(fn);
        sfuQueue = result.then(() => {}, () => {});
        return result;
    }

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

    // Active Speaker & Spotlight Logic
    const activeSpeakerId = ref<string | null>(null);
    const pinnedParticipantId = ref<string | null>(null);

    // Dev Mode Simulation
    const isDevMode = ref(false);
    const mockParticipants = ref<MeetingParticipant[]>([]);
    const simulatedRole = ref<'host' | 'participant' | null>(null);

    function flushPendingTracks() {
        if (pendingTrackEvents.length === 0) return;
        trace('SFU', `Flushing ${pendingTrackEvents.length} buffered track event(s)`);

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
                trace('TRACK', `Resolved buffered track ${evt.track.kind} (${evt.mid}) for ${participantId}`);
                let s = evt.streams[0] || new MediaStream([evt.track]);
                
                const handleActive = () => {
                    const pid = participantId!;
                    const existingStream = remoteStreams.get(pid);
                    if (existingStream) {
                        if (!existingStream.getTracks().find(t => t.id === evt.track.id)) {
                            existingStream.addTrack(evt.track);
                        }
                    } else {
                        remoteStreams.set(pid, s);
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

    function trace(area: string, message: string, data?: any) {
        const timestamp = new Date().toISOString().split('T')[1].split('.')[0];
        const logData = data ? (typeof data === 'object' ? JSON.parse(JSON.stringify(data)) : data) : '';
        console.info(`[RTC-TRACE][${timestamp}][${area}] ${message}`, logData);
        if (area === 'SFU' || area === 'SIGNAL') {
            const stack = new Error().stack?.split('\n')[2]?.trim();
            console.debug(`[RTC-STACK][${area}] ${stack}`);
        }
    }

    function broadcastSfuMediaReady() {
        if (!sfuPc || !localParticipant.value) return;

        const transceivers = sfuPc.getTransceivers();
        const myTracks = Array.from(transceivers)
            .filter(t => t.sender.track)
            .map(t => ({ 
                name: sfuTransceiverMap.get(t)?.trackName || t.sender.track!.kind, 
                mid: t.mid 
            }));
        
        const audioMid = myTracks.find(t => t.name === 'audio')?.mid;
        const videoMid = myTracks.find(t => t.name === 'video')?.mid;
        const screenMid = myTracks.find(t => t.name === 'screen')?.mid;

        trace('SIGNAL', 'Broadcasting SFU Media Ready state', { audioMid, videoMid, screenMid });

        // Target existing participants specifically
        const others = participants.value.filter(p => p.public_id !== localParticipant.value?.public_id && p.status === 'admitted');
        for (const other of others) {
            meetingService.sendSignal(meeting.value!.public_id, {
                signal_type: 'signal',
                signal_data: {
                    type: 'sfu-media-ready',
                    sessionId: sfuSessionId.value,
                    audioMid,
                    videoMid,
                    screenMid
                },
                sender_participant_public_id: localParticipant.value!.public_id,
                target_participant_public_id: other.public_id
            });
        }
    }

    async function initializeMeeting(meetingId: string, participantPublicId: string) {
        const normalizedParticipantId = participantPublicId.toLowerCase();
        try {
            const data = await meetingService.getMeeting(meetingId);
            meeting.value = data;
            
            // Normalize all incoming participant IDs
            if (data.participants) {
                data.participants.forEach(p => {
                    p.public_id = p.public_id.toLowerCase();
                });
            }

            // Find local participant
            const found = data.participants?.find(p => p.public_id === normalizedParticipantId) || null;
            
            trace('SECURITY', `Verifying participant ${participantPublicId}`, {
                found: !!found,
                participantUserPublicId: found?.user?.public_id,
                currentUserPublicId: authStore.user?.public_id
            });

            // SECURITY CHECK: Ensure this participant belongs to the current logged-in user
            const currentPublicId = authStore.user?.public_id || 'Guest';
            const recordUserPublicId = found?.user?.public_id || 'Guest';

            if (found?.user_id && recordUserPublicId !== currentPublicId) {
                trace('SECURITY', 'IDENTITY MISMATCH: Rejecting token.');
                localParticipant.value = null;
            } else {
                localParticipant.value = found;
                if (localParticipant.value) {
                    localParticipant.value.public_id = localParticipant.value.public_id.toLowerCase();
                }
            }

            participants.value = data.participants || [];

            setupEcho(meetingId);

            // Fetch ICE servers
            try {
                const creds = await meetingService.getTurnCredentials(meetingId);
                iceServers.value = creds.ice_servers;
            } catch (e) {
                console.warn("[MeetingStore] Failed to fetch TURN credentials, using STUN fallback", e);
                iceServers.value = [{ urls: 'stun:stun.cloudflare.com:3478' }];
            }

        } catch (error) {
            console.error('Failed to initialize meeting store:', error);
            toast.error('Failed to load meeting data');
        }
    }

    function setupEcho(meetingId: string) {
        if (echoChannel.value) return;

        const echo = startEcho();
        
        trace('PRESENCE', `Joining presence channel: meeting.${meetingId}`);

        echoChannel.value = echo.join(`meeting.${meetingId}`)
            .here((users: any[]) => {
                trace('PRESENCE', `Members here: ${users.length}`, users);
                activeParticipantIds.value = new Set(users.map(u => u.public_id.toLowerCase()));
            })
            .joining((user: any) => {
                const pid = user.public_id.toLowerCase();
                trace('PRESENCE', `Member joining: ${pid}`, user);
                activeParticipantIds.value.add(pid);
                // If it's a new person, ensure we have their record in the list (fallback if join event missed)
                if (!participants.value.find(p => p.public_id === pid)) {
                    participants.value.push({
                        public_id: pid,
                        role: user.role,
                        status: user.status,
                        user: user.avatar ? { name: user.name, avatar_url: user.avatar } : null,
                        metadata: { guest_name: user.name }
                    } as any);
                }
            })
            .leaving((user: any) => {
                const pid = user.public_id.toLowerCase();
                trace('PRESENCE', `Member leaving: ${pid}`, user);
                activeParticipantIds.value.delete(pid);
                // Trigger media cleanup
                remoteStreams.delete(pid);
                stopAudioAnalysis(pid);
            })
            .listen('.MeetingSignal', (e: any) => {
                handleSignal(e);
            })
            .listen('.MeetingParticipantAdmitted', (e: any) => {
                trace('SECURITY', 'Participant admitted signal received', e);
                
                if (e.participant_public_id === localParticipant.value?.public_id) {
                    localParticipant.value.status = 'admitted';
                    toast.success("You've been admitted to the meeting!");

                    meetingService.sendSignal(meetingId, {
                        signal_type: 'participant-joined',
                        signal_data: {},
                        sender_participant_public_id: localParticipant.value!.public_id
                    });
                }

                const p = participants.value.find(p => p.public_id === e.participant_public_id);
                if (p) p.status = 'admitted';
            })
            .listen('.MeetingParticipantJoined', (e: any) => {
                trace('PRESENCE', 'Explicit join signal received', e);
                const existing = participants.value.find(p => p.public_id === e.participant.public_id);
                if (!existing) {
                    participants.value.push(e.participant);
                    if (isHost.value && e.participant.status === 'waiting') {
                        toast.info(`${e.participant.user?.name || e.participant.metadata?.guest_name || 'Someone'} is waiting in the lobby.`);
                    }
                } else {
                    Object.assign(existing, e.participant);
                }
            });
    }

    function handleSignal(e: any) {
        const sender_participant_public_id = e.sender_participant_public_id?.toLowerCase();
        const target_participant_public_id = e.target_participant_public_id?.toLowerCase();
        const { signal_type, signal_data } = e;

        if (sender_participant_public_id === localParticipant.value?.public_id?.toLowerCase()) {
            return;
        }

        trace('SIGNAL', `Received ${signal_type} from ${sender_participant_public_id}`, {
            target: target_participant_public_id,
            data: signal_data
        });

        // Ignore if not for us (if targeted)
        if (target_participant_public_id && target_participant_public_id !== localParticipant.value?.public_id?.toLowerCase()) {
            return;
        }

        if (signal_type === 'participant-joined') {
            // Re-broadcast our SFU media readiness if we're active so the new person pulls our tracks
            if (sfuSessionId.value) {
                const transceivers = sfuPc?.getTransceivers() || [];
                const tracks = Array.from(transceivers)
                    .filter(t => t.sender.track)
                    .map(t => ({ 
                        name: sfuTransceiverMap.get(t)?.trackName || t.sender.track!.kind, 
                        mid: t.mid 
                    }));
                
                const audioMid = tracks.find(t => t.name === 'audio')?.mid;
                const videoMid = tracks.find(t => t.name === 'video')?.mid;
                const screenMid = tracks.find(t => t.name === 'screen')?.mid; // 🔥 Added screen support

                meetingService.sendSignal(meeting.value!.public_id, {
                    signal_type: 'signal',
                    signal_data: {
                        type: 'sfu-media-ready',
                        sessionId: sfuSessionId.value,
                        audioMid,
                        videoMid,
                        screenMid
                    },
                    sender_participant_public_id: localParticipant.value!.public_id,
                    target_participant_public_id: sender_participant_public_id
                });
            }
            return;
        }

        if (signal_type === 'participant-left') {
            trace('CLEANUP', `Participant ${sender_participant_public_id} left. Removing tiles and analysers.`);
            participants.value = participants.value.filter(p => p.public_id !== sender_participant_public_id);
            remoteStreams.delete(sender_participant_public_id);
            stopAudioAnalysis(sender_participant_public_id);
            return;
        }

        if (signal_type === 'hand-toggle') {
            const { raised } = signal_data;
            if (raised) {
                raisedHands.value = new Set(raisedHands.value).add(sender_participant_public_id);
            } else {
                const newSet = new Set(raisedHands.value);
                newSet.delete(sender_participant_public_id);
                raisedHands.value = newSet;
            }
            return;
        }

        if (signal_type === 'screen-share-toggle') {
            const { sharing, mid } = signal_data;
            if (sharing) {
                screenShares.value = new Set(screenShares.value).add(sender_participant_public_id);
                // Proactively pull this new screen track
                const sessionId = remoteSfuSessions.get(sender_participant_public_id);
                if (sessionId) {
                    trace('SIGNAL', `Proactively pulling screen share for ${sender_participant_public_id} with MID: ${mid}`);
                    pullParticipantTracks(sender_participant_public_id, sessionId, undefined, undefined, mid || "true");
                }
            } else {
                const newSet = new Set(screenShares.value);
                newSet.delete(sender_participant_public_id);
                screenShares.value = newSet;
            }
            return;
        }

        // SFU Media Signaling
        if (signal_type === 'signal' && signal_data.type === 'sfu-media-ready') {
            const { sessionId, audioMid, videoMid, screenMid } = signal_data;
            remoteSfuSessions.set(sender_participant_public_id, sessionId);
            pullParticipantTracks(sender_participant_public_id, sessionId, audioMid, videoMid, screenMid);
        }
    }

    async function initSFU(stream: MediaStream | null = null) {
        if (sfuPc) {
            sfuPc.close();
        }

        trace('SFU', 'Initializing RTCPeerConnection (Cold Start)', { hasStream: !!stream });
        sfuPc = new RTCPeerConnection({
            iceServers: iceServers.value.length > 0 ? iceServers.value : [{ urls: 'stun:stun.cloudflare.com:3478' }],
            bundlePolicy: 'max-bundle',
        });

        // 0. Pre-create ICE Promise to avoid race conditions
        const iceConnectedPromise = new Promise((resolve) => {
            const checkState = () => {
                if (sfuPc!.iceConnectionState === "connected" || sfuPc!.iceConnectionState === "completed") {
                    resolve(true);
                }
            };
            sfuPc!.oniceconnectionstatechange = () => {
                sfuIceState.value = sfuPc!.iceConnectionState;
                trace('SFU', `ICE Connection State: ${sfuIceState.value}`);
                checkState();
            };
            sfuPc!.onconnectionstatechange = () => {
                sfuConnectionState.value = sfuPc!.connectionState;
                trace('SFU', `Connection State: ${sfuConnectionState.value}`);
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
                trace("TRACK", `Active: ${track.kind} (${mid}) for ${participantId}`);
                let s = event.streams[0] || new MediaStream([track]);
                
                const handleActive = () => {
                    const pid = participantId!;
                    const existingStream = remoteStreams.get(pid);
                    if (existingStream) {
                        if (!existingStream.getTracks().find(t => t.id === track.id)) {
                            existingStream.addTrack(track);
                        }
                    } else {
                        remoteStreams.set(pid, s);
                        if (!pid.endsWith(':screen')) {
                            startAudioAnalysis(pid, s);
                        }
                    }
                };
                
                track.onunmute = handleActive;
                if (!track.muted) handleActive();
            } else {
                trace("TRACK", `No participant mapping for MID ${mid}, buffering...`);
                pendingTrackEvents.push({
                    track,
                    mid,
                    transceiver: event.transceiver,
                    streams: event.streams
                });
            }
        };

        // 1. Add Local Senders with Simulcast (if provided) or Placeholders
        if (stream) {
            stream.getTracks().forEach((track) => {
                const init: RTCRtpTransceiverInit = {
                    direction: "sendonly",
                    streams: [stream],
                };
                if (track.kind === "video") {
                    init.sendEncodings = [
                        { rid: "l", active: true, maxBitrate: 150000, scaleResolutionDownBy: 4 },
                        { rid: "m", active: true, maxBitrate: 500000, scaleResolutionDownBy: 2 },
                        { rid: "h", active: true, maxBitrate: 1500000, scaleResolutionDownBy: 1 },
                    ];
                }
                const tc = sfuPc!.addTransceiver(track, init);
                sfuTransceiverMap.set(tc, { participantId: localParticipant.value?.public_id || 'self', trackName: track.kind });
            });
        } else {
            // Cold Start: Add placeholder transceivers to ensure valid SDP for Cloudflare
            // We use 'sendonly' as defaults for our OWN publishing slots.
            trace('SFU', 'Adding placeholder transceivers for Cold Start');
            const atc = sfuPc!.addTransceiver('audio', { direction: 'sendonly' });
            const vtc = sfuPc!.addTransceiver('video', { direction: 'sendonly' });
            sfuTransceiverMap.set(atc, { participantId: localParticipant.value?.public_id || 'self', trackName: 'audio' });
            sfuTransceiverMap.set(vtc, { participantId: localParticipant.value?.public_id || 'self', trackName: 'video' });
        }

        // 2. Initial Offer
        const offer = await sfuPc.createOffer();
        await sfuPc.setLocalDescription(offer);

        const trackObjects = sfuPc.getTransceivers()
            .filter((t) => t.sender.track)
            .map((t) => ({
                location: "local",
                mid: t.mid,
                trackName: t.sender.track!.kind,
            }));

        trace('SFU', 'Creating New Session on Backend', trackObjects);
        
        const sessionRes = await meetingService.sfuSessionNew(
            meeting.value!.public_id,
            mungeSdp(sfuPc.localDescription!.sdp),
            trackObjects
        );

        if (sessionRes.sessionDescription) {
            await sfuPc.setRemoteDescription(new RTCSessionDescription(sessionRes.sessionDescription));
        }

        if (sessionRes.sessionId) {
            sfuSessionId.value = sessionRes.sessionId;
            trace('SFU', 'Session Established', sessionRes.sessionId);

            if (sfuSessionId.value && trackObjects.length > 0) {
                // Double Tap: Primary track registration
                // We skip this if trackObjects is empty (Cold Start) to avoid 406 error.
                trace('SFU', 'Explicitly registering tracks via sfuSessionTracks (Double Tap)...', trackObjects);
                try {
                    await meetingService.sfuSessionTracks(
                        meeting.value!.public_id,
                        sfuSessionId.value!,
                        trackObjects
                    );
                } catch (e) {
                    trace('SFU', 'Double Tap track registration warning:', e);
                }
            }

            // 3. Wait for ICE connection then signal ready
            await iceConnectedPromise;

            // 4. Signal readiness to others
            broadcastSfuMediaReady();
        }
    }

    async function pullParticipantTracks(participantPublicId: string, remoteSessionId: string, audioMid?: string, videoMid?: string, screenMid?: string) {
        if (!sfuPc || !sfuSessionId.value) return;
        // Queue to avoid re-entry collisions
        return runInSFUQueue(async () => {
            trace('SFU', `Pulling tracks for ${participantPublicId}...`);
            const tracksToPull: any[] = [];

            if (audioMid) {
                tracksToPull.push({ location: "remote", sessionId: remoteSessionId, trackName: "audio" });
            }
            if (videoMid) {
                tracksToPull.push({ location: "remote", sessionId: remoteSessionId, trackName: "video" });
            }
            if (screenMid) {
                tracksToPull.push({ location: "remote", sessionId: remoteSessionId, trackName: "screen" });
            }

            // Add recvonly transceivers for tracks we want to pull if they don't exist
            // We don't need to map MIDs here, as the server will provide them in the answer.
            if (audioMid && !sfuPc!.getTransceivers().find(t => t.direction === "recvonly" && sfuTransceiverMap.get(t)?.participantId === participantPublicId && sfuTransceiverMap.get(t)?.trackName === "audio")) {
                const tc = sfuPc!.addTransceiver("audio", { direction: "recvonly" });
                sfuTransceiverMap.set(tc, { participantId: participantPublicId, trackName: "audio" });
            }
            if (videoMid && !sfuPc!.getTransceivers().find(t => t.direction === "recvonly" && sfuTransceiverMap.get(t)?.participantId === participantPublicId && sfuTransceiverMap.get(t)?.trackName === "video")) {
                const tc = sfuPc!.addTransceiver("video", { direction: "recvonly" });
                sfuTransceiverMap.set(tc, { participantId: participantPublicId, trackName: "video" });
            }
            if (screenMid && !sfuPc!.getTransceivers().find(t => t.direction === "recvonly" && sfuTransceiverMap.get(t)?.participantId === participantPublicId && sfuTransceiverMap.get(t)?.trackName === "screen")) {
                const tc = sfuPc!.addTransceiver("video", { direction: "recvonly" }); // Screen is video
                sfuTransceiverMap.set(tc, { participantId: participantPublicId, trackName: "screen" });
            }

            try {
                // Server Offer flow: Request tracks, server sends an offer, we set it and answer.
                const res = await meetingService.sfuSessionTracks(
                    meeting.value!.public_id,
                    sfuSessionId.value!,
                    tracksToPull,
                    undefined // Server Offer flow: No Client SDP in initial request
                );

                if (res.sessionDescription) {
                    trace('SFU', 'Processing Server Offer for Pull', res.tracks);
                    await sfuPc!.setRemoteDescription(new RTCSessionDescription(res.sessionDescription));

                    // Update midToParticipantMap based on server's track info
                    if (Array.isArray(res.tracks)) {
                        res.tracks.forEach((t: any) => {
                            if (t.mid) {
                                midToParticipantMap.set(t.mid, t.trackName === 'screen' ? `${participantPublicId}:screen` : participantPublicId);
                            }
                        });
                    }
                    
                    flushPendingTracks();

                    const answer = await sfuPc!.createAnswer();
                    await sfuPc!.setLocalDescription(answer);

                    await meetingService.sfuSessionRenegotiate(
                        meeting.value!.public_id,
                        sfuSessionId.value!,
                        mungeSdp(answer.sdp),
                        'answer'
                    );
                }
            } catch (e) {
                console.error(`[SFU] Failed to pull tracks for ${participantPublicId}:`, e);
            }
        });
    }

    async function addLocalStream(stream: MediaStream | null) {
        localStream.value = stream;
        if (stream) {
            startAudioAnalysis(localParticipant.value?.public_id || 'self', stream);
        }
        await initSFU(stream);
    }

    // Lightweight setter — only stores the stream without starting SFU.
    // Used by Lobby to carry the stream into the Room without triggering initSFU early.
    function setStream(stream: MediaStream | null) {
        localStream.value = stream;
    }

    function setSpotlight(publicId: string | null) {
        pinnedParticipantId.value = publicId;
        trace('SPOTLIGHT', `Pinned participant: ${publicId || 'NONE'}`);
    }

    function clearSpotlight() {
        pinnedParticipantId.value = null;
    }

    function startAudioAnalysis(id: string, stream: MediaStream) {
        const idLower = id.toLowerCase();
        if (audioAnalysers.has(idLower)) stopAudioAnalysis(idLower);

        if (stream.getAudioTracks().length === 0) return;

        try {
            const AudioContextClass = (window as any).AudioContext || (window as any).webkitAudioContext;
            const context = new AudioContextClass();
            const source = context.createMediaStreamSource(stream);
            const analyser = context.createAnalyser();
            analyser.fftSize = 256;
            analyser.smoothingTimeConstant = 0.8;
            source.connect(analyser);

            const dataArray = new Uint8Array(analyser.frequencyBinCount);

            const interval = setInterval(() => {
                analyser.getByteFrequencyData(dataArray);
                let volume = 0;
                for (let i = 0; i < dataArray.length; i++) {
                    volume += dataArray[i];
                }
                const average = volume / dataArray.length;

                if (average > 15) {
                    talkingParticipants.value.add(idLower);
                    if (idLower !== localParticipant.value?.public_id?.toLowerCase()) {
                        activeSpeakerId.value = idLower;
                    }
                } else {
                    talkingParticipants.value.delete(idLower);
                }
            }, 100);

            audioAnalysers.set(idLower, { context, source, analyser, interval });
        } catch (e) {
            trace('ERROR', `Audio analysis failed for ${idLower}`, e);
        }
    }

    function stopAudioAnalysis(id: string) {
        const idLower = id.toLowerCase();
        const entry = audioAnalysers.get(idLower);
        if (entry) {
            clearInterval(entry.interval);
            entry.context.close().catch(() => {});
            audioAnalysers.delete(idLower);
        }
        talkingParticipants.value.delete(idLower);
    }

    function toggleHand() {
        if (!localParticipant.value) return;
        const publicId = localParticipant.value.public_id;
        const isRaised = raisedHands.value.has(publicId);
        const newState = !isRaised;

        if (newState) {
            raisedHands.value = new Set(raisedHands.value).add(publicId);
        } else {
            const newSet = new Set(raisedHands.value);
            newSet.delete(publicId);
            raisedHands.value = newSet;
        }

        meetingService.sendSignal(meeting.value!.public_id, {
            signal_type: 'hand-toggle',
            signal_data: { raised: newState },
            sender_participant_public_id: publicId
        });
    }

    async function replaceTrack(kind: 'audio' | 'video', newTrack: MediaStreamTrack | null) {
        if (!sfuPc || !sfuSessionId.value) return;

        trace('MEDIA', `Replacing ${kind} track`, { hasNewTrack: !!newTrack });

        // Find the transceiver assigned to this track name for 'self'
        const tc = sfuPc.getTransceivers().find(t => sfuTransceiverMap.get(t)?.trackName === kind && sfuTransceiverMap.get(t)?.participantId === (localParticipant.value?.public_id || 'self'));
        
        if (tc) {
            let directionChanged = false;
            if (newTrack && tc.direction !== 'sendonly') {
                tc.direction = 'sendonly';
                directionChanged = true;
                trace('MEDIA', `Upgraded ${kind} transceiver to sendonly`);
            } else if (!newTrack && tc.direction !== 'inactive') { // Change to 'inactive' when stopping sending
                tc.direction = 'inactive';
                directionChanged = true;
                trace('MEDIA', `Downgraded ${kind} transceiver to inactive`);
            }

            await tc.sender.replaceTrack(newTrack);

            if (directionChanged) {
                // If the transceiver direction actually changed, we MUST renegotiate with the backend
                // so it knows this MID is now a local publishing track (or stopped being one).
                trace('MEDIA', `Renegotiating ${kind} due to direction change`);
                const offer = await sfuPc.createOffer();
                await sfuPc.setLocalDescription(offer);

                try {
                    const res = await meetingService.sfuSessionTracks(
                        meeting.value!.public_id,
                        sfuSessionId.value!,
                        [
                            {
                                location: "local",
                                mid: tc.mid,
                                trackName: kind,
                            }
                        ],
                        mungeSdp(sfuPc.localDescription!.sdp!)
                    );

                    if (res.sessionDescription) {
                        await sfuPc.setRemoteDescription(new RTCSessionDescription(res.sessionDescription));
                        trace('MEDIA', `Renegotiation for ${kind} successful`);
                    }
                } catch (e) {
                    console.error(`[SFU] Failed to renegotiate ${kind} direction:`, e);
                }
            } else {
                trace('MEDIA', `Successfully replaced ${kind} track via replaceTrack (no renegotiation needed)`);
            }
        } else {
            trace('MEDIA', `Could not find existing SFU transceiver for ${kind} to replace.`);
        }

        // IMPORTANT: Re-broadcast our media state so others pull the newest tracks if they changed
        broadcastSfuMediaReady();
    }

    async function publishScreenTrack(stream: MediaStream) {
        if (!sfuPc || !sfuSessionId.value) return;

        return runInSFUQueue(async () => {
            const track = stream.getVideoTracks()[0];
            
            // 1. ADD transceiver for screen
            const init: RTCRtpTransceiverInit = {
                direction: "sendonly",
                streams: [stream],
                sendEncodings: [
                    { rid: "l", active: true, maxBitrate: 150000, scaleResolutionDownBy: 4 },
                    { rid: "m", active: true, maxBitrate: 500000, scaleResolutionDownBy: 2 },
                    { rid: "h", active: true, maxBitrate: 1500000, scaleResolutionDownBy: 1 },
                ],
            };
            
            const tc = sfuPc!.addTransceiver(track, init);
            sfuTransceiverMap.set(tc, { participantId: localParticipant.value?.public_id || 'self', trackName: 'screen' });

            // 2. Create Offer and Register via sfuSessionTracks (important!)
            const offer = await sfuPc!.createOffer();
            await sfuPc!.setLocalDescription(offer);

            const res = await meetingService.sfuSessionTracks(
                meeting.value!.public_id,
                sfuSessionId.value!,
                [
                    {
                        location: "local",
                        mid: tc.mid,
                        trackName: "screen",
                    }
                ],
                mungeSdp(sfuPc!.localDescription!.sdp!)
            );

            if (res.sessionDescription) {
                await sfuPc!.setRemoteDescription(new RTCSessionDescription(res.sessionDescription));

                // DOUBLE TAP: Call tracks again after remote description is applied 
                // to finalize registration on the backend before we signal others.
                try {
                    await meetingService.sfuSessionTracks(
                        meeting.value!.public_id,
                        sfuSessionId.value!,
                        [
                            {
                                location: "local",
                                mid: tc.mid,
                                trackName: "screen",
                            }
                        ]
                    );
                } catch (e) {
                    console.warn("[SFU] Screen publication double-tap warning:", e);
                }
            }

            // 3. Broadcast new MIDs
            screenShares.value = new Set(screenShares.value).add(localParticipant.value!.public_id);
            broadcastSfuMediaReady();

            meetingService.sendSignal(meeting.value!.public_id, {
                signal_type: 'screen-share-toggle',
                signal_data: { sharing: true, mid: tc.mid },
                sender_participant_public_id: localParticipant.value!.public_id
            });
        });
    }

    async function unpublishScreenTrack() {
        if (!sfuPc || !sfuSessionId.value) return;

        return runInSFUQueue(async () => {
            const screenTc = sfuPc!.getTransceivers().find(t => sfuTransceiverMap.get(t)?.trackName === 'screen');
            if (!screenTc) return;

            // Stop sending locally
            await screenTc.sender.replaceTrack(null);
            screenTc.direction = 'inactive';
            
            // Renegotiate removal via sfuSessionTracks or renegotiate
            // Group call just stops signaling, but let's try a clean renegotiate answer if needed.
            // Actually, let's just use renegotiate for simple SDP update if no new tracks are added.
            const offer = await sfuPc!.createOffer();
            await sfuPc!.setLocalDescription(offer);

            try {
                const res = await meetingService.sfuSessionRenegotiate(
                    meeting.value!.public_id,
                    sfuSessionId.value!,
                    mungeSdp(offer.sdp),
                    'offer'
                );

                if (res.sessionDescription) {
                    await sfuPc!.setRemoteDescription(new RTCSessionDescription(res.sessionDescription));
                }
            } catch (e) {
                console.warn("[SFU] unpublish renegotiate failed (often safe to ignore):", e);
            }

            // Cleanup map
            sfuTransceiverMap.delete(screenTc);
            
            const newSet = new Set(screenShares.value);
            newSet.delete(localParticipant.value!.public_id);
            screenShares.value = newSet;

            meetingService.sendSignal(meeting.value!.public_id, {
                signal_type: 'screen-share-toggle',
                signal_data: { sharing: false },
                sender_participant_public_id: localParticipant.value!.public_id
            });
        });
    }

    function cleanup() {
        if (sfuPc) {
            sfuPc.close();
            sfuPc = null;
        }
        sfuSessionId.value = null;
        sfuTransceiverMap.clear();
        remoteSfuSessions.clear();

        remoteStreams.clear();
        audioAnalysers.forEach((_, id) => stopAudioAnalysis(id));
        audioAnalysers.clear();
        talkingParticipants.value.clear();
        activeSpeakerId.value = null;
        pinnedParticipantId.value = null;
        screenShares.value.clear();
        raisedHands.value.clear();
        if (echoChannel.value) {
            const meetingId = meeting.value?.public_id;
            const participantId = localParticipant.value?.public_id;
            
            if (meetingId && participantId) {
                // Broadcast that we're leaving for explicit cleanup
                meetingService.sendSignal(meetingId, {
                    signal_type: 'participant-left',
                    signal_data: {},
                    sender_participant_public_id: participantId
                });
            }

            const echo = startEcho();
            echo.leave(`meeting.${meetingId}`);
            echoChannel.value = null;
        }
        localParticipant.value = null;
    }

    // Dev Mode Actions
    function addMockParticipant() {
        const id = mockParticipants.value.length + 1;
        const public_id = `mock-p-${id}`;
        mockParticipants.value.push({
            id: -id,
            public_id,
            user_id: null,
            role: 'participant',
            status: 'admitted',
            metadata: { guest_name: `Mock Participant ${id}` },
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString()
        } as MeetingParticipant);
        
        trace('DEV', `Added mock participant ${public_id}`);
    }

    function removeMockParticipant() {
        if (mockParticipants.value.length > 0) {
            const removed = mockParticipants.value.pop();
            trace('DEV', `Removed mock participant ${removed?.public_id}`);
        }
    }
    async function admitParticipant(publicId: string) {
        if (!meeting.value) return;
        try {
            await meetingService.admitParticipant(meeting.value.public_id, publicId);
            const p = participants.value.find(p => p.public_id === publicId);
            if (p) p.status = 'admitted';
            toast.success("Participant admitted.");
        } catch (e) {
            toast.error("Failed to admit participant.");
        }
    }

    async function rejectParticipant(publicId: string) {
        if (!meeting.value) return;
        try {
            await meetingService.rejectParticipant(meeting.value.public_id, publicId);
            participants.value = participants.value.filter(p => p.public_id !== publicId);
            toast.success("Participant rejected.");
        } catch (e) {
            toast.error("Failed to reject participant.");
        }
    }

    function removeParticipant(publicId: string) {
        participants.value = participants.value.filter(p => p.public_id !== publicId);
        if (remoteStreams.has(publicId)) {
            remoteStreams.delete(publicId);
        }
    }

    async function toggleDevMode() {
        isDevMode.value = !isDevMode.value;
        if (meeting.value?.public_id) {
            try {
                const creds = await meetingService.getTurnCredentials(meeting.value.public_id);
                iceServers.value = creds.ice_servers;
            } catch (e) {
                console.warn("[MeetingStore] Failed to fetch TURN credentials, using STUN fallback", e);
                iceServers.value = [{ urls: 'stun:stun.cloudflare.com:3478' }];
            }
        }
    }

    function resetSimulation() {
        mockParticipants.value = [];
        simulatedRole.value = null;
        activeSpeakerId.value = null;
        pinnedParticipantId.value = null;
        talkingParticipants.clear();
        trace('DEV', 'Simulation reset');
    }

    function setSimulatedRole(role: 'host' | 'participant' | null) {
        simulatedRole.value = role;
    }

    const allParticipants = computed(() => {
        // Only show admitted participants who are actually in the presence channel
        // or yourself, or mocks
        return [
            ...participants.value.filter(p => 
                p.status === 'admitted' && 
                (p.public_id === localParticipant.value?.public_id || activeParticipantIds.value.has(p.public_id))
            ), 
            ...mockParticipants.value
        ];
    });

    const waitingParticipants = computed(() => {
        return participants.value.filter(p => p.status === 'waiting');
    });

    const isHost = computed(() => {
        if (simulatedRole.value) return simulatedRole.value === 'host';
        const userId = authStore.user?.id;
        const isOwner = meeting.value && userId && meeting.value.user_id == userId;
        return isOwner || localParticipant.value?.role === 'host';
    });

    return {
        meeting,
        localParticipant,
        participants,
        allParticipants,
        remoteStreams,
        raisedHands,
        localStream,
        isDevMode,
        mockParticipants,
        simulatedRole,
        isHost,
        initializeMeeting,
        addLocalStream,
        setStream,
        toggleHand,
        replaceTrack,
        cleanup,
        publishScreenTrack,
        unpublishScreenTrack,
        addMockParticipant,
        removeMockParticipant,
        removeParticipant,
        toggleDevMode,
        resetSimulation,
        setSimulatedRole,
        talkingParticipants,
        activeSpeakerId,
        pinnedParticipantId,
        setSpotlight,
        clearSpotlight,
        startAudioAnalysis,
        stopAudioAnalysis,
        screenShares,
        admitParticipant,
        rejectParticipant,
        waitingParticipants,
        activeParticipantIds,
        sfuConnectionState,
        sfuIceState
    };
});
