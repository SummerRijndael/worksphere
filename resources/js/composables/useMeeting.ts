import { toast } from 'vue-sonner';
import { isValidUlid, normalizeUlid } from '@/utils/meetingId';

let meetingPopup: Window | null = null;
let broadcastChannel: BroadcastChannel | null = null;

export function useMeeting() {
  function openMeetingPopup(meetingId: string, participantId?: string) {
    if (!isValidUlid(meetingId)) {
      toast.error('Invalid meeting ID', {
        description: 'Please use the meeting link from your invitation email or ask the host to resend it.',
      });
      return;
    }

    const safeMeetingId = normalizeUlid(meetingId);

    let safeParticipantId: string | undefined;
    if (participantId) {
      if (!isValidUlid(participantId)) {
        toast.error('Invalid participant session', {
          description: 'Your join session has expired. Please start again from the lobby.',
        });
        return;
      }
      safeParticipantId = normalizeUlid(participantId);
    }

    const width = 1280;
    const height = 800;
    const left = window.screenX + (window.outerWidth - width) / 2;
    const top = window.screenY + (window.outerHeight - height) / 2;

    let url = `/m/${safeMeetingId}`;
    if (safeParticipantId) {
      url += `?participant=${safeParticipantId}`;
    }

    console.log('[Meeting] Opening popup for meeting:', safeMeetingId);

    meetingPopup = window.open(
      url,
      `worksphere-meeting-${safeMeetingId}`,
      `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=no,toolbar=no,menubar=no,location=no,status=no`
    );

    if (!meetingPopup) {
      console.error('[Meeting] ❌ Popup blocked by browser');
      toast.error('Popup Blocked', {
        description: 'Please allow popups for this site to join meetings.',
      });
      return;
    }

    // Monitor popup close
    const checkInterval = setInterval(() => {
      if (meetingPopup?.closed) {
        clearInterval(checkInterval);
        handlePopupClosed();
      }
    }, 1000);

    ensureBroadcastChannel();
  }

  function handlePopupClosed() {
    console.log('[Meeting] Popup window closed');
    meetingPopup = null;
    // Notify app if needed that meeting ended/closed
  }

  function ensureBroadcastChannel() {
    if (broadcastChannel) return;
    broadcastChannel = new BroadcastChannel('worksphere-meeting');
    broadcastChannel.onmessage = (event) => {
      const msg = event.data;
      if (!msg) return;

      switch (msg.type) {
        case 'meeting-ended':
          toast.info('Meeting has ended');
          if (meetingPopup && !meetingPopup.closed) {
            meetingPopup.close();
          }
          break;
        case 'error':
          toast.error(msg.message || 'An error occurred in the meeting');
          break;
      }
    };
  }

  return {
    openMeetingPopup,
  };
}
