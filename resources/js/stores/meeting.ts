import { defineStore } from 'pinia';
import { ref, reactive, computed } from 'vue';
import { meetingService } from '@/services/meeting.service';
import type { Meeting, MeetingParticipant } from '@/types/models';
import { createLogger } from './managers/logger';
import { useAuthStore } from '@/stores/auth';

import { createPresenceManager } from './managers/PresenceManager';
import { createUnifiedMediaManager } from './managers/UnifiedMediaManager';
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
    const isLockToggleBusy = ref(false);
    const originalVideoTrack = ref<MediaStreamTrack | null>(null);
    let lockHeartbeatInterval: any = null;
    const LOCK_TOGGLE_DEBOUNCE_MS = 600;
    let lastLockToggleAt = 0;

    // ── Polls ──────────────────────────────────────────────────────────────────
    interface Poll {
        public_id: string;
        question: string;
        options: string[];
        is_active: boolean;
        vote_counts: number[];
        my_votes?: number[]; // indices of what THIS participant voted
        allow_multiple: boolean;
        allow_change_vote: boolean;
        anonymous: boolean;
    }
    const activePoll = ref<Poll | null>(null);
    const recentPolls = ref<Poll[]>([]);

    // ── Laser Pointer ──────────────────────────────────────────────────────────
    interface LaserPointer {
        participantId: string;
        targetParticipantId?: string;
        x: number;
        y: number;
        lastSeen: number;
    }
    const remotePointers = reactive(new Map<string, LaserPointer>());
    const laserPointerMode = ref<'off' | 'global' | 'targeted'>('off');
    
    // ── Annotations ─────────────────────────────────────────────────────────────
    const isAnnotating = ref(false);
    const activeAnnotationTool = ref('pen');
    const activeAnnotationColor = ref('#ea4335');
    const lastAnnotationSignal = ref<any>(null);

    interface ReactionEvent {
        id: string;
        publicId: string;
        emoji: string;
        timestamp: number;
    }
    const activeReactions = ref<ReactionEvent[]>([]);
    
    // ── Breakout Rooms ─────────────────────────────────────────────────────────
    const activeBreakoutSession = ref<any>(null);
    const isTransitioningRoom = ref(false);
    const isInBreakout = ref(false);
    const currentRoomId = ref<string | null>(null);
    const currentRoomName = ref<string | null>(null);
    const breakoutTimer = ref(0);
    const showBreakoutManager = ref(false);
    let timerInterval: any = null;

    function stopBreakoutTimerTicker() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
    }

    function ensureBreakoutTimerTicker() {
        if (timerInterval || breakoutTimer.value <= 0) return;
        timerInterval = setInterval(() => {
            if (breakoutTimer.value > 0) {
                breakoutTimer.value--;
                return;
            }

            stopBreakoutTimerTicker();

            // Host auto-ends the breakout when timer reaches 0.
            if (presence.isHost.value) {
                log('BREAKOUT', 'Timer expired, auto-ending breakout session');
                endBreakout();
            }
        }, 1000);
    }

    function applyBreakoutTimerSeconds(seconds: number) {
        breakoutTimer.value = Math.max(0, Math.floor(seconds));
        if (breakoutTimer.value > 0) {
            ensureBreakoutTimerTicker();
        } else {
            stopBreakoutTimerTicker();
        }
    }

    // ── PRO Recording ──────────────────────────────────────────────────────────
    // Reactive recording state. Set via signal handlers so ALL participants see
    // the REC badge — not just the host who called start/stop.
    const isRecording = ref(false);
    const activeRecordingId = ref<string | null>(null);
    const recordingDuration = ref(0);
    const recordingTimerInterval = ref<number | null>(null);
    const meetingLink = computed(() => {
        if (!meeting.value?.public_id) return '';
        return `${window.location.origin}/m/${meeting.value.public_id}`;
    });

    const formattedRecordingDuration = computed(() => {
        const mins = Math.floor(recordingDuration.value / 60);
        const secs = recordingDuration.value % 60;
        return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    });

    function startRecordingTimer(initialDuration = 0) {
        if (recordingTimerInterval.value) clearInterval(recordingTimerInterval.value);
        recordingDuration.value = initialDuration;
        recordingTimerInterval.value = window.setInterval(() => {
            recordingDuration.value++;
        }, 1000);
    }

    function stopRecordingTimer() {
        if (recordingTimerInterval.value) {
            clearInterval(recordingTimerInterval.value);
            recordingTimerInterval.value = null;
        }
    }

    async function handleRecordingStarted(data: { recording_id: string; started_by: string; duration?: number }) {
        isRecording.value = true;
        activeRecordingId.value = data.recording_id;
        startRecordingTimer(data.duration || 0);

        // Notify all participants with a prominent privacy notice
        const { toast } = await import('vue-sonner');
        toast('Recording started', {
            description: 'This meeting is being recorded. By continuing to participate, you consent to being recorded.',
            duration: 10000,
            icon: '🔴',
        });
    }

    async function handleRecordingStopped(_data: { recording_id: string }) {
        isRecording.value = false;
        activeRecordingId.value = null;
        stopRecordingTimer();

        const { toast } = await import('vue-sonner');
        toast('Recording stopped', {
            description: 'The recording will be available in the meeting history once processed.',
            duration: 5000,
            icon: '⏹️',
        });
    }

    // 2. Initialize Sub-Managers
    const layout = createLayoutManager(meeting, localParticipant);
    
    const presence = createPresenceManager(meeting, localParticipant, currentRoomId, (pid) => {
        stream.removeParticipantStreams(pid);
        stream.removeParticipantStreams(`${pid}:screen`);
    });
    
    const stream = createUnifiedMediaManager(
        meeting, 
        localParticipant, 
        iceServers,
        currentRoomId,
        (id, isTalking) => {
            presence.setTalking(id, isTalking);
            if (isTalking) layout.setActiveSpeaker(id);
        },
        (id, isSharing) => {
            presence.toggleScreenShareState(id, isSharing);
        },
        (audioMid, videoMid, screenMid) => {
            signaling.broadcastSfuMediaReady(audioMid, videoMid, screenMid, currentRoomId.value);
        },
        (err) => {
            log('ERROR', 'Media Engine Error encountered', err);
        }
    );

    const signaling = createSignalingManager(
        meeting, 
        localParticipant, 
        presence, 
        stream,
        async () => {
            log('SYS', 'onAdmittedCallback triggered - initializing media engine');
            await initMediaEngine();

            // Admission transition guard: refresh roster/state so waiting-room joins
            // can reliably see existing participants immediately after admission.
            if (meeting.value?.public_id) {
                try {
                    const latest = await meetingService.getMeeting(meeting.value.public_id) as any;
                    const latestParticipants = (latest?.participants || []).map((p: any) => ({
                        ...p,
                        public_id: String(p.public_id || '').toLowerCase(),
                    }));

                    meeting.value = { ...meeting.value, ...latest };
                    presence.participants.value = latestParticipants;

                    const me = latestParticipants.find(
                        (p: any) => p.public_id === localParticipant.value?.public_id?.toLowerCase()
                    );
                    if (me) {
                        localParticipant.value = me;
                    }
                } catch (e) {
                    log('ERROR', 'Failed to refresh meeting roster after admission', e);
                }
            }
            
            // Fetch any polls that were already created before we joined
            if (meeting.value?.public_id) {
                try {
                    const { default: api } = await import('@/lib/api');
                    const res = await api.get(`/api/meetings/${meeting.value.public_id}/polls`);
                    const polls: any[] = res.data?.data ?? [];
                    const active = polls.find(p => p.is_active) ?? null;
                    const recent = polls.filter(p => !p.is_active);
                    if (active) {
                        // Normalize my_votes if it comes from the API
                        if (active.my_vote !== undefined && !Array.isArray(active.my_vote)) {
                            active.my_votes = [active.my_vote];
                        }
                    }
                    activePoll.value = active;
                    recentPolls.value = recent;
                } catch {
                    // Non-critical — polls will still appear via Pusher events
                }
            }
        }
    );

    // 3. High Level Orchestrator Methods
    async function initializeMeeting(meetingId: string, participantPublicId: string) {
         try {
             log('SYS', `Initializing meeting ${meetingId} for participant ${participantPublicId}`);
             const data = await meetingService.getMeeting(meetingId, participantPublicId) as any;
             
            const normalizeParticipantId = (value: unknown) =>
                String(value ?? "").trim().toLowerCase();

             // Extract participants, canonicalize IDs, and dedupe by participant ID.
             // Echo presence payload uses lowercased IDs; keeping bootstrap data in the same
             // format prevents duplicate "same user" rows (UPPERCASE + lowercase variants).
             const participants = Array.from(
                 (Array.isArray(data.participants) ? data.participants : []).reduce(
                     (acc: Map<string, any>, item: any) => {
                         const pid = normalizeParticipantId(item?.public_id);
                         if (!pid) return acc;

                         const existing = acc.get(pid);
                         if (!existing) {
                             acc.set(pid, {
                                 ...item,
                                 public_id: pid,
                             });
                             return acc;
                         }

                         acc.set(pid, {
                             ...existing,
                             ...item,
                             public_id: pid,
                             user: item?.user ?? existing?.user ?? null,
                             metadata: {
                                 ...(existing?.metadata || {}),
                                 ...(item?.metadata || {}),
                             },
                         });
                         return acc;
                     },
                     new Map<string, any>(),
                 ).values(),
             );
             log('SYS', 'Meeting bootstrap payload', {
                 keys: Object.keys(data || {}),
                 requested_participant: participantPublicId,
                 requested_participant_normalized: String(participantPublicId || '').trim().toLowerCase(),
                 participant_count: Array.isArray(participants) ? participants.length : 0,
                 participant_ids: Array.isArray(participants)
                     ? participants.map((p: any) => p?.public_id).filter(Boolean)
                     : [],
                 participant_ids_normalized: Array.isArray(participants)
                     ? participants
                           .map((p: any) => String(p?.public_id || '').trim().toLowerCase())
                           .filter(Boolean)
                     : [],
             });
             // Find local participant in the fetched data
             const normalizedParticipantId = normalizeParticipantId(participantPublicId);
             let found = participants.find(
                 (p: any) => normalizeParticipantId(p?.public_id) === normalizedParticipantId
             ) || null;
             
             // --- SECURITY GUARD: PRE-COMMIT VALIDATION ---
             const authStore = useAuthStore();
            const currentPublicId = authStore.user?.public_id || 'Guest';
            const normalizedCurrentUserPublicId = normalizeParticipantId(authStore.user?.public_id);
             if (!found && authStore.user?.public_id) {
                 const authUserPid = String(authStore.user.public_id).toLowerCase();
                 const byAuthUser = participants.find(
                     (p: any) => String(p?.user?.public_id || '').toLowerCase() === authUserPid
                 ) || null;
                 if (byAuthUser) {
                     log('SECURITY', 'Participant token mismatch; falling back to authenticated participant record.', {
                         requested_participant: normalizedParticipantId,
                         resolved_participant: byAuthUser.public_id,
                     });
                     found = byAuthUser;
                 }
             }
            if (!found) {
                log('SECURITY', 'PARTICIPANT NOT FOUND: Rejecting session.', {
                    requested_participant: normalizedParticipantId,
                    current_user_public_id: currentPublicId,
                    participant_ids: participants.map((p: any) =>
                        normalizeParticipantId(p?.public_id)
                    ),
                });
                throw new Error("Invalid Participant");
            }

            const recordUserPublicId = normalizeParticipantId(found?.user?.public_id);
            if (recordUserPublicId) {
                // Registered participant records must match the active authenticated user.
                if (!normalizedCurrentUserPublicId || recordUserPublicId !== normalizedCurrentUserPublicId) {
                    log('SECURITY', 'IDENTITY MISMATCH: Rejecting token.', {
                        requested_participant: normalizedParticipantId,
                        participant_record_user: recordUserPublicId,
                        current_user_public_id: normalizedCurrentUserPublicId || null,
                    });
                    throw new Error("Identity Mismatch");
                }
            }

             // --- COMMIT PHASE: Checks passed, set global state ---
             log('SYS', `Committing state: Local PID=${found.public_id}, Name=${found.user?.name || found.metadata?.guest_name}`);
             meeting.value = {
                ...data,
                participants,
             };
             isLocked.value = !!data.is_locked;
             laserPointerMode.value = data.settings?.laser_pointer_mode || 'off';
             localParticipant.value = found;
             presence.participants.value = participants;

             // Fetch ICE Servers separately since getMeeting doesn't return them
             const turnCreds = await meetingService.getTurnCredentials(meetingId);
             iceServers.value = turnCreds.ice_servers || [];
             
             // Setup communication channels
             signaling.setupSignaling(meetingId);
             presence.setupEcho(meetingId);
             
             // Start lock heartbeat if meeting is already locked and we are host
             if (isLocked.value && presence.isHost.value) {
                 startLockHeartbeat();
             }

             // Note: We do NOT call initSFU here. MeetingRoomView.vue will call
             // addLocalStream() next, which triggers initialization.
             if (localParticipant.value?.status === 'admitted') {
                 log('SYS', 'Participant admitted, initializing media engine');
                 await initMediaEngine();

                 // --- BREAKOUT RECOVERY ---
                 const activeSession = data.active_breakout_session;
                 if (activeSession) {
                     log('SYS', 'Active breakout session found, restoring state');
                     activeBreakoutSession.value = {
                         id: activeSession.public_id,
                         rooms: activeSession.rooms_config.map((r: any) => ({
                             ...r,
                             participants: r.participants.map((p: any) => ({
                                 ...p,
                                 public_id: p.public_id.toLowerCase()
                             }))
                         })),
                         duration: activeSession.duration_minutes,
                         started_at: activeSession.started_at
                     };

                     if ((activeSession.duration_minutes ?? 0) > 0 && activeSession.started_at) {
                         const elapsedSeconds = Math.max(
                             0,
                             Math.floor((Date.now() - new Date(activeSession.started_at).getTime()) / 1000),
                         );
                         const remainingSeconds = Math.max(
                             0,
                             Number(activeSession.duration_minutes) * 60 - elapsedSeconds,
                         );
                         applyBreakoutTimerSeconds(remainingSeconds);
                     } else {
                         applyBreakoutTimerSeconds(0);
                     }
                     
                     // Restore specific room
                     const myAssignedRoomId = localParticipant.value.assigned_room_id;
                     if (myAssignedRoomId) {
                         const room = activeSession.rooms_config.find((r: any) => String(r.id) === String(myAssignedRoomId));
                         if (room) {
                             isInBreakout.value = true;
                             currentRoomId.value = String(room.id);
                             currentRoomName.value = room.name;
                             log('SYS', `Restored to breakout room: ${room.name}`);
                         }
                     }
                 }

                 // Also fetch existing polls since we bypass onAdmittedCallback when rejoining already-admitted
                 (async () => {
                     try {
                         const { default: api } = await import('@/lib/api');
                         const res = await api.get(`/api/meetings/${meetingId}/polls`);
                         const polls: any[] = res.data?.data ?? [];
                         const active = polls.find(p => p.is_active) ?? null;
                         const recent = polls.filter(p => !p.is_active);
                         if (active) {
                             if (active.my_vote !== undefined && !Array.isArray(active.my_vote)) {
                                 active.my_votes = [active.my_vote];
                             }
                         }
                         activePoll.value = active;
                         recentPolls.value = recent;
                     } catch (e) {
                         log('ERROR', 'Initial poll fetch failed', e);
                     }
                 })();
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
         stopLockHeartbeat();
         signaling.leaveSignaling();
         presence.leaveEcho();
         stream.cleanup();
         stopLocalAudioAnalysis();
         layout.clearSpotlight();
         meeting.value = null;
         localParticipant.value = null;
         remotePointers.clear();
    }

    const localVolume = ref(0);
    let localAudioAnalyser: { context: any, source: any, analyser: any, interval: number } | null = null;
    
    function startLocalAudioAnalysis(s: MediaStream) {
        stopLocalAudioAnalysis(); // cleanup first
        const pId = localParticipant.value?.public_id;
        if (!pId || !s.getAudioTracks().length) return;

        try {
            const AudioContextClass = (window as any).AudioContext || (window as any).webkitAudioContext;
            const context = new AudioContextClass();
            const source = context.createMediaStreamSource(s);
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
                localVolume.value = average;

                if (average > 15) {
                    presence.setTalking(pId, true);
                } else {
                    presence.setTalking(pId, false);
                }
            }, 100);

            localAudioAnalyser = { context, source, analyser, interval };
        } catch (e) {
            log('ERROR', 'Local audio analysis failed', e);
        }
    }

    function stopLocalAudioAnalysis() {
        if (localAudioAnalyser) {
            window.clearInterval(localAudioAnalyser.interval);
            localAudioAnalyser.context.close().catch(() => {});
            localAudioAnalyser = null;
        }
        const pId = localParticipant.value?.public_id;
        if (pId) {
            presence.setTalking(pId, false);
        }
        localVolume.value = 0;
    }

    function toggleHand() {
        const pId = localParticipant.value?.public_id;
        if (!pId) return;
        const isRaised = !presence.raisedHands.value.has(pId);
        presence.toggleHandState(pId, isRaised);
        signaling.broadcastHandState(isRaised);
    }

    async function publishScreenTrack(s?: MediaStream) {
        if (!meeting.value || !localParticipant.value) return null;

        const restrictToModerators = !!meeting.value.settings?.screen_share_host_cohost_only;
        if (restrictToModerators && !presence.isModerator.value) {
            const { toast } = await import('vue-sonner');
            toast.error('Only host or co-host can share screen in this meeting.');
            return null;
        }

        const localId = localParticipant.value.public_id.toLowerCase();
        const activeOtherSharer = Array.from(presence.screenShares.value).find(
            (id) => String(id).toLowerCase() !== localId
        );

        if (activeOtherSharer && !presence.isModerator.value) {
            const { toast } = await import('vue-sonner');
            toast.error('Another participant is already sharing their screen.');
            return null;
        }

        let streamToPublish = s;

        // Legacy SFU REQUIRES a stream (it doesn't have a built-in prompt like the SDK)
        if (!meeting.value?.recording_enabled && !streamToPublish) {
            log('SYS', 'Legacy SFU mode: Triggering manual getDisplayMedia prompt');
            try {
                streamToPublish = await navigator.mediaDevices.getDisplayMedia({
                    video: true,
                    audio: false
                });
            } catch (e) {
                log('ERROR', 'Failed to get display media for legacy sharing', e);
                return null;
            }
        }

        const result = await stream.publishScreenTrack(streamToPublish);
        if (result && result.mid) {
            presence.toggleScreenShareState(localParticipant.value!.public_id, true);
            signaling.broadcastScreenShareState(true, result.mid);
        }
        return result;
    }

    async function unpublishScreenTrack() {
        await stream.unpublishScreenTrack();
        presence.toggleScreenShareState(localParticipant.value!.public_id, false);
        signaling.broadcastScreenShareState(false);
    }

    function setStream(newStream: MediaStream | null) {
        stream.setLocalStream(newStream);
        if (newStream) {
            startLocalAudioAnalysis(newStream);
        } else {
            stopLocalAudioAnalysis();
        }
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

        const now = Date.now();
        if (isLockToggleBusy.value || now - lastLockToggleAt < LOCK_TOGGLE_DEBOUNCE_MS) {
            return;
        }
        isLockToggleBusy.value = true;
        lastLockToggleAt = now;

        try {
            const { toast } = await import('vue-sonner');
            if (isLocked.value) {
                await meetingService.unlockMeeting(meeting.value.public_id);
                isLocked.value = false;
                stopLockHeartbeat();
                toast.success('🔓 Meeting unlocked', { description: 'New participants can join.' });
            } else {
                await meetingService.lockMeeting(meeting.value.public_id);
                isLocked.value = true;
                startLockHeartbeat();
                toast.success('🔒 Meeting locked', { description: 'No new participants can join.' });
            }
        } catch (e) {
            log('ERROR', 'Failed to toggle meeting lock', e);
            const { toast } = await import('vue-sonner');
            toast.error('Failed to toggle meeting lock');
        } finally {
            const elapsed = Date.now() - now;
            const unlockIn = Math.max(0, LOCK_TOGGLE_DEBOUNCE_MS - elapsed);
            setTimeout(() => {
                isLockToggleBusy.value = false;
            }, unlockIn);
        }
    }

    function startLockHeartbeat() {
        stopLockHeartbeat();
        log('SYS', 'Starting meeting lock lease heartbeat (2m)');
        lockHeartbeatInterval = setInterval(async () => {
            if (!meeting.value || !isLocked.value) {
                stopLockHeartbeat();
                return;
            }
            try {
                await meetingService.renewLock(meeting.value.public_id);
            } catch (e) {
                log('ERROR', 'Failed to renew meeting lock lease', e);
            }
        }, 120000); // 2 minutes
    }

    function stopLockHeartbeat() {
        if (lockHeartbeatInterval) {
            log('SYS', 'Stopping meeting lock lease heartbeat');
            clearInterval(lockHeartbeatInterval);
            lockHeartbeatInterval = null;
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
        stream.localStream.value?.getTracks().forEach(t => t.stop());
        const id = meeting.value?.public_id;
        cleanup();
        if (id) {
            window.location.href = `/m/${id}?ended=1`;
        } else {
            // Route to home via window since we don't have router in store
            window.location.href = '/';
        }
    }

    // --- Reactions ---

    let lastReactionTime = 0;
    const REACTION_THROTTLE = 500; // ms

    function sendReaction(emoji: string) {
        if (!localParticipant.value) return;
        
        const now = Date.now();
        if (now - lastReactionTime < REACTION_THROTTLE) return;
        lastReactionTime = now;
        
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

    // --- Annotations ---

    function sendAnnotationUpdate(data: any) {
        signaling.sendSignal('annotation-update', data);
    }

    function receiveAnnotationUpdate(data: any) {
        lastAnnotationSignal.value = { ...data, _timestamp: Date.now() };
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

    async function initMediaEngine() {
        if (!meeting.value || !localParticipant.value) return;
        if (localParticipant.value.status !== 'admitted') {
            log('SYS', 'Skipping media engine init: Participant not admitted');
            return;
        }

        log('SYS', 'Initializing media engine...');

        if (meeting.value.recording_enabled) {
            // Cloudflare SDK Path
            try {
                const { default: api } = await import('@/lib/api');
                const tokenRes = await api.post(`/api/meetings/${meeting.value.public_id}/recording/token`);
                
                if (tokenRes.data.auth_token) {
                    log('SYS', 'Joining Cloudflare Realtime session...');
                    await stream.initSDK(tokenRes.data.auth_token, stream.localStream.value);
                }
                
                // Sync recording state if active
                if (tokenRes.data.recording) {
                    log('SYS', 'Active recording found, syncing state');
                    const startedAt = new Date(tokenRes.data.recording.started_at).getTime();
                    const now = new Date().getTime();
                    const currentDuration = Math.max(0, Math.floor((now - startedAt) / 1000));
                    
                    handleRecordingStarted({
                        recording_id: tokenRes.data.recording.id,
                        started_by: 'System',
                        duration: currentDuration
                    });
                }
            } catch (e) {
                log('ERROR', 'Failed to initialize Cloudflare SDK', e);
            }
        } else {
            // Legacy SFU Path
            log('SYS', 'Initializing Legacy SFU session...');
            await stream.initSFU(stream.localStream.value);
        }
    }

    async function resetSFUSession() {
        log('SYS', 'Manual SFU Reset requested via UI');
        await stream.resetSFUSession(stream.localStream.value);
    }

    function toggleDevMode() { 
        isDevMode.value = !isDevMode.value; 
    }

    // --- Signal Queueing & Deduping ---
    class SignalQueue {
        private queue: { type: string; data: any; handler: (data: any) => Promise<void> }[] = [];
        private isProcessing = false;
        private lastSignalHash: string | null = null;

        async add(type: string, data: any, handler: (data: any) => Promise<void>) {
            // Dedupe immediate repeats of the same signal type + payload
            const currentHash = JSON.stringify({ type, data });
            if (currentHash === this.lastSignalHash) {
                log('SIGNAL', `Ignoring duplicate signal: ${type}`);
                return;
            }
            this.lastSignalHash = currentHash;

            this.queue.push({ type, data, handler });
            this.process();
        }

        private async process() {
            if (this.isProcessing || this.queue.length === 0) return;
            this.isProcessing = true;

            while (this.queue.length > 0) {
                const item = this.queue.shift();
                if (!item) continue;

                try {
                    log('SIGNAL', `Queue processing: ${item.type}`);
                    await item.handler(item.data);
                } catch (e) {
                    log('ERROR', `Queue error processing ${item.type}`, e);
                }
            }

            this.isProcessing = false;
        }
    }

    const signalQueue = new SignalQueue();

    // --- Breakout Handlers ---

    async function handleBreakoutStarted(data: any) {
        signalQueue.add('breakout-started', data, async (data) => {
            log('BREAKOUT', 'Received breakout started signal', data);
            
            // Normalize IDs in the incoming signal
            const normalizedData = {
                ...data,
                rooms: data.rooms.map((r: any) => ({
                    ...r,
                    participants: r.participants.map((p: any) => ({
                        ...p,
                        public_id: p.public_id.toLowerCase()
                    }))
                }))
            };

            // --- GLOBAL ROOM SYNC ---
            // Ensure EVERY participant's room ID is updated in the presence store
            // so filtering works for everyone immediately.
            normalizedData.rooms.forEach((room: any) => {
                room.participants.forEach((p: any) => {
                    presence.upsertParticipant({
                        public_id: p.public_id,
                        current_room_id: String(room.id)
                    });
                });
            });

            activeBreakoutSession.value = normalizedData;
            
            // Find if this participant is assigned to a room
            const myRoom = normalizedData.rooms.find((r: any) => 
                r.participants.some((p: any) => p.public_id === localParticipant.value?.public_id?.toLowerCase())
            );

            if (myRoom) {
                isInBreakout.value = true;
                log('BREAKOUT', `Joining breakout room: ${myRoom.name}`);
                currentRoomId.value = String(myRoom.id);
                currentRoomName.value = myRoom.name;
                const { toast } = await import('vue-sonner');
                toast.info(`Joining breakout room: ${myRoom.name}`);
                
                // Re-sync SFU for the new room context
                await stream.resetSFUSession(stream.localStream.value);
            } else {
                isInBreakout.value = false;
                log('BREAKOUT', 'Not assigned to a breakout room. Staying in main room.');
                // Even if staying in main, we should reset if we were previously elsewhere
                await stream.resetSFUSession(stream.localStream.value);
            }

            // Start timer only if duration is positive and compute accurate remaining
            // time from the shared started_at timestamp.
            if ((normalizedData.duration ?? 0) > 0 && normalizedData.started_at) {
                const elapsedSeconds = Math.max(
                    0,
                    Math.floor((Date.now() - new Date(normalizedData.started_at).getTime()) / 1000),
                );
                const remainingSeconds = Math.max(
                    0,
                    Number(normalizedData.duration) * 60 - elapsedSeconds,
                );
                applyBreakoutTimerSeconds(remainingSeconds);
            } else {
                applyBreakoutTimerSeconds(0);
            }
        });
    }

    async function handleBreakoutEnded(data: any) {
        signalQueue.add('breakout-ended', data, async (data) => {
            log('BREAKOUT', 'Session ended', data);
            activeBreakoutSession.value = null;
            isInBreakout.value = false;
            currentRoomId.value = null;
            currentRoomName.value = null;

            // --- GLOBAL ROOM RESET ---
            // Clear everyone's room ID in the participant list
            presence.participants.value.forEach(p => {
                presence.upsertParticipant({
                    public_id: p.public_id,
                    current_room_id: null
                });
            });
            
            stopBreakoutTimerTicker();
            
            // Return to main SFU context - IMPORTANT: Must be awaited to ensure clean state
            await stream.resetSFUSession(stream.localStream.value);
            
            const { toast } = await import('vue-sonner');
            toast.info('Breakout session has ended. Returning to main room.');
        });
    }

    async function handleBreakoutHelpRequest(data: any) {
        if (!presence.isHost.value) return;
        const { toast } = await import('vue-sonner');
        toast.info(`Help requested in ${data.room_name || 'Room'}`, {
            duration: 15000,
            description: `A participant in ${data.room_name || 'Room'} is asking for assistance.`,
            action: {
                label: 'Join Room',
                onClick: () => {
                    joinBreakoutRoom(data.room_id, data.room_name || 'Room');
                }
            }
        });
    }

    async function handleBreakoutMove(data: any) {
        signalQueue.add('breakout-move', data, async (data) => {
            if (!data.target_id) return;
            const targetId = data.target_id.toLowerCase();
            const targetRoomId = data.target_room_id ? String(data.target_room_id) : null;
            const isMe = targetId === localParticipant.value?.public_id?.toLowerCase();

            // TOAST LOGIC: Only show if it affects our current view
            const myRoom = currentRoomId.value ? String(currentRoomId.value) : null;
            const existing = presence.participants.value.find(p => p.public_id === targetId);
            const oldRoom = existing?.current_room_id ? String(existing.current_room_id) : null;

            if (!isMe) {
                // Someone else moved
                const { toast } = await import('vue-sonner');
                const name = existing?.user?.name || existing?.metadata?.guest_name || 'Someone';

                if (oldRoom === myRoom && targetRoomId !== myRoom) {
                    toast.info(`🚪 ${name} left the room`);
                } else if (targetRoomId === myRoom && oldRoom !== myRoom) {
                    // Only toast if moving to Main Room (null), 
                    // because breakout joins (1, 2, etc) are handled by breakout-activity signals from backend
                    if (targetRoomId === null) {
                        toast.info(`👋 ${name} joined the room`);
                    }
                }
            }

            if (isMe) {
                log('BREAKOUT', 'Moving participant (Self)', data);
                
                // Loop Guard: If we are already in this room, skip
                if (currentRoomId.value === targetRoomId) {
                    log('BREAKOUT', 'Already in target room, skipping join action');
                } else {
                    if (targetRoomId) {
                        const room = activeBreakoutSession.value?.rooms?.find((r: any) => String(r.id) === targetRoomId);
                        // Sequencing: joinBreakoutRoom handles state updates and SFU reset
                        await joinBreakoutRoom(targetRoomId, room?.name || 'Breakout Room');
                    } else {
                        // Moving back to main room
                        await joinBreakoutRoom(null, 'Main Room');
                    }
                }
            }

            // Update the participant's room ID in the global raw list for EVERYONE'S UI
            presence.upsertParticipant({
                public_id: targetId,
                current_room_id: targetRoomId
            });
        });
    }

    async function handleBreakoutTimerUpdated(data: any) {
        log('BREAKOUT', 'Timer updated', data);
        if (typeof data.remaining_seconds === 'number') {
            applyBreakoutTimerSeconds(data.remaining_seconds);
        } else {
            applyBreakoutTimerSeconds(breakoutTimer.value + (Number(data.additional_minutes || 0) * 60));
        }

        if (activeBreakoutSession.value && typeof data.duration_minutes === 'number') {
            activeBreakoutSession.value = {
                ...activeBreakoutSession.value,
                duration: data.duration_minutes,
            };
        }

        const { toast } = await import('vue-sonner');
        const actionText = data.additional_minutes > 0 ? 'added' : 'removed';
        toast.info(`Host ${actionText} ${Math.abs(data.additional_minutes)} minute(s) to the session.`);
    }

    async function handleBreakoutActivity(data: any) {
        // Show notification if it's for everyone or targeting our current room
        const myRoom = activeBreakoutSession.value?.rooms?.find((r: any) => 
            r.participants.some((p: any) => p.public_id === localParticipant.value?.public_id)
        );
        
        if (!data.target_room_id || (myRoom && String(myRoom.id) === String(data.target_room_id))) {
            // Suppress "Someone joined the room" toast if it was triggered by OUR OWN join,
            // as we already show "Joining Room..." or similar.
            if (data.message.toLowerCase().includes('joined') && data.sender_id === localParticipant.value?.public_id) {
                return;
            }

            const { toast } = await import('vue-sonner');
            toast.info(data.message);
        }
    }

    async function joinBreakoutRoom(roomId: string | number | null, roomName: string) {
        if (!meeting.value) return;
        
        // Guard: Prevent manual return to main if assigned to a room
        if (roomId === null && !presence.isHost.value) {
            const myAssignment = localParticipant.value?.assigned_room_id;
            if (myAssignment) {
                const { toast } = await import('vue-sonner');
                toast.error('Only the host can return to the main room during a breakout session.');
                return;
            }
        }

        const normalizedRoomId = roomId === null ? null : String(roomId);
        if (isTransitioningRoom.value) return;

        const myId = localParticipant.value?.public_id;
        const currentId = currentRoomId.value;
        const currentName = currentRoomName.value || 'Main Room';
        
        log('BREAKOUT', `🚀 STARTING ROOM JUMP [${currentName} (${currentId})] -> [${roomName} (${normalizedRoomId})]`, {
            participantId: myId,
            wasInBreakout: isInBreakout.value,
            currentBaggage: {
                activeSession: !!activeBreakoutSession.value,
                participantCount: presence.allParticipants.value.length,
                remoteStreams: stream.remoteStreams.value.size
            }
        });

        isTransitioningRoom.value = true;
        try {
            await meetingService.joinBreakoutRoom(meeting.value.public_id, normalizedRoomId);
            log('BREAKOUT', `📍 Arriving in ${roomName}...`);
            
            if (roomId === null) {
                // --- HOST ROOM SYNC ---
                // Even if not assigned, the Host should consider themselves in the Main Room (null)
                // to maintain consistent presence filtering.
                if (!isInBreakout.value) {
                    currentRoomId.value = null;
                    currentRoomName.value = 'Main Room';
                    
                    // Update our own room ID in the participant list for UI sync
                    if (localParticipant.value) {
                        presence.upsertParticipant({
                            public_id: localParticipant.value.public_id,
                            current_room_id: null
                        });
                    }
                }
                isInBreakout.value = false;
                currentRoomId.value = null;
                currentRoomName.value = null;
            } else {
                isInBreakout.value = true;
                currentRoomId.value = String(normalizedRoomId);
                currentRoomName.value = roomName;
            }

            // Push our updated state to the presence list immediately so filtering works
            if (myId) {
                presence.upsertParticipant({
                    public_id: myId,
                    current_room_id: normalizedRoomId
                });
            }

            // Deterministic SFU reset AFTER state update
            await stream.resetSFUSession(stream.localStream.value);

            // --- PROACTIVE MEDIA INFO REFRESH ---
            // After reset, ask everyone in the new room to re-send their media info.
            // This ensures we get fresh MIDs for people who were already in the room.
            log('BREAKOUT', 'Proactively requesting media info for the new room context');
            signaling.broadcastRequestMediaInfo();

            const occupants = presence.allParticipants.value.map(p => ({
                id: p.public_id,
                name: p.user?.name || p.metadata?.guest_name || 'Unknown',
                roomId: p.current_room_id
            }));

            log('BREAKOUT', `✅ ARRIVED in ${roomName}. Room Inventory:`, {
                totalGlobal: presence.participants.value.length,
                roomOccupants: occupants,
                count: occupants.length,
                message: occupants.length > 1 
                    ? `Success! Seeing ${occupants.length - 1} other(s).` 
                    : "I am the only one here (waiting for others)."
            });
        } catch (e) {
            log('ERROR', 'Failed to join breakout room', e);
            throw e;
        } finally {
            isTransitioningRoom.value = false;
        }
    }

    async function moveParticipant(participantPublicId: string, targetRoomId: string | number | null) {
        if (!meeting.value) return;
        const normalizedRoomId = targetRoomId === null ? null : String(targetRoomId);
        try {
            await meetingService.moveParticipant(
                meeting.value.public_id, 
                participantPublicId, 
                normalizedRoomId
            );
            log('BREAKOUT', `Moved participant ${participantPublicId} to ${normalizedRoomId}`);
        } catch (e) {
            log('ERROR', `Failed to move participant ${participantPublicId}`, e);
            throw e;
        }
    }

    async function startBreakout(rooms: any[], duration: number | null) {
        if (!meeting.value) return;
        try {
            await meetingService.createBreakoutSession(meeting.value.public_id, {
                rooms,
                duration_minutes: duration
            });
        } catch (e) {
            log('ERROR', 'Failed to start breakout', e);
            throw e;
        }
    }

    async function endBreakout() {
        if (!meeting.value) return;
        try {
            await meetingService.endBreakoutSession(meeting.value.public_id);
        } catch (e) {
            log('ERROR', 'Failed to end breakout', e);
            throw e;
        }
    }

    async function updateBreakoutTimer(additionalMinutes: number) {
        if (!meeting.value) return;
        if (!Number.isFinite(additionalMinutes) || additionalMinutes === 0) return;
        try {
            await meetingService.updateBreakoutTimer(
                meeting.value.public_id,
                Math.trunc(additionalMinutes),
            );
        } catch (e) {
            log('ERROR', 'Failed to update breakout timer', e);
            throw e;
        }
    }

    async function requestHostHelp() {
        if (!meeting.value || !isInBreakout.value) return;
        try {
            // Find current room ID
            const myRoom = activeBreakoutSession.value?.rooms?.find((r: any) => 
                r.participants.some((p: any) => p.public_id?.toLowerCase() === localParticipant.value?.public_id?.toLowerCase())
            );
            
            const roomId = myRoom?.id || currentRoomId.value;
            if (roomId) {
                await meetingService.requestHostHelp(meeting.value.public_id, String(roomId));
                const { toast } = await import('vue-sonner');
                toast.success('Host has been notified.');
            }
        } catch (e) {
            log('ERROR', 'Failed to request help', e);
        }
    }

    async function notifyBreakoutActivity(message: string, targetRoomId?: string | null) {
        if (!meeting.value) return;
        try {
            // Normalize targetRoomId to string or null (never undefined) for backend validation
            const normalizedTargetId = (targetRoomId === undefined || targetRoomId === null) ? null : String(targetRoomId);
            await meetingService.notifyBreakoutActivity(meeting.value.public_id, message, normalizedTargetId);
        } catch (e) {
            log('ERROR', 'Failed to notify breakout activity', e);
            throw e;
        }
    }
    
    // 4. Expose unified API to Vue components
    return {
        // State
        meeting,
        localParticipant,
        isLocked,
        isLockToggleBusy,
        originalVideoTrack,
        isDevMode,
        
        // Breakout Rooms
        activeBreakoutSession,
        isInBreakout,
        currentRoomId,
        currentRoomName,
        breakoutTimer,
        formatBreakoutTime: computed(() => {
            const mins = Math.floor(breakoutTimer.value / 60);
            const secs = breakoutTimer.value % 60;
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        }),
        showBreakoutManager,
        handleBreakoutStarted,
        handleBreakoutEnded,
        handleBreakoutHelpRequest,
        handleBreakoutActivity,
        handleBreakoutTimerUpdated,
        handleBreakoutMove,
        startBreakout,
        endBreakout,
        joinBreakoutRoom,
        moveParticipant,
        updateBreakoutTimer,
        requestHostHelp,
        notifyBreakoutActivity,

        // Presence Manager
        participants: presence.participants,
        allParticipants: computed(() => {
            const all = presence.allParticipants.value;
            // In breakout room: Filter by real-time current_room_id on participants
            const myRoomId = currentRoomId.value === null || currentRoomId.value === undefined ? null : String(currentRoomId.value);
            
            return all.filter(p => {
                // Always include self
                if (p.public_id === localParticipant.value?.public_id) return true;
                
                // match room ID (both sides normalized to string or null)
                const pRoomId = p.current_room_id === null || p.current_room_id === undefined ? null : String(p.current_room_id);
                return pRoomId === myRoomId;
            });
        }),
        waitingParticipants: presence.waitingParticipants,
        activeParticipantIds: presence.activeParticipantIds,
        raisedHands: presence.raisedHands,
        screenShares: presence.screenShares,
        talkingParticipants: presence.talkingParticipants,
        mockParticipants: presence.mockParticipants,
        rawParticipants: presence.allParticipants,
        simulatedRole: presence.simulatedRole,
        isHost: presence.isHost,
        isModerator: presence.isModerator,
        
        // Stream Manager
        remoteStreams: stream.remoteStreams,
        localStream: stream.localStream,
        localVolume,
        sfuConnectionState: stream.sfuConnectionState,
        sfuIceState: stream.sfuIceState,
        sfuPc: stream.sfuPc,
        networkScore: stream.networkScore,
        networkBitrate: stream.networkBitrate,
        networkPacketLoss: stream.networkPacketLoss,
        networkRtt: stream.networkRtt,
        
        // Layout Manager
        pinnedParticipantId: layout.pinnedParticipantId,
        activeSpeakerId: layout.activeSpeakerId,
        preferredLayout: layout.preferredLayout,

        // High-level Actions
        initializeMeeting,
        cleanup,
        addLocalStream: stream.addLocalStream,
        replaceTrack: stream.replaceTrack,
        setStream,
        toggleHand,
        publishScreenTrack,
        unpublishScreenTrack,
        resetSFUSession,
        
        // Full stream manager (for advanced access like setVisibleParticipants)
        stream,
        
        // Host Action proxies
        sendSignal: signaling.sendSignal, // used by LaserPointerOverlay for laser-move events
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

        // Polls
        activePoll,
        recentPolls,
        handlePollCreated: (poll: Poll) => {
            // If the poll already exists (update), replace it in recentPolls too
            const idx = recentPolls.value.findIndex(p => p.public_id === poll.public_id);
            if (idx !== -1) {
                recentPolls.value[idx] = poll;
            } else {
                recentPolls.value.unshift(poll);
            }
            // Always set as active poll if it's active
            if (poll.is_active) {
                activePoll.value = poll;
            }
        },
        handlePollVoted: (data: { poll_id: string; vote_counts: number[]; total_votes: number }) => {
            if (activePoll.value?.public_id === data.poll_id) {
                activePoll.value.vote_counts = data.vote_counts;
            }
            const recent = recentPolls.value.find(p => p.public_id === data.poll_id);
            if (recent) recent.vote_counts = data.vote_counts;
        },
        handlePollEnded: (data: { poll_id: string; final_vote_counts: number[] }) => {
            if (activePoll.value?.public_id === data.poll_id) {
                activePoll.value.is_active = false;
                activePoll.value.vote_counts = data.final_vote_counts;
                activePoll.value = null; // Clear active poll when it ends
            }
            const recent = recentPolls.value.find(p => p.public_id === data.poll_id);
            if (recent) {
                recent.is_active = false;
                recent.vote_counts = data.final_vote_counts;
            }
        },
        handlePollDeleted: (pollId: string) => {
            if (activePoll.value?.public_id === pollId) {
                activePoll.value = null;
            }
            recentPolls.value = recentPolls.value.filter(p => p.public_id !== pollId);
        },

        // Laser Pointer
        remotePointers,
        laserPointerMode,
        handleLaserMove: (data: { participant_id: string; target_participant_id?: string; x: number; y: number }) => {
            const myId = localParticipant.value?.public_id;
            if (data.participant_id === myId) return; // ignore own echo
            remotePointers.set(data.participant_id, {
                participantId: data.participant_id,
                targetParticipantId: data.target_participant_id,
                x: data.x,
                y: data.y,
                lastSeen: Date.now(),
            });
        },
        handleLaserModeChanged: (mode: 'off' | 'global' | 'targeted') => {
            log('SYS', `Laser pointer mode changed to: ${mode}`);
            laserPointerMode.value = mode;
        },

        // Host Actions
        toggleLock, // Exposed toggleLock
        endMeeting,
        handleMeetingEnded,
        
        // Layout Action proxies
        setSpotlight: layout.setSpotlight,
        clearSpotlight: layout.clearSpotlight,
        setLayout: layout.setLayout,

        // Dev tool wrappers
        addMockParticipant: presence.addMockParticipant,
        removeMockParticipant: presence.removeMockParticipant,
        resetSimulation: presence.resetSimulation,
        setSimulatedRole: (r: any) => { presence.simulatedRole.value = r; },
        toggleDevMode,

        // Annotations
        isAnnotating,
        activeAnnotationTool,
        activeAnnotationColor,
        lastAnnotationSignal,
        sendAnnotationUpdate,
        receiveAnnotationUpdate,
        handleAnnotationUpdate: receiveAnnotationUpdate,

        // PRO Recording
        isRecording,
        activeRecordingId,
        handleRecordingStarted,
        handleRecordingStopped,
    };
});
