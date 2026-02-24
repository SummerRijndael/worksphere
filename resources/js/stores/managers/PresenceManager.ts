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
    localParticipantRef: Ref<MeetingParticipant | null>
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
            })
            .joining((user: any) => {
                const pid = user.public_id.toLowerCase();
                log('CHANNEL', `Member joining: ${pid}`, user);
                // Must create new Set for Vue reactivity (ref<Set> doesn't track .add())
                const newIds = new Set(activeParticipantIds.value);
                newIds.add(pid);
                activeParticipantIds.value = newIds;
                // Fallback to ensuring participant exists in our main raw array
                if (!participants.value.find(p => p.public_id === pid)) {
                    participants.value = [...participants.value, {
                        public_id: pid,
                        role: user.role,
                        status: user.status || 'admitted',
                        user: user.avatar ? { name: user.name, avatar_url: user.avatar } : null,
                        metadata: { guest_name: user.name }
                    }];
                }
            })
            .leaving((user: any) => {
                const pid = user.public_id.toLowerCase();
                log('CHANNEL', `Member leaving: ${pid}`, user);
                // Must create new Set for Vue reactivity
                const newIds = new Set(activeParticipantIds.value);
                newIds.delete(pid);
                activeParticipantIds.value = newIds;
                // Also clean up screen shares or talking states if they forcefully vanish
                const newScreens = new Set(screenShares.value);
                newScreens.delete(pid);
                screenShares.value = newScreens;

                const newTalking = new Set(talkingParticipants.value);
                newTalking.delete(pid);
                talkingParticipants.value = newTalking;
            });
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
        const pid = data.public_id.toLowerCase();
        const existingIdx = participants.value.findIndex(p => p.public_id === pid);
        
        const participantData = {
            public_id: pid,
            role: data.role || 'participant',
            status: data.status,
            user: data.user,
            metadata: data.metadata || {}
        };

        if (existingIdx !== -1) {
            participants.value[existingIdx] = {
                ...participants.value[existingIdx],
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
        return [
            ...participants.value.filter(p => 
                p.status === 'admitted' && 
                (p.public_id === localParticipantRef.value?.public_id || activeParticipantIds.value.has(p.public_id))
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
        
        allParticipants,
        waitingParticipants,
        isHost,
        isModerator
    };
}
