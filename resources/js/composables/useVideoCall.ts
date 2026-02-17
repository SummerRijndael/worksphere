/**
 * useVideoCall — Parent-side composable for the popup call architecture.
 *
 * This composable NO LONGER manages WebRTC directly. Instead it:
 * 1. Receives incoming call events (from Echo via CustomEvent)
 * 2. Opens the standalone call page in a popup window
 * 3. Passes call data via sessionStorage
 * 4. Listens to BroadcastChannel for state updates from the popup
 *
 * All WebRTC logic lives in CallApp.vue (the popup).
 */
import { ref, onBeforeUnmount } from 'vue';
import { useVideoCallStore, type CallType } from '@/stores/videocall';
import { videoCallService } from '@/services/videocall.service';
import { useAuthStore } from '@/stores/auth';
import { useChatStore } from '@/stores/chat';
import { toast } from 'vue-sonner';

// Singleton state
let initialized = false;
let callPopup: Window | null = null;
let broadcastChannel: BroadcastChannel | null = null;
let ringtoneAudio: HTMLAudioElement | null = null;
let ringtoneTimeout: ReturnType<typeof setTimeout> | null = null;

// Pending signals (offer + ICE candidates) for incoming calls (received before user accepts)
const pendingSignals = ref<any[]>([]);
const processedIncomingCalls = new Set<string>(); // For deduplication

export function useVideoCall() {
  const store = useVideoCallStore();
  const authStore = useAuthStore();
  const chatStore = useChatStore();

  // ============================================================================
  // Popup Window Management
  // ============================================================================

  function openCallPopup(callId: string) {
    const width = 1024;
    const height = 768;
    const left = window.screenX + window.outerWidth - width - 24;
    const top = window.screenY + 80;

    console.log('[VideoCall] Opening popup for call:', callId);
    console.log('[VideoCall] Popup dimensions:', { width, height, left, top });

    callPopup = window.open(
      `/call/${callId}`,
      `worksphere-call-${callId}`,
      `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=no,toolbar=no,menubar=no,location=no,status=no`,
    );

    if (!callPopup) {
      console.error('[VideoCall] ❌ Popup blocked by browser');
      toast.error('Popup Blocked', {
        description: 'Please allow popups for this site to make calls.',
      });
      cleanup();
      return;
    }

    console.log('[VideoCall] Popup opened successfully');

    // Monitor popup close
    const checkInterval = setInterval(() => {
      if (callPopup?.closed) {
        console.log('[VideoCall] Popup window closed detected via interval');
        clearInterval(checkInterval);
        handlePopupClosed();
      }
    }, 1000);
  }

  function handlePopupClosed() {
    console.log('[VideoCall] handlePopupClosed: cleaning up state');
    callPopup = null;
    cleanup();
  }

  // ============================================================================
  // BroadcastChannel (receive state from popup)
  // ============================================================================

  function ensureBroadcastChannel() {
    if (broadcastChannel) return;
    console.log('[VideoCall] Initializing BroadcastChannel "worksphere-call"');
    broadcastChannel = new BroadcastChannel('worksphere-call');
    broadcastChannel.onmessage = (event) => {
      const msg = event.data;
      if (!msg) return;

      console.log('[VideoCall] 📥 Broadcast message received:', msg);

      switch (msg.type) {
        case 'state':
          console.log('[VideoCall] Syncing state from popup:', msg.state);
          if (msg.state === 'connected') {
            store.setState('connected');
            stopRingtone();
          } else if (msg.state === 'ended') {
            if (msg.reason === 'declined') {
              const remoteName = store.currentCall?.remoteUser?.name || 'User';
              toast.info(`${remoteName} declined the call`);
            } else if (msg.reason === 'timeout') {
              toast.info('Call was not answered');
            } else {
              toast.info('Call ended');
            }
            cleanup();
          }
          break;
      }
    };
  }

  // ============================================================================
  // Outgoing Call
  // ============================================================================

  async function startCall(chatId: string, callType: CallType, user: { publicId: string; name: string; avatar: string | null }) {
    console.log('[VideoCall] startCall initiated:', { chatId, callType, user: user.name });

    if (store.isCallActive) {
      console.warn('[VideoCall] Blocked: call already active');
      toast.warning('You are already in a call');
      return;
    }

    store.setState('initiating');

    try {
      console.log('[VideoCall] Requesting call initiation from API...');
      const res = await videoCallService.initiateCall(chatId, callType);
      console.log('[VideoCall] API response:', res);
      const { call_id, busy_participants, offline_participants } = res;

      if (busy_participants?.length > 0) {
          toast.info(`${busy_participants.join(', ')} ${busy_participants.length > 1 ? 'are' : 'is'} busy`);
      }
      if (offline_participants?.length > 0) {
          toast.info(`${offline_participants.join(', ')} ${offline_participants.length > 1 ? 'are' : 'is'} offline`);
      }

      // Create outgoing call structure with self as first participant
      const participants = new Map();
      // We don't add ourselves to the store participants map typically, 
      // but we need to track who we are calling if it's a DM.
      // For Group calls, we just start empty and wait for joins.
      
      const remoteUser = {
          publicId: user.publicId,
          name: user.name,
          avatar: user.avatar
      };

      const chatType = store.currentCall?.chatType || (user.publicId === 'group' ? 'group' : 'dm');

      store.setCall({
        callId: call_id,
        chatId,
        callType,
        participants, // Initially empty for group, populated for DM below
        isOutgoing: true,
        chatType,
        startedAt: null,
      });

      // If it's a DM, we effectively "add" the other person as a placeholder
      // so the UI knows who we are calling.
      // BUT for the hybrid approach, we want to just open the window and wait.
      console.log('[VideoCall] Storing callData in sessionStorage');
      
      const dataToStore = {
        callId: call_id,
        chatId,
        callType,
        chatType,
        direction: 'outgoing',
        remoteUser, // Kept for backwards compatibility/DM display
        selfPublicId: authStore.user?.public_id,
      };

      sessionStorage.setItem('callData', JSON.stringify(dataToStore));

      ensureBroadcastChannel();
      openCallPopup(call_id);

    } catch (err) {
      console.error('[VideoCall] ❌ Failed to start call:', err);
      toast.error('Failed to start call');
      cleanup();
    }
  }

  // ============================================================================
  // Incoming Call Handling
  // ============================================================================

  function handleIncomingCall(data: {
    call_id: string;
    call_type: CallType;
    caller_public_id: string;
    caller_name: string;
    caller_avatar: string | null;
    chat_id: string;
    chat_type?: 'dm' | 'group' // Added by backend event
  }) {
    console.log('[VideoCall] 📞 handleIncomingCall:', data);

    // Ignore our own events
    if (data.caller_public_id === authStore.user?.public_id) return;

    // Deduplication: Prevents double toasts/ringing for the same callId
    if (processedIncomingCalls.has(data.call_id)) {
        console.log('[VideoCall] Dropping duplicate incoming call event:', data.call_id);
        return;
    }
    processedIncomingCalls.add(data.call_id);
    // Cleanup old IDs (simple limit)
    if (processedIncomingCalls.size > 50) {
        const first = processedIncomingCalls.values().next().value;
        if (first) processedIncomingCalls.delete(first);
    }
    if (store.isCallActive) {
      const isDifferentCall = store.currentCall?.callId !== data.call_id;
      const isPopupStale = !callPopup || callPopup.closed;

      if (isDifferentCall || isPopupStale) {
        console.log('[VideoCall] Detected different or stale call state, forcing cleanup');
        cleanup();
      } else {
        console.warn('[VideoCall] Dropping incoming call: this call is already active');
        return;
      }
    }


    // Unified Ringing Experience for both DM and Group calls
    const chat = chatStore.chats.find(c => c.id === data.chat_id);
    const chatName = chat?.name || null;

    // Register active call for UI indicators
    store.registerActiveCall(data.chat_id, data.call_id, data.call_type);

    store.setCall({
      callId: data.call_id,
      chatId: data.chat_id,
      callType: data.call_type,
      participants: new Map([[data.caller_public_id, {
          publicId: data.caller_public_id,
          name: data.caller_name,
          avatar: data.caller_avatar
      }]]),
      isOutgoing: false,
      chatType: data.chat_type || 'dm',
      chatName: chatName, // Pass to store for UI display
      startedAt: null,
    });

    store.setState('ringing');
    playRingtone('incoming');

    // Double check local presence preference
    if (authStore.user?.presence === 'busy') {
        console.log('[VideoCall] Suppressing incoming call ring: status is busy');
        videoCallService.endCall(data.chat_id, data.call_id, 'busy').catch(() => {});
        // Still register active call so user can see it in chat list, but don't show overlay
        store.setState('idle');
        stopRingtone();
        return;
    }

    // Auto-decline after 45 seconds
    ringtoneTimeout = setTimeout(() => {
      if (store.callState === 'ringing') {
        timeoutCall();
      }
    }, 45000);
  }

  function joinActiveCall(chatId: string, callId: string, callType: CallType) {
      if (store.isCallActive) {
          toast.warning('You are already in a call');
          return;
      }

      console.log('[VideoCall] Joining active call:', { chatId, callId });
      
      // Set minimal state
      store.setCall({
          callId,
          chatId,
          callType,
          participants: new Map(),
          isOutgoing: false,
          startedAt: null,
      });
      store.setState('connecting');

      const dataToStore = {
          callId,
          chatId,
          callType,
          chatType: 'group', // joining an active call usually implies group/room
          direction: 'incoming', // treated as incoming join
          selfPublicId: authStore.user?.public_id,
      };
      sessionStorage.setItem('callData', JSON.stringify(dataToStore));
      ensureBroadcastChannel();
      openCallPopup(callId);
  }

  function handleSignal(data: {
    call_id: string;
    signal_type: 'offer' | 'answer' | 'ice-candidate' | 'signal';
    signal_data: any;
    sender_public_id: string;
    target_public_id?: string;
  }) {
    if (data.sender_public_id === authStore.user?.public_id) return;
    
    // If target is specified and it's NOT us, ignore
    if (data.target_public_id && data.target_public_id !== authStore.user?.public_id) return;

    // Buffer if popup is open (it will handle its own signaling once joined)
    // or if we are in ringing state but haven't accepted yet.
    console.log(`[VideoCall] 📥 Received signal from ${data.sender_public_id}. Popup open: ${!!callPopup && !callPopup.closed}`);
    
    pendingSignals.value.push(data);

    // If popup is already open, we also broadcast it, but we MUST keep it in pendingSignals 
    // in case the popup is still in the lobby/initializing phase.
    if (callPopup && !callPopup.closed) {
        // We don't need to do anything else here; the popup might already be listening 
        // to Echo directly, but buffering here ensures we can pass it via sessionStorage 
        // if the popup just opened.
    }
  }

  async function acceptCall() {
    if (!store.currentCall) return;
    const { callId, chatId, callType } = store.currentCall;
    
    stopRingtone();
    store.setState('connecting');

    // For group calls, we don't have a single "remoteUser" really, 
    // but we pass a placeholder or the caller info from the store if available.
    // The popup will call join() and get the real list.
    
    const firstParticipant = store.currentCall.participants.values().next().value;
    const remoteUser = firstParticipant ? {
        publicId: firstParticipant.publicId,
        name: firstParticipant.name,
        avatar: firstParticipant.avatar
    } : { publicId: 'group', name: 'Group Call', avatar: null };

    const dataToStore = {
      callId,
      chatId,
      callType,
      chatType: store.currentCall.chatType || (remoteUser.publicId === 'group' ? 'group' : 'dm'),
      direction: 'incoming',
      remoteUser,
      pendingSignals: [...pendingSignals.value],
      selfPublicId: authStore.user?.public_id,
    };
    
    sessionStorage.setItem('callData', JSON.stringify(dataToStore));
    pendingSignals.value = [];

    ensureBroadcastChannel();
    openCallPopup(callId);
  }

  function declineCall() {
    if (!store.currentCall) return;
    videoCallService.endCall(store.currentCall.chatId, store.currentCall.callId, 'declined').catch(() => {});
    cleanup();
  }

  function timeoutCall() {
    if (!store.currentCall) return;
    videoCallService.endCall(store.currentCall.chatId, store.currentCall.callId, 'no_answer').catch(() => {});
    cleanup();
  }

  function handleCallEnded(data: { call_id: string; ender_public_id: string; reason: string }) {
    // Unregister active call
    for (const [chatId, activeCall] of store.activeCalls.entries()) {
        if (typeof activeCall === 'object' && activeCall.callId === data.call_id) {
            store.unregisterActiveCall(chatId);
            break;
        } else if (typeof activeCall === 'string' && activeCall === data.call_id) {
            // Legacy/Fallback check if map wasn't updated cleanly (shouldn't happen with TS)
            store.unregisterActiveCall(chatId);
            break;
        }
    }

    // For 1:1 calls, if the other person ends it, we close everything.
    // For group calls, "CallEnded" is only sent when the LAST person leaves (implied by backend logic).
    // Or we might receive "CallParticipantLeft".
    
    if (data.ender_public_id === authStore.user?.public_id) return;
    if (!store.currentCall || store.currentCall.callId !== data.call_id) return;

    if (callPopup && !callPopup.closed) return;

    if (data.reason === 'declined') {
        toast.info('Call declined');
    } else if (data.reason === 'no_answer' || data.reason === 'timeout') {
        toast.info('Call was not answered');
    } else if (data.reason === 'busy') {
        toast.info('User is busy');
    } else {
        toast.info('Call ended');
    }
    
    cleanup();
  }

  // ============================================================================
  // Call Controls (from parent side)
  // ============================================================================

  async function endCall(reason: 'hangup' | 'declined' | 'timeout' | 'failed' | 'no_answer' | 'busy' = 'hangup') {
    if (store.currentCall) {
      videoCallService.endCall(store.currentCall.chatId, store.currentCall.callId, reason).catch(() => {});
    }
    broadcastChannel?.postMessage({ type: 'end-call' });
    cleanup();
  }

  // ============================================================================
  // Ringtone
  // ============================================================================

  function playRingtone(type: 'incoming' | 'outgoing') {
    try {
      ringtoneAudio = new Audio(type === 'incoming' ? '/static/sounds/inbound-call.mp3' : '/static/sounds/outbound-call.mp3');
      ringtoneAudio.loop = true;
      ringtoneAudio.volume = 0.5;
      ringtoneAudio.play().catch(() => {});
    } catch { /* noop */ }
  }

  function stopRingtone() {
    if (ringtoneAudio) {
      ringtoneAudio.pause();
      ringtoneAudio.currentTime = 0;
      ringtoneAudio = null;
    }
    if (ringtoneTimeout) {
      clearTimeout(ringtoneTimeout);
      ringtoneTimeout = null;
    }
  }

  // ============================================================================
  // Cleanup
  // ============================================================================

  function cleanup() {
    stopRingtone();
    pendingSignals.value = [];
    callPopup = null;
    store.reset();
  }

  // ============================================================================
  // Global Event Listener Setup (called once from AppLayout)
  // ============================================================================

  function setupGlobalListeners() {
    if (initialized) return;
    initialized = true;
    console.log('[VideoCall] Global listeners initialized (popup architecture)');

    window.addEventListener('videocall:incoming', (e: Event) => {
      handleIncomingCall((e as CustomEvent).detail);
    });
    window.addEventListener('videocall:signal', (e: Event) => {
      handleSignal((e as CustomEvent).detail);
    });
    window.addEventListener('videocall:ended', (e: Event) => {
      handleCallEnded((e as CustomEvent).detail);
    });
    // Group call specific events are handled inside the popup mainly, 
    // but the parent might want to know about joins/leaves for the "Call Active" indicator
    
    ensureBroadcastChannel();
  }

  return {
    startCall,
    acceptCall,
    declineCall,
    endCall,
    handleIncomingCall,
    handleSignal,
    handleCallEnded: handleCallEnded,
    cleanup,
    setupGlobalListeners,
    joinActiveCall,
  };
}
