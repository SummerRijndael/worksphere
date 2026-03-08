import { ref, computed, type Ref } from 'vue';
import { createLogger } from './logger';
import { meetingService } from '@/services/meeting.service';
import { useAuthStore } from '@/stores/auth';
import type { Meeting, MeetingParticipant } from '@/types/models';
import Echo from 'laravel-echo';
import { startEcho } from '@/echo';

const log = createLogger('PRESENCE');

export function createPresenceManager(
    meetingRef: Ref<Meeting | null>,
    localParticipantRef: Ref<MeetingParticipant | null>,
    currentRoomIdRef: Ref<string | null>
) {
    const participants = ref<any[]>([]);
    const activeParticipantIds = ref<Set<string>>(new Set());
    const raisedHands = ref<Set<string>>(new Set());
    const screenShares = ref<Set<string>>(new Set());
    const talkingParticipants = ref<Set<string>>(new Set());
    const echoChannel = ref<any>(null);

    // Mocks and Dev tools
    const mockParticipants = ref<any[]>([]);
    const simulatedRole = ref<'host' | 'participant' | null>(null);

    const authStore = useAuthStore();

    function setupEcho(meetingId: string) {
        if (echoChannel.value) return;

        // Ensure window.Echo exists, start it if needed
        let echoInstance = (window as any).Echo;
        if (!echoInstance) {
            log('SYS', 'Laravel Echo not found on window, starting it now...');
            echoInstance = startEcho();
        }
        if (!echoInstance) {
            log('ERROR', 'Failed to initialize Laravel Echo.');
            return;
        }

        log('CHANNEL', `Joining presence channel: meeting.${meetingId}`);
        echoChannel.value = echoInstance.join(`meeting.${meetingId}`)
            .here((users: any[]) => {
                log('CHANNEL', `Members here: ${users.length}`, users);
                activeParticipantIds.value = new Set(users.map(u => u.public_id.toLowerCase()));

                // IMPORTANT: hydrate participant records from current occupants.
                // Waiting-room users may only have themselves in initial meeting payload,
                // so without this they can get "You're the only one here" after admission.
                users.forEach((user: any) => {
                    upsertParticipant({
                        public_id: user.public_id,
                        role: user.role,
                        status: user.status || 'admitted',
                        user: user.avatar ? { name: user.name, avatar_url: user.avatar } : undefined,
                        metadata: { guest_name: user.name },
                        current_room_id:
                            user.current_room_id !== undefined && user.current_room_id !== null
                                ? String(user.current_room_id)
                                : user.assigned_room_id !== undefined && user.assigned_room_id !== null
                                  ? String(user.assigned_room_id)
                                  : null,
                    });
                });
            })
            .joining(async (user: any) => {
                const pid = user.public_id.toLowerCase();
                log('CHANNEL', `Member joining: ${pid}`, user);
                
                const { toast } = await import('vue-sonner');
                toast.info(`🔔 ${user.name} joined the meeting`);

                const newIds = new Set(activeParticipantIds.value);
                newIds.add(pid);
                activeParticipantIds.value = newIds;
                
                if (!participants.value.find(p => p.public_id === pid)) {
                    participants.value = [...participants.value, {
                        public_id: pid,
                        role: user.role,
                        status: user.status || 'admitted',
                        user: user.avatar ? { name: user.name, avatar_url: user.avatar } : null,
                        metadata: { guest_name: user.name },
                        current_room_id: (user.current_room_id !== undefined && user.current_room_id !== null) ? String(user.current_room_id) : ((user.assigned_room_id !== undefined && user.assigned_room_id !== null) ? String(user.assigned_room_id) : null)
                    }];
                }
            })
            .leaving(async (user: any) => {
                const pid = user.public_id.toLowerCase();
                log('CHANNEL', `Member leaving: ${pid}`, user);
                
                // Only toast if they were in our current room
                const myRoom = currentRoomIdRef.value ? String(currentRoomIdRef.value) : null;
                const existing = participants.value.find(p => p.public_id === pid);
                const pRoom = existing?.current_room_id ? String(existing.current_room_id) : null;

                if (pRoom === myRoom) {
                    const { toast } = await import('vue-sonner');
                    toast.info(`👋 ${user.name} has disconnected`);
                }

                const newIds = new Set(activeParticipantIds.value);
                newIds.delete(pid);
                activeParticipantIds.value = newIds;
                
                const newScreens = new Set(screenShares.value);
                newScreens.delete(pid);
                screenShares.value = newScreens;

                const newTalking = new Set(talkingParticipants.value);
                newTalking.delete(pid);
                talkingParticipants.value = newTalking;
            });
    }

    function whisper(event: string, data: any) {
        if (echoChannel.value) {
            echoChannel.value.whisper(event, data);
        }
    }

    function leaveEcho() {
        if (echoChannel.value && meetingRef.value) {
            log('CHANNEL', `Leaving presence channel`);
            const echoInstance = (window as any).Echo as Echo;
            echoInstance?.leave(`meeting.${meetingRef.value.public_id}`);
            echoChannel.value = null;
        }
        activeParticipantIds.value.clear();
        raisedHands.value.clear();
        screenShares.value.clear();
        talkingParticipants.value.clear();
    }

    function removeParticipant(publicId: string) {
        participants.value = participants.value.filter(p => p.public_id !== publicId);
        const newIds = new Set(activeParticipantIds.value);
        newIds.delete(publicId);
        activeParticipantIds.value = newIds;
        
        const newScreens = new Set(screenShares.value);
        newScreens.delete(publicId);
        screenShares.value = newScreens;

        const newHands = new Set(raisedHands.value);
        newHands.delete(publicId);
        raisedHands.value = newHands;
    }

    function upsertParticipant(data: any) {
        if (!data.public_id) return;
        const pid = data.public_id.toLowerCase();
        const existingIdx = participants.value.findIndex(p => p.public_id === pid);
        
        const existing = existingIdx !== -1 ? participants.value[existingIdx] : null;

        const participantData = {
            public_id: pid,
            role: data.role || existing?.role || 'participant',
            status: data.status || existing?.status || 'admitted',
            user: data.user || existing?.user,
            metadata: { ...(existing?.metadata || {}), ...(data.metadata || {}) },
            current_room_id: data.current_room_id !== undefined ? (data.current_room_id === null ? null : String(data.current_room_id)) : (existing?.current_room_id || null)
        };

        if (existingIdx !== -1) {
            participants.value[existingIdx] = {
                ...existing,
                ...participantData
            };
            participants.value = [...participants.value]; // Trigger reactivity
        } else {
            participants.value = [...participants.value, participantData];
        }
    }

    function toggleHandState(publicId: string, isRaised: boolean) {
        if (isRaised) {
            raisedHands.value = new Set(raisedHands.value).add(publicId);
        } else {
            const newSet = new Set(raisedHands.value);
            newSet.delete(publicId);
            raisedHands.value = newSet;
        }
    }

    function toggleScreenShareState(publicId: string, isSharing: boolean) {
        if (isSharing) {
            screenShares.value = new Set(screenShares.value).add(publicId);
        } else {
            const newSet = new Set(screenShares.value);
            newSet.delete(publicId);
            screenShares.value = newSet;
        }
    }

    function setTalking(publicId: string, isTalking: boolean) {
        if (isTalking) {
            talkingParticipants.value = new Set(talkingParticipants.value).add(publicId);
        } else {
            const newSet = new Set(talkingParticipants.value);
            newSet.delete(publicId);
            talkingParticipants.value = newSet;
        }
    }

    // -- Host controls --
    async function admitParticipant(publicId: string) {
        if (!meetingRef.value) return;
        try {
            await meetingService.admitParticipant(meetingRef.value.public_id, publicId);
            const p = participants.value.find(x => x.public_id === publicId);
            if (p) p.status = 'admitted';
        } catch (e) {
            log('ERROR', 'Failed to admit participant', e);
            throw e;
        }
    }

    async function rejectParticipant(publicId: string) {
        if (!meetingRef.value) return;
        try {
            await meetingService.rejectParticipant(meetingRef.value.public_id, publicId);
            removeParticipant(publicId);
        } catch (e) {
            log('ERROR', 'Failed to reject participant', e);
            throw e;
        }
    }

    // -- Dev Tools --
    function addMockParticipant() {
        const id = `mock-${Date.now()}`;
        mockParticipants.value.push({
            public_id: id,
            role: 'participant',
            status: 'admitted',
            user: { name: `Mock User ${mockParticipants.value.length + 1}` },
        });
    }

    function removeMockParticipant(id: string) {
        mockParticipants.value = mockParticipants.value.filter(p => p.public_id !== id);
    }

    function resetSimulation() {
        mockParticipants.value = [];
        simulatedRole.value = null;
        talkingParticipants.value.clear();
    }

    // -- Computed --
    const allParticipants = computed(() => {
        const myRoomId = currentRoomIdRef.value ? String(currentRoomIdRef.value) : null;
        
        return [
            ...participants.value.filter(p => {
                const isAdmitted = p.status === 'admitted';
                const isActive = p.public_id === localParticipantRef.value?.public_id || activeParticipantIds.value.has(p.public_id);
                
                if (!isAdmitted || !isActive) return false;

                // Room Filtering Logic (Robust / Redundant):
                // 1. Get our current room from the store ref
                const myRoomId = currentRoomIdRef.value ? String(currentRoomIdRef.value) : null;
                // 2. Fallback to our own participant record (Redundancy for race conditions)
                const myAssignedRoomId = (localParticipantRef.value?.current_room_id !== undefined && localParticipantRef.value?.current_room_id !== null) 
                    ? String(localParticipantRef.value.current_room_id) 
                    : null;
                
                const effectiveMyRoom = myRoomId || myAssignedRoomId;
                
                const pRoomId = (p.current_room_id !== undefined && p.current_room_id !== null) ? String(p.current_room_id) : null;
                const matches = pRoomId === (effectiveMyRoom || null);
                
                if (!matches) {
                    log('DEBUG', `Filtered out ${p.public_id}: Room mismatch (P:${pRoomId} vs My:${effectiveMyRoom})`);
                }
                
                return matches;
            }), 
            ...mockParticipants.value
        ];
    });

    const waitingParticipants = computed(() => {
        return participants.value.filter(p => p.status === 'waiting');
    });

    const isHost = computed(() => {
        if (simulatedRole.value) return simulatedRole.value === 'host';
        const userId = authStore.user?.id;
        const isOwner = meetingRef.value && userId && meetingRef.value.user_id == userId;
        return isOwner || localParticipantRef.value?.role === 'host';
    });

    const isModerator = computed(() => {
        return isHost.value || localParticipantRef.value?.role === 'co-host';
    });

    return {
        participants,
        activeParticipantIds,
        raisedHands,
        screenShares,
        talkingParticipants,
        echoChannel,
        mockParticipants,
        simulatedRole,
        
        setupEcho,
        leaveEcho,
        removeParticipant,
        upsertParticipant,
        toggleHandState,
        toggleScreenShareState,
        setTalking,
        admitParticipant,
        rejectParticipant,
        addMockParticipant,
        removeMockParticipant,
        resetSimulation,
        whisper,
        
        allParticipants,
        waitingParticipants,
        isHost,
        isModerator
    };
}
