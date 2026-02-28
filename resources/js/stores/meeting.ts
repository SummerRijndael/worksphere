import { defineStore } from 'pinia';
import { ref, reactive, computed } from 'vue';
import { meetingService } from '@/services/meeting.service';
import type { Meeting, MeetingParticipant } from '@/types/models';
import { createLogger } from './managers/logger';
import { useAuthStore } from '@/stores/auth';

import { createPresenceManager } from './managers/PresenceManager';
import { createStreamManager } from './managers/StreamManager';
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
    const originalVideoTrack = ref<MediaStreamTrack | null>(null);
    let lockHeartbeatInterval: any = null;

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

    // 2. Initialize Sub-Managers
    const layout = createLayoutManager(meeting, localParticipant);
    
    const presence = createPresenceManager(meeting, localParticipant, currentRoomId);
    
    const stream = createStreamManager(
        meeting, 
        localParticipant, 
        iceServers,
        currentRoomId,
        (id, isTalking) => {
            presence.setTalking(id, isTalking);
            if (isTalking) layout.setActiveSpeaker(id);
        },
        (audioMid, videoMid, screenMid) => {
            signaling.broadcastSfuMediaReady(audioMid, videoMid, screenMid, currentRoomId.value);
        },
        (err) => {
            log('ERROR', 'SFU Error encountered', err);
        }
    );

    const signaling = createSignalingManager(
        meeting, 
        localParticipant, 
        presence, 
        stream,
        async () => {
            // onAdmitted callback: initialize WebRTC when host lets them in
            stream.initSFU(stream.localStream.value);
            
            // Proactively ask everyone to re-send their media info to us
            signaling.broadcastRequestMediaInfo();

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
             const data = await meetingService.getMeeting(meetingId) as any;
             // MeetingResource has $wrap = null, so the response IS the meeting object
             // with participants nested inside it.
             const participants = data.participants || [];
             meeting.value = data;
             isLocked.value = !!data.is_locked;
             laserPointerMode.value = data.settings?.laser_pointer_mode || 'off';
             
             // Normalize all incoming participant IDs
             participants.forEach((p: any) => {
                 p.public_id = p.public_id.toLowerCase();
             });

             // Find local participant
             const normalizedParticipantId = participantPublicId.toLowerCase();
             const found = participants.find((p: any) => p.public_id === normalizedParticipantId) || null;
             
             const authStore = useAuthStore();
             const currentPublicId = authStore.user?.public_id || 'Guest';
             const recordUserPublicId = found?.user?.public_id || 'Guest';

             if (found?.user_id) {
                // Registered User Check
                if (recordUserPublicId !== currentPublicId) {
                    log('SECURITY', 'IDENTITY MISMATCH: Rejecting token.');
                    meeting.value = null; // Clear state before throwing
                    localParticipant.value = null;
                    throw new Error("Identity Mismatch");
                }
             } else if (found) {
                // Guest Check: Verify against localStorage token set during join/login
                // This prevents hijacking a guest session by just pasting the URL
                const storedToken = localStorage.getItem(`worksphere_meeting_token_${meetingId}`);
                if (!storedToken || storedToken.toLowerCase() !== normalizedParticipantId) {
                    log('SECURITY', 'GUEST SESSION MISMATCH: Rejecting URL-pasted token.');
                    meeting.value = null; // Clear state before throwing
                    localParticipant.value = null;
                    throw new Error("Guest Session Mismatch");
                }
             }
             
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
             // addLocalStream() next, which triggers resetSFUSession → initSFU with
             // the actual local stream. Calling it here would cause a double-init.
             if (localParticipant.value?.status === 'admitted') {
                 log('SYS', 'Participant admitted, SFU will start when addLocalStream is called');
                 
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
         layout.clearSpotlight();
         meeting.value = null;
         localParticipant.value = null;
         remotePointers.clear();
    }

    function toggleHand() {
        const pId = localParticipant.value?.public_id;
        if (!pId) return;
        const isRaised = !presence.raisedHands.value.has(pId);
        presence.toggleHandState(pId, isRaised);
        signaling.broadcastHandState(isRaised);
    }

    async function publishScreenTrack(s: MediaStream) {
        const result = await stream.publishScreenTrack(s);
        if (result && result.mid) {
            presence.toggleScreenShareState(localParticipant.value!.public_id, true);
            signaling.broadcastScreenShareState(true, result.mid);
        }
    }

    async function unpublishScreenTrack() {
        await stream.unpublishScreenTrack();
        presence.toggleScreenShareState(localParticipant.value!.public_id, false);
        signaling.broadcastScreenShareState(false);
    }

    function setStream(newStream: MediaStream | null) {
        stream.setLocalStream(newStream);
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

            // Start timer only if duration is positive
            if (normalizedData.duration > 0) {
                breakoutTimer.value = data.duration * 60;
                if (timerInterval) clearInterval(timerInterval);
                timerInterval = setInterval(() => {
                    if (breakoutTimer.value > 0) {
                        breakoutTimer.value--;
                    } else {
                        clearInterval(timerInterval);
                        // If we're the host, automatically end the session for everyone
                        if (presence.isHost.value) {
                            log('BREAKOUT', 'Timer expired, auto-ending breakout session');
                            endBreakout();
                        }
                    }
                }, 1000);
            } else {
                breakoutTimer.value = 0;
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
            
            if (timerInterval) clearInterval(timerInterval);
            
            // Return to main SFU context - IMPORTANT: Must be awaited to ensure clean state
            await stream.resetSFUSession(stream.localStream.value);
            
            const { toast } = await import('vue-sonner');
            toast.info('Breakout session has ended. Returning to main room.');
        });
    }

    async function handleBreakoutHelpRequest(data: any) {
        if (!presence.isHost.value) return;
        const { toast } = await import('vue-sonner');
        toast.info(`🆘 Help requested in ${data.room_name || 'Room'}`, {
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
        breakoutTimer.value += (data.additional_minutes * 60);
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

    async function startBreakout(rooms: any[], duration: number) {
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
        sfuConnectionState: stream.sfuConnectionState,
        sfuIceState: stream.sfuIceState,
        sfuPc: stream.sfuPc,
        
        // Layout Manager
        pinnedParticipantId: layout.pinnedParticipantId,
        activeSpeakerId: layout.activeSpeakerId,
        preferredLayout: layout.preferredLayout,

        // High-level Actions
        initializeMeeting,
        cleanup,
        addLocalStream: stream.addLocalStream,
        setStream,
        toggleHand,
        replaceTrack: stream.replaceTrack,
        publishScreenTrack,
        unpublishScreenTrack,
        
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
    };
});
