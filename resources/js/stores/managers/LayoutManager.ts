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
    const preferredLayout = ref<'auto' | 'tiled' | 'spotlight' | 'sidebar'>('auto');

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

    function setLayout(layout: 'auto' | 'tiled' | 'spotlight' | 'sidebar') {
        log('UI', `Preferred layout set to: ${layout}`);
        preferredLayout.value = layout;
    }

    return {
        pinnedParticipantId,
        activeSpeakerId,
        preferredLayout,
        setSpotlight,
        clearSpotlight,
        setActiveSpeaker,
        setLayout
    };
}
