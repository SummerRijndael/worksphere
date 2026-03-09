import { videoCallService } from "@/services/videocall.service";
import type { CallSfuSessionManager } from "./CallSfuSessionManager";

interface CallSfuMediaManagerCallData {
    chatId: string;
    callId: string;
}

type ParticipantTransceiverState = {
    audioMid?: string;
    videoMid?: string;
    screenMid?: string;
};

export interface CallSfuMediaManagerOptions {
    getPeerConnection: () => RTCPeerConnection | null;
    setPeerConnection: (peerConnection: RTCPeerConnection | null) => void;
    getSessionId: () => string | null;
    setSessionId: (sessionId: string | null) => void;
    getCallData: () => CallSfuMediaManagerCallData | null;
    getIceServers: () => RTCIceServer[];
    getParticipants: () => Array<{ publicId: string; isSelf?: boolean }>;
    getRemoteSfuSessions: () => Map<string, string>;
    getRemoteSfuTracks: () => Map<string, { audioMid?: string; videoMid?: string }>;
    participantTransceivers: Map<string, ParticipantTransceiverState>;
    sfuSessionManager: CallSfuSessionManager;
    mungeSdp: (sdp: string) => string;
    trace: (area: string, message: string, data?: any) => void;
    onRemoteMainStream: (participantId: string, stream: MediaStream) => void;
    onRemoteScreenStream: (participantId: string, stream: MediaStream) => void;
    onRemoteTrackInactive: (
        participantId: string,
        track: MediaStreamTrack,
    ) => void;
    flushPendingTracks: () => void;
    onHandle406Rescue: () => Promise<boolean>;
    setScreenMid: (mid: string | null) => void;
}

export class CallSfuMediaManager {
    private readonly participantPullInFlight = new Map<string, string>();
    private readonly screenPullInFlight = new Map<string, string>();

    constructor(private readonly options: CallSfuMediaManagerOptions) {}

    private runInQueue(fn: () => Promise<void>): Promise<void> {
        return this.options.sfuSessionManager.runInQueue(
            fn,
            (queuedGeneration, currentGeneration) => {
                console.log(
                    `[SFU] Skipping queued task from generation ${queuedGeneration} (current: ${currentGeneration})`,
                );
            },
        );
    }

    private findLocalTransceiver(
        pc: RTCPeerConnection,
        kind: "audio" | "video",
    ): RTCRtpTransceiver | undefined {
        const byAssociation = pc.getTransceivers().find((transceiver) => {
            const association =
                this.options.sfuSessionManager.getTransceiverAssociation(
                    transceiver,
                );
            return (
                association?.participantId === "self" &&
                association.trackName === kind
            );
        });
        if (byAssociation) return byAssociation;

        return pc
            .getTransceivers()
            .find(
                (transceiver) =>
                    transceiver.direction === "sendonly" &&
                    transceiver.receiver.track.kind === kind,
            );
    }

    private broadcastLocalMediaReady(
        pc: RTCPeerConnection,
        callData: CallSfuMediaManagerCallData,
        sessionId: string,
    ): void {
        const trackObjects = pc
            .getTransceivers()
            .filter(
                (transceiver) =>
                    transceiver.mid !== null &&
                    !!transceiver.sender.track &&
                    (transceiver.sender.track.kind === "audio" ||
                        transceiver.sender.track.kind === "video"),
            )
            .map((transceiver) => ({
                mid: transceiver.mid!,
                trackName: transceiver.sender.track!.kind,
            }));

        const audioMid = trackObjects.find(
            (track) => track.trackName === "audio",
        )?.mid;
        const videoMid = trackObjects.find(
            (track) => track.trackName === "video",
        )?.mid;

        const otherParticipants = this.options
            .getParticipants()
            .filter((participant) => !participant.isSelf);

        for (const participant of otherParticipants) {
            videoCallService
                .sendSignal(
                    callData.chatId,
                    callData.callId,
                    "signal",
                    {
                        type: "sfu-media-ready",
                        sessionId,
                        audioMid,
                        videoMid,
                    } as any,
                    participant.publicId,
                )
                .catch(() => {});
        }
    }

    async joinSession(stream: MediaStream): Promise<void> {
        await this.runInQueue(async () => {
            const callData = this.options.getCallData();
            if (!callData) return;

            console.log("[SFU] Initializing RTCPeerConnection for Cloudflare Calls");

            const sfuPc = new RTCPeerConnection({
                iceServers:
                    this.options.getIceServers().length > 0
                        ? this.options.getIceServers()
                        : [{ urls: "stun:stun.cloudflare.com:3478" }],
                bundlePolicy: "max-bundle",
            });
            this.options.setPeerConnection(sfuPc);

            const iceConnectedPromise = new Promise((resolve, reject) => {
                const timeout = setTimeout(
                    () => reject("SFU Connect Timeout"),
                    15000,
                );

                const checkState = () => {
                    if (
                        sfuPc.iceConnectionState === "connected" ||
                        sfuPc.iceConnectionState === "completed"
                    ) {
                        clearTimeout(timeout);
                        resolve(true);
                    }
                };

                sfuPc.oniceconnectionstatechange = checkState;
                checkState();
            });

            const localAudioTransceiver = sfuPc.addTransceiver("audio", {
                direction: "sendonly",
            });
            this.options.sfuSessionManager.setTransceiverAssociation(
                localAudioTransceiver,
                {
                    participantId: "self",
                    trackName: "audio",
                },
            );

            const localVideoTransceiver = sfuPc.addTransceiver("video", {
                direction: "sendonly",
                sendEncodings: [
                    {
                        rid: "l",
                        active: true,
                        maxBitrate: 150000,
                        scaleResolutionDownBy: 4,
                    },
                    {
                        rid: "m",
                        active: true,
                        maxBitrate: 500000,
                        scaleResolutionDownBy: 2,
                    },
                    {
                        rid: "h",
                        active: true,
                        maxBitrate: 1500000,
                        scaleResolutionDownBy: 1,
                    },
                ],
            });
            this.options.sfuSessionManager.setTransceiverAssociation(
                localVideoTransceiver,
                {
                    participantId: "self",
                    trackName: "video",
                },
            );

            stream.getTracks().forEach((track) => {
                console.log(`[SFU] Adding local ${track.kind} track to session`);

                const placeholder = sfuPc.getTransceivers().find(
                    (t) =>
                        this.options.sfuSessionManager.getTransceiverAssociation(t)
                            ?.participantId === "self" &&
                        this.options.sfuSessionManager.getTransceiverAssociation(t)
                            ?.trackName === track.kind &&
                        t.receiver.track.kind === track.kind &&
                        t.direction === "sendonly" &&
                        !t.sender.track,
                );

                if (!placeholder) {
                    sfuPc.addTransceiver(track, {
                        direction: "sendonly",
                        streams: [stream],
                    });
                    return;
                }

                console.log(
                    `[SFU] Reserving placeholder transceiver for local ${track.kind}`,
                );
                placeholder.sender.replaceTrack(track);
                try {
                    const params = placeholder.sender.getParameters();
                    if (params.encodings && params.encodings.length > 0) {
                        params.encodings.forEach((encoding) => {
                            encoding.active = true;
                        });
                        placeholder.sender.setParameters(params).catch(() => {});
                    }
                } catch {
                    // Ignore parameter adjustment errors on cold-start.
                }
            });

            const offer = await sfuPc.createOffer();
            await sfuPc.setLocalDescription(offer);

            const trackObjects = sfuPc
                .getTransceivers()
                .filter(
                    (t) =>
                        t.mid !== null &&
                        !!t.sender.track &&
                        (t.sender.track.kind === "audio" ||
                            t.sender.track.kind === "video"),
                )
                .map((t) => ({
                    location: "local",
                    mid: t.mid,
                    trackName: t.sender.track!.kind,
                }));

            console.log("[SFU] Creating new session via backend proxy...", trackObjects);
            const sessionRes = await videoCallService.sfuSessionNew(
                callData.chatId,
                this.options.mungeSdp(sfuPc.localDescription!.sdp!),
                trackObjects,
            );

            if (sessionRes.sessionDescription) {
                await sfuPc.setRemoteDescription(
                    new RTCSessionDescription(sessionRes.sessionDescription),
                );
            }

            if (sessionRes.sessionId) {
                this.options.setSessionId(sessionRes.sessionId);
                console.log("[SFU] Session established:", sessionRes.sessionId);
                console.log(
                    "[SFU] Explicitly registering tracks via sfuSessionTracks (Double Tap)...",
                );
                try {
                    const tracksRes = await videoCallService.sfuSessionTracks(
                        callData.chatId,
                        sessionRes.sessionId,
                        trackObjects,
                        undefined,
                    );
                    if (tracksRes.sessionDescription) {
                        console.log("[SFU] Applying Double Tap SDP Answer");
                        await sfuPc.setRemoteDescription(
                            new RTCSessionDescription(tracksRes.sessionDescription),
                        );
                    }
                } catch (e) {
                    console.warn("[SFU] Double Tap track registration warning:", e);
                }
            } else {
                this.options.setSessionId(null);
            }

            console.log("[SFU] Waiting for ICE connection...");
            await iceConnectedPromise;

            this.options.trace("JOIN", "Local tracks published", { trackObjects });

            const activeSessionId = this.options.getSessionId();
            if (activeSessionId) {
                const audioMid = trackObjects.find(
                    (t) => t.trackName === "audio",
                )?.mid;
                const videoMid = trackObjects.find(
                    (t) => t.trackName === "video",
                )?.mid;
                const otherParticipants = this.options
                    .getParticipants()
                    .filter((participant) => !participant.isSelf);

                console.log(
                    `[SFU] Signaling sfu-media-ready to ${otherParticipants.length} participant(s): audio=${audioMid}, video=${videoMid}`,
                );

                for (const participant of otherParticipants) {
                    videoCallService
                        .sendSignal(callData.chatId, callData.callId, "signal", {
                            type: "sfu-media-ready",
                            sessionId: activeSessionId,
                            audioMid,
                            videoMid,
                        } as any, participant.publicId)
                        .catch(() => {});
                }
            }

            sfuPc.ontrack = (event) => {
                const track = event.track;
                const mid = event.transceiver.mid;
                this.options.trace("TRACK", `ontrack: ${track.kind}, mid: ${mid}`, {
                    muted: track.muted,
                    readyState: track.readyState,
                });

                const resolveParticipantId = (): string | null => {
                    let participantId = this.options.sfuSessionManager.getParticipantByMid(
                        mid!,
                    );

                    if (participantId) {
                        return participantId;
                    }

                    const assoc =
                        this.options.sfuSessionManager.getTransceiverAssociation(
                            event.transceiver,
                        );
                    if (!assoc) return null;

                    participantId =
                        assoc.trackName === "screen"
                            ? `${assoc.participantId}:screen`
                            : assoc.participantId;
                    console.log(
                        `[SFU] Identified mid ${mid} via transceiver association: ${participantId}`,
                    );
                    return participantId;
                };

                const handleTrackActive = () => {
                    const participantId = resolveParticipantId();

                    this.options.trace(
                        "TRACK",
                        `Active: ${track.kind} (${mid}) for ${participantId}`,
                        { streams: event.streams.length },
                    );

                    if (!participantId) {
                        console.warn(
                            `[SFU] Buffering unresolved track (mid: ${mid}) — will flush when MID map is populated`,
                        );
                        this.options.sfuSessionManager.queuePendingTrackEvent({
                            track,
                            mid: mid!,
                            transceiver: event.transceiver,
                            streams: event.streams,
                        });
                        return;
                    }

                    let mediaStream = event.streams[0];
                    if (!mediaStream) {
                        mediaStream = new MediaStream([track]);
                        console.log(
                            `[SFU] Created synthetic stream for ${participantId} (${track.kind})`,
                        );
                    }

                    if (
                        track.kind === "video" &&
                        participantId.endsWith(":screen")
                    ) {
                        this.options.onRemoteScreenStream(
                            participantId.replace(":screen", ""),
                            mediaStream,
                        );
                        return;
                    }

                    this.options.onRemoteMainStream(participantId, mediaStream);
                };

                const handleTrackInactive = () => {
                    const participantId = resolveParticipantId();
                    if (!participantId) return;
                    this.options.trace(
                        "TRACK",
                        `Inactive: ${track.kind} (${mid}) for ${participantId}`,
                        {
                            muted: track.muted,
                            readyState: track.readyState,
                        },
                    );
                    this.options.onRemoteTrackInactive(participantId, track);
                };

                track.onunmute = handleTrackActive;
                track.onmute = handleTrackInactive;
                track.onended = handleTrackInactive;
                if (!track.muted) {
                    handleTrackActive();
                }
            };

            sfuPc.oniceconnectionstatechange = () => {
                const state = sfuPc.iceConnectionState;
                console.log(`[SFU] ICE connection state: ${state}`);
                this.options.sfuSessionManager.handleIceConnectionState(state, {
                    delayMs: 5000,
                    onDisconnectedStable: () => {
                        console.warn(
                            "[SFU] ICE disconnected for 5s, attempting restart",
                        );
                        this.attemptIceRestart();
                    },
                    onFailed: () => {
                        console.error(
                            "[SFU] ICE failed, attempting immediate restart",
                        );
                        this.attemptIceRestart();
                    },
                });
            };
        });
    }

    async replaceLocalTrack(
        kind: "audio" | "video",
        newTrack: MediaStreamTrack | null,
    ): Promise<boolean> {
        let replaceSucceeded = false;

        await this.runInQueue(async () => {
            const queuePc = this.options.getPeerConnection();
            const queueSessionId = this.options.getSessionId();
            const callData = this.options.getCallData();
            if (!queuePc || !queueSessionId || !callData) return;

            let transceiver = this.findLocalTransceiver(queuePc, kind);

            if (!transceiver && newTrack) {
                transceiver =
                    kind === "video"
                        ? queuePc.addTransceiver("video", {
                              direction: "sendonly",
                              sendEncodings: [
                                  {
                                      rid: "l",
                                      active: true,
                                      maxBitrate: 150000,
                                      scaleResolutionDownBy: 4,
                                  },
                                  {
                                      rid: "m",
                                      active: true,
                                      maxBitrate: 500000,
                                      scaleResolutionDownBy: 2,
                                  },
                                  {
                                      rid: "h",
                                      active: true,
                                      maxBitrate: 1500000,
                                      scaleResolutionDownBy: 1,
                                  },
                              ],
                          })
                        : queuePc.addTransceiver("audio", {
                              direction: "sendonly",
                          });
                this.options.sfuSessionManager.setTransceiverAssociation(
                    transceiver,
                    {
                        participantId: "self",
                        trackName: kind,
                    },
                );
            }

            if (!transceiver) return;

            try {
                await transceiver.sender.replaceTrack(newTrack);

                // Cloudflare needs track registration for newly-published camera track.
                if (kind === "video" && newTrack) {
                    await queuePc.setLocalDescription(await queuePc.createOffer());
                    if (!transceiver.mid) {
                        throw new Error(
                            "[SFU] Local video transceiver has no MID after offer.",
                        );
                    }

                    const registerRes = await videoCallService.sfuSessionTracks(
                        callData.chatId,
                        queueSessionId,
                        [
                            {
                                location: "local",
                                mid: transceiver.mid,
                                trackName: "video",
                            },
                        ],
                        this.options.mungeSdp(queuePc.localDescription!.sdp!),
                    );

                    const registeredVideo = Array.isArray(registerRes.tracks)
                        ? registerRes.tracks.find(
                              (track: any) =>
                                  track.trackName === "video" &&
                                  !!track.mid &&
                                  !track.errorCode,
                          )
                        : null;

                    if (!registeredVideo) {
                        throw new Error(
                            "[SFU] Local video track registration returned no valid video track.",
                        );
                    }

                    if (registerRes.sessionDescription) {
                        await queuePc.setRemoteDescription(
                            new RTCSessionDescription(
                                registerRes.sessionDescription,
                            ),
                        );
                    }
                }

                replaceSucceeded = true;
            } catch (error: any) {
                console.warn(
                    `[SFU] Failed replacing local ${kind} track`,
                    error,
                );
                if (error?.response?.status === 406) {
                    await this.options.onHandle406Rescue();
                    return;
                }
                if (queuePc.signalingState === "have-local-offer") {
                    await queuePc.setLocalDescription({
                        type: "rollback",
                    } as any);
                }
            }

            if (kind === "video" && replaceSucceeded) {
                this.broadcastLocalMediaReady(queuePc, callData, queueSessionId);
            }
        });

        return replaceSucceeded;
    }

    async attemptIceRestart(): Promise<void> {
        const sfuPc = this.options.getPeerConnection();
        const sfuSessionId = this.options.getSessionId();
        const callData = this.options.getCallData();

        if (!sfuPc || !sfuSessionId || !callData) {
            console.warn("[SFU] Cannot restart ICE: no active SFU session");
            return;
        }

        try {
            console.log("[SFU] Creating ICE restart offer");
            const offer = await sfuPc.createOffer({ iceRestart: true });
            await sfuPc.setLocalDescription(offer);

            const res = await videoCallService.sfuSessionRenegotiate(
                callData.chatId,
                sfuSessionId,
                this.options.mungeSdp(offer.sdp!),
                "offer",
            );

            if (res?.sessionDescription) {
                await sfuPc.setRemoteDescription(
                    new RTCSessionDescription(res.sessionDescription),
                );
                console.log("[SFU] ICE restart completed successfully");
            }
        } catch (err) {
            console.error(
                "[SFU] ICE restart failed, may need full session reset:",
                err,
            );
        }
    }

    async pullParticipantTracks(
        participantPublicId: string,
        remoteSessionId?: string,
        remoteAudioMid?: string,
        remoteVideoMid?: string,
    ): Promise<void> {
        const sfuPc = this.options.getPeerConnection();
        const sfuSessionId = this.options.getSessionId();

        if (!sfuPc || !sfuSessionId) return;

        const targetSessionId =
            remoteSessionId ||
            this.options.getRemoteSfuSessions().get(participantPublicId);
        const persistedTracks =
            this.options.getRemoteSfuTracks().get(participantPublicId);

        const actualAudioMid = remoteAudioMid || persistedTracks?.audioMid;
        const actualVideoMid = remoteVideoMid || persistedTracks?.videoMid;

        if (!targetSessionId) {
            console.warn(
                `[SFU] Cannot pull tracks for ${participantPublicId}: session ID unknown yet`,
            );
            return;
        }

        const requestFingerprint = [
            targetSessionId || "",
            actualAudioMid || "",
            actualVideoMid || "",
        ].join("|");

        const inFlight = this.participantPullInFlight.get(participantPublicId);
        if (inFlight) {
            if (inFlight === requestFingerprint) {
                console.log(
                    `[SFU] Pull already in-flight for ${participantPublicId}, skipping duplicate request`,
                );
                return;
            }

            setTimeout(
                () =>
                    this.pullParticipantTracks(
                        participantPublicId,
                        remoteSessionId,
                        remoteAudioMid,
                        remoteVideoMid,
                    ),
                350,
            );
            return;
        }

        const existingParticipantMids =
            this.options.participantTransceivers.get(participantPublicId);
        const alreadyHasRequestedMids =
            !!existingParticipantMids &&
            (!actualAudioMid ||
                existingParticipantMids.audioMid === actualAudioMid) &&
            (!actualVideoMid ||
                existingParticipantMids.videoMid === actualVideoMid) &&
            !!(actualAudioMid || actualVideoMid);
        if (alreadyHasRequestedMids) {
            console.log(
                `[SFU] Already synchronized mids for ${participantPublicId}, skipping redundant pull`,
            );
            return;
        }

        const currentAttempts =
            this.options.sfuSessionManager.nextParticipantPullAttempt(
                participantPublicId,
            );
        const retryDelays = [1000, 1500, 2000, 3000, 5000];
        if (currentAttempts > retryDelays.length) {
            console.error(
                `[SFU] Failed to pull tracks for ${participantPublicId} after ${retryDelays.length} attempts. Giving up.`,
            );
            this.options.sfuSessionManager.clearParticipantPullAttempt(
                participantPublicId,
            );
            return;
        }

        const trackReqs: any[] = [];
        const hasAnyKnownMid = Boolean(actualAudioMid || actualVideoMid);
        if (actualAudioMid) {
            trackReqs.push({
                location: "remote",
                sessionId: targetSessionId,
                trackName: "audio",
            });
        }
        if (actualVideoMid) {
            trackReqs.push({
                location: "remote",
                sessionId: targetSessionId,
                trackName: "video",
            });
        }
        if (!hasAnyKnownMid && currentAttempts === 1) {
            trackReqs.push(
                {
                    location: "remote",
                    sessionId: targetSessionId,
                    trackName: "audio",
                },
                {
                    location: "remote",
                    sessionId: targetSessionId,
                    trackName: "video",
                },
            );
        }

        if (trackReqs.length === 0) {
            console.warn(
                `[SFU] No tracks to pull for ${participantPublicId}, skipping.`,
            );
            return;
        }

        this.participantPullInFlight.set(participantPublicId, requestFingerprint);
        try {
            await this.runInQueue(async () => {
                const queuePc = this.options.getPeerConnection();
                const queueSessionId = this.options.getSessionId();
                const callData = this.options.getCallData();
                if (!queuePc || !queueSessionId || !callData) return;

                const queuedExistingParticipantMids =
                    this.options.participantTransceivers.get(
                        participantPublicId,
                    );
                const queuedAlreadyHasRequestedMids =
                    !!queuedExistingParticipantMids &&
                    (!actualAudioMid ||
                        queuedExistingParticipantMids.audioMid === actualAudioMid) &&
                    (!actualVideoMid ||
                        queuedExistingParticipantMids.videoMid === actualVideoMid) &&
                    !!(actualAudioMid || actualVideoMid);
                if (queuedAlreadyHasRequestedMids) {
                    console.log(
                        `[SFU] Participant ${participantPublicId} already synchronized while queued, skipping`,
                    );
                    return;
                }

                try {
                    console.log(
                        `[SFU] Attempt ${currentAttempts}: Handshaking tracks for ${participantPublicId}...`,
                    );

                    let audioTransceiver: RTCRtpTransceiver | undefined;
                    let videoTransceiver: RTCRtpTransceiver | undefined;

                    const wantsAudio = trackReqs.some(
                        (request) => request.trackName === "audio",
                    );
                    const wantsVideo = trackReqs.some(
                        (request) => request.trackName === "video",
                    );

                    if (wantsAudio) {
                        audioTransceiver = queuePc
                            .getTransceivers()
                            .find(
                                (t) =>
                                    t.direction === "recvonly" &&
                                    this.options.sfuSessionManager.getTransceiverAssociation(
                                        t,
                                    )?.participantId === participantPublicId &&
                                    this.options.sfuSessionManager.getTransceiverAssociation(
                                        t,
                                    )?.trackName === "audio",
                            );

                        if (!audioTransceiver) {
                            audioTransceiver = queuePc.addTransceiver("audio", {
                                direction: "recvonly",
                            });
                            this.options.sfuSessionManager.setTransceiverAssociation(
                                audioTransceiver,
                                {
                                    participantId: participantPublicId,
                                    trackName: "audio",
                                },
                            );
                        }
                    }

                    if (wantsVideo) {
                        videoTransceiver = queuePc
                            .getTransceivers()
                            .find(
                                (t) =>
                                    t.direction === "recvonly" &&
                                    this.options.sfuSessionManager.getTransceiverAssociation(
                                        t,
                                    )?.participantId === participantPublicId &&
                                    this.options.sfuSessionManager.getTransceiverAssociation(
                                        t,
                                    )?.trackName === "video",
                            );

                        if (!videoTransceiver) {
                            videoTransceiver = queuePc.addTransceiver("video", {
                                direction: "recvonly",
                            });
                            this.options.sfuSessionManager.setTransceiverAssociation(
                                videoTransceiver,
                                {
                                    participantId: participantPublicId,
                                    trackName: "video",
                                },
                            );
                        }
                    }

                    const trackReqsWithMid = trackReqs.map((req) => {
                        if (req.trackName === "audio") {
                            return {
                                ...req,
                                mid: audioTransceiver?.mid || undefined,
                            };
                        }
                        if (req.trackName === "video") {
                            return {
                                ...req,
                                mid: videoTransceiver?.mid || undefined,
                            };
                        }
                        return req;
                    });

                    const res = await videoCallService.sfuSessionTracks(
                        callData.chatId,
                        queueSessionId,
                        trackReqsWithMid,
                        undefined,
                    );

                    const foundAny =
                        Array.isArray(res.tracks) &&
                        res.tracks.some((t: any) => t.mid && !t.errorCode);

                    if (!foundAny) {
                        console.warn(
                            `[SFU] Pull attempt ${currentAttempts} for ${participantPublicId} returned no valid tracks. Rescheduling...`,
                        );
                        const delay = retryDelays[currentAttempts - 1] || 1000;
                        setTimeout(
                            () =>
                                this.pullParticipantTracks(
                                    participantPublicId,
                                    remoteSessionId,
                                    remoteAudioMid,
                                    remoteVideoMid,
                                ),
                            delay,
                        );
                        return;
                    }

                    console.log(
                        `[SFU] Track pull success on attempt ${currentAttempts} for ${participantPublicId}`,
                    );
                    this.options.sfuSessionManager.clearParticipantPullAttempt(
                        participantPublicId,
                    );

                    if (res.sessionDescription && Array.isArray(res.tracks)) {
                        res.tracks.forEach((track: any) => {
                            if (!track.mid) return;
                            this.options.sfuSessionManager.mapMid(
                                track.mid,
                                participantPublicId,
                            );

                            const transceiver = queuePc
                                .getTransceivers()
                                .find((tr) => tr.mid === track.mid);
                            if (transceiver) {
                                this.options.sfuSessionManager.setTransceiverAssociation(
                                    transceiver,
                                    {
                                        participantId: participantPublicId,
                                        trackName: track.trackName,
                                    },
                                );
                            }
                        });

                        this.options.flushPendingTracks();

                        console.log(
                            `[SFU] Processing Server Offer for tracks from ${participantPublicId}`,
                        );

                        await queuePc.setRemoteDescription(
                            new RTCSessionDescription(res.sessionDescription),
                        );

                        const answer = await queuePc.createAnswer();
                        await queuePc.setLocalDescription(answer);

                        await videoCallService.sfuSessionRenegotiate(
                            callData.chatId,
                            queueSessionId,
                            this.options.mungeSdp(answer.sdp!),
                            "answer",
                            "PUT",
                        );

                        const existingState =
                            this.options.participantTransceivers.get(
                                participantPublicId,
                            ) || {};
                        this.options.participantTransceivers.set(
                            participantPublicId,
                            {
                                ...existingState,
                                audioMid:
                                    res.tracks?.find(
                                        (t: any) => t.trackName === "audio",
                                    )?.mid || existingState.audioMid || "",
                                videoMid:
                                    res.tracks?.find(
                                        (t: any) => t.trackName === "video",
                                    )?.mid || existingState.videoMid || "",
                            },
                        );
                    }
                } catch (e: any) {
                    console.warn(
                        `[SFU] Pull attempt ${currentAttempts} failed for ${participantPublicId}`,
                        e,
                    );
                    if (e.response?.status === 406) {
                        await this.options.onHandle406Rescue();
                        return;
                    }

                    const delay = retryDelays[currentAttempts - 1] || 1000;
                    setTimeout(
                        () =>
                            this.pullParticipantTracks(
                                participantPublicId,
                                remoteSessionId,
                                remoteAudioMid,
                                remoteVideoMid,
                            ),
                        delay,
                    );
                }
            });
        } finally {
            if (
                this.participantPullInFlight.get(participantPublicId) ===
                requestFingerprint
            ) {
                this.participantPullInFlight.delete(participantPublicId);
            }
        }
    }

    async pullRemoteScreen(
        participantPublicId: string,
        mid: string,
        remoteSessionId?: string,
    ): Promise<void> {
        const sfuPc = this.options.getPeerConnection();
        const sfuSessionId = this.options.getSessionId();
        if (!sfuPc || !sfuSessionId) return;

        if (this.options.participantTransceivers.get(participantPublicId)?.screenMid === mid) {
            console.log(
                `[SFU] Already have screen share mid ${mid} for ${participantPublicId}`,
            );
            return;
        }

        const targetSessionId =
            remoteSessionId ||
            this.options.getRemoteSfuSessions().get(participantPublicId);
        if (!targetSessionId) {
            console.warn(
                `[SFU] Cannot pull screen for ${participantPublicId}: session ID unknown`,
            );
            return;
        }

        const requestFingerprint = `${targetSessionId}|${mid || ""}`;
        const inFlight = this.screenPullInFlight.get(participantPublicId);
        if (inFlight) {
            if (inFlight === requestFingerprint) {
                console.log(
                    `[SFU] Screen pull already in-flight for ${participantPublicId}, skipping duplicate request`,
                );
                return;
            }

            setTimeout(
                () =>
                    this.pullRemoteScreen(
                        participantPublicId,
                        mid,
                        remoteSessionId,
                    ),
                350,
            );
            return;
        }

        const currentAttempts = this.options.sfuSessionManager.nextScreenPullAttempt(
            participantPublicId,
        );
        const retryDelays = [1000, 1500, 2000, 3000, 5000];
        if (currentAttempts > retryDelays.length) {
            console.error(
                `[SFU] Failed to pull screen from ${participantPublicId} after ${retryDelays.length} attempts.`,
            );
            this.options.sfuSessionManager.clearScreenPullAttempt(
                participantPublicId,
            );
            return;
        }

        const trackReqs = [
            {
                location: "remote",
                sessionId: targetSessionId,
                trackName: "screen",
            },
        ];

        this.screenPullInFlight.set(participantPublicId, requestFingerprint);
        try {
            await this.runInQueue(async () => {
                const queuePc = this.options.getPeerConnection();
                const queueSessionId = this.options.getSessionId();
                const callData = this.options.getCallData();
                if (!queuePc || !queueSessionId || !callData) return;

                try {
                console.log(
                    `[SFU] Attempt ${currentAttempts}: Handshaking screen for ${participantPublicId}...`,
                );

                let transceiver = queuePc
                    .getTransceivers()
                    .find(
                        (t) =>
                            t.direction === "recvonly" &&
                            this.options.sfuSessionManager.getTransceiverAssociation(
                                t,
                            )?.participantId === participantPublicId &&
                            this.options.sfuSessionManager.getTransceiverAssociation(
                                t,
                            )?.trackName === "screen",
                    );

                if (!transceiver) {
                    transceiver = queuePc.addTransceiver("video", {
                        direction: "recvonly",
                    });
                    this.options.sfuSessionManager.setTransceiverAssociation(
                        transceiver,
                        {
                            participantId: participantPublicId,
                            trackName: "screen",
                        },
                    );
                }

                const tracksRes = await videoCallService.sfuSessionTracks(
                    callData.chatId,
                    queueSessionId,
                    trackReqs,
                    undefined,
                );

                const foundAny =
                    Array.isArray(tracksRes.tracks) &&
                    tracksRes.tracks.some((t: any) => t.mid && !t.errorCode);
                const hasNotFoundError =
                    Array.isArray(tracksRes.tracks) &&
                    tracksRes.tracks.some(
                        (t: any) =>
                            t.errorCode === "not_found_track_error" ||
                            t.errorCode === "internal_error",
                    );

                if (!foundAny) {
                    console.warn(
                        `[SFU] Screen pull attempt ${currentAttempts} failed (no valid tracks).${
                            hasNotFoundError ? " (Tracks Not Found Yet)" : ""
                        } Rescheduling...`,
                    );
                    const delay = retryDelays[currentAttempts - 1] || 1000;
                    setTimeout(
                        () =>
                            this.pullRemoteScreen(
                                participantPublicId,
                                mid,
                                remoteSessionId,
                            ),
                        delay,
                    );
                    return;
                }

                console.log(
                    `[SFU] Screen pull success on attempt ${currentAttempts} for ${participantPublicId}`,
                );
                this.options.sfuSessionManager.clearScreenPullAttempt(
                    participantPublicId,
                );

                if (tracksRes.sessionDescription) {
                    console.log(
                        `[SFU] Processing Server Offer for screen track from ${participantPublicId}`,
                    );
                    await queuePc.setRemoteDescription(
                        new RTCSessionDescription(tracksRes.sessionDescription),
                    );

                    if (Array.isArray(tracksRes.tracks)) {
                        tracksRes.tracks.forEach((track: any) => {
                            if (!track.mid) return;
                            this.options.sfuSessionManager.mapMid(
                                track.mid,
                                `${participantPublicId}:screen`,
                            );

                            const mappedTransceiver = queuePc
                                .getTransceivers()
                                .find((tr) => tr.mid === track.mid);
                            if (mappedTransceiver) {
                                this.options.sfuSessionManager.setTransceiverAssociation(
                                    mappedTransceiver,
                                    {
                                        participantId: participantPublicId,
                                        trackName: "screen",
                                    },
                                );
                            }

                            const existing =
                                this.options.participantTransceivers.get(
                                    participantPublicId,
                                ) || {};
                            this.options.participantTransceivers.set(
                                participantPublicId,
                                {
                                    ...existing,
                                    screenMid: track.mid,
                                },
                            );
                        });
                    }

                    this.options.flushPendingTracks();

                    const answer = await queuePc.createAnswer();
                    await queuePc.setLocalDescription(answer);
                    await videoCallService.sfuSessionRenegotiate(
                        callData.chatId,
                        queueSessionId,
                        this.options.mungeSdp(answer.sdp!),
                        "answer",
                        "PUT",
                    );
                }
                } catch (err: any) {
                    console.error(
                        `[SFU] Screen pull attempt ${currentAttempts} failed (exception).`,
                        err,
                    );
                    if (err.response?.status === 406) {
                        await this.options.onHandle406Rescue();
                    }
                    const delay = retryDelays[currentAttempts - 1] || 1000;
                    setTimeout(
                        () =>
                            this.pullRemoteScreen(
                                participantPublicId,
                                mid,
                                remoteSessionId,
                            ),
                        delay,
                    );
                }
            });
        } finally {
            if (
                this.screenPullInFlight.get(participantPublicId) ===
                requestFingerprint
            ) {
                this.screenPullInFlight.delete(participantPublicId);
            }
        }
    }

    async publishScreenTrack(stream: MediaStream): Promise<void> {
        const sfuPc = this.options.getPeerConnection();
        const sfuSessionId = this.options.getSessionId();
        const callData = this.options.getCallData();
        if (!sfuPc || !sfuSessionId || !callData) return;

        await this.runInQueue(async () => {
            const queuePc = this.options.getPeerConnection();
            const queueSessionId = this.options.getSessionId();
            const queueCallData = this.options.getCallData();
            if (!queuePc || !queueSessionId || !queueCallData) return;

            const track = stream.getVideoTracks()[0];
            let transceiver = queuePc.getTransceivers().find(
                (t) =>
                    t.direction === "sendonly" && !t.sender.track && t.mid === null,
            );

            if (transceiver) {
                console.log("[SFU] Reusing existing sendonly transceiver for screen");
                await transceiver.sender.replaceTrack(track);
                this.options.sfuSessionManager.setTransceiverAssociation(
                    transceiver,
                    {
                        participantId: "self",
                        trackName: "screen",
                    },
                );

                const params = transceiver.sender.getParameters();
                if (!params.encodings) {
                    params.encodings = [];
                }
                if (params.encodings.length === 0) {
                    params.encodings = [
                        {
                            rid: "l",
                            active: true,
                            maxBitrate: 150000,
                            scaleResolutionDownBy: 4,
                        },
                        {
                            rid: "m",
                            active: true,
                            maxBitrate: 500000,
                            scaleResolutionDownBy: 2,
                        },
                        {
                            rid: "h",
                            active: true,
                            maxBitrate: 1500000,
                            scaleResolutionDownBy: 1,
                        },
                    ];
                    await transceiver.sender.setParameters(params);
                }
            } else {
                console.log("[SFU] Adding new screen transceiver");
                transceiver = queuePc.addTransceiver(track, {
                    direction: "sendonly",
                    streams: [stream],
                    sendEncodings: [
                        {
                            rid: "l",
                            active: true,
                            maxBitrate: 150000,
                            scaleResolutionDownBy: 4,
                        },
                        {
                            rid: "m",
                            active: true,
                            maxBitrate: 500000,
                            scaleResolutionDownBy: 2,
                        },
                        {
                            rid: "h",
                            active: true,
                            maxBitrate: 1500000,
                            scaleResolutionDownBy: 1,
                        },
                    ],
                });
                this.options.sfuSessionManager.setTransceiverAssociation(
                    transceiver,
                    {
                        participantId: "self",
                        trackName: "screen",
                    },
                );
            }

            try {
                await queuePc.setLocalDescription(await queuePc.createOffer());
                const res = await videoCallService.sfuSessionTracks(
                    queueCallData.chatId,
                    queueSessionId,
                    [
                        {
                            location: "local",
                            mid: transceiver.mid,
                            trackName: "screen",
                        },
                    ],
                    this.options.mungeSdp(queuePc.localDescription!.sdp!),
                );

                if (res.sessionDescription) {
                    await queuePc.setRemoteDescription(
                        new RTCSessionDescription(res.sessionDescription),
                    );
                }

                await new Promise((resolve) => {
                    if (
                        queuePc.iceConnectionState === "connected" ||
                        queuePc.iceConnectionState === "completed"
                    ) {
                        setTimeout(resolve, 500);
                        return;
                    }
                    const check = setInterval(() => {
                        if (
                            queuePc.iceConnectionState === "connected" ||
                            queuePc.iceConnectionState === "completed"
                        ) {
                            clearInterval(check);
                            setTimeout(resolve, 500);
                        }
                    }, 200);
                    setTimeout(() => {
                        clearInterval(check);
                        resolve(true);
                    }, 5000);
                });

                this.options.setScreenMid(transceiver.mid);

                videoCallService
                    .sendSignal(
                        queueCallData.chatId,
                        queueCallData.callId,
                        "signal",
                        {
                            type: "sfu-screen-share-started",
                            mid: transceiver.mid,
                            sessionId: queueSessionId,
                        },
                    )
                    .catch(() => {});
            } catch (e: any) {
                console.warn("[SFU] Failed to publish screen track", e);
                if (e.response?.status === 406) {
                    if (await this.options.onHandle406Rescue()) {
                        this.publishScreenTrack(stream);
                    }
                } else if (queuePc.signalingState === "have-local-offer") {
                    await queuePc.setLocalDescription({ type: "rollback" } as any);
                }
            }
        });
    }

    stopScreenShareSignal(): void {
        this.options.setScreenMid(null);
        const callData = this.options.getCallData();
        if (!callData) return;

        videoCallService
            .sendSignal(callData.chatId, callData.callId, "signal", {
                type: "sfu-screen-share-stopped",
            })
            .catch(() => {});
    }
}
