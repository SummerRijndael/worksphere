import { ref, type Ref } from 'vue';
import { createLogger } from './logger';
import type { Meeting, MeetingParticipant } from '@/types/models';

const log = createLogger('LAYOUT');

export function createLayoutManager(
    meetingRef: Ref<Meeting | null>,
    localParticipantRef: Ref<MeetingParticipant | null>
) {
    const pinnedParticipantId = ref<string | null>(null);
    const activeSpeakerId = ref<string | null>(null);

    function setSpotlight(publicId: string | null) {
        log('UI', `Spotlight set to: ${publicId || 'none'}`);
        pinnedParticipantId.value = publicId;
    }

    function clearSpotlight() {
        if (pinnedParticipantId.value) {
            log('UI', 'Spotlight cleared');
            pinnedParticipantId.value = null;
        }
    }

    function setActiveSpeaker(publicId: string | null) {
        if (activeSpeakerId.value !== publicId) {
            activeSpeakerId.value = publicId;
        }
    }

    return {
        pinnedParticipantId,
        activeSpeakerId,
        setSpotlight,
        clearSpotlight,
        setActiveSpeaker
    };
}
