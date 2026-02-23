import { defineStore } from 'pinia';
import { ref, computed, reactive } from 'vue';

export type CallState = 'idle' | 'initiating' | 'ringing' | 'connecting' | 'connected' | 'ended';
export type CallType = 'video' | 'audio';

export interface Participant {
  publicId: string;
  name: string;
  avatar: string | null;
  isSelf?: boolean;
}

export interface CallInfo {
  callId: string;
  chatId: string;
  callType: CallType;
  participants: Map<string, Participant>;
  isOutgoing: boolean;
  chatType: 'dm' | 'group';
  chatName: string | null;
  startedAt: number | null;
  remoteUser?: Participant; // Added for UI context
}

export const useVideoCallStore = defineStore('videoCall', () => {
  // ============================================================================
  // State
  // ============================================================================
  const callState = ref<CallState>('idle');
  const currentCall = ref<CallInfo | null>(null);
  const localStream = ref<MediaStream | null>(null);
  
  // Maps publicId -> Stream
  const remoteStreams = reactive(new Map<string, MediaStream>());
  const remoteScreenStreams = reactive(new Map<string, MediaStream>());
  
  const isMuted = ref(false);
  const isCameraOff = ref(false);
  const callDuration = ref(0);
  const error = ref<string | null>(null);
  const activeCallId = ref<string | null>(null); // For "Call in progress" indicator in chat
  const selfPublicId = ref<string | null>(null);

  // Maps publicId -> Volume (0-100)
  const remoteVolumes = reactive(new Map<string, number>());

  // Track active calls in other chats: Map<chatId, { callId: string, callType: CallType }>
  const activeCalls = ref<Map<string, { callId: string, callType: CallType }>>(new Map());

  let durationTimer: ReturnType<typeof setInterval> | null = null;

  // Settings
  const globalVolume = ref(1.0); // 0.0 to 1.0
  const selectedAudioDeviceId = ref<string | null>(null);
  const selectedVideoDeviceId = ref<string | null>(null);
  const selectedOutputDeviceId = ref<string | null>(null);
  const videoEffect = ref<'none' | 'blur' | 'image'>('none');
  const backgroundImage = ref<string | null>(null);
  const autoFraming = ref(false);
  const videoFitMode = ref<'cover' | 'contain'>('contain');

  // ============================================================================
  // Getters
  // ============================================================================
  const isCallActive = computed(() =>
    ['initiating', 'ringing', 'connecting', 'connected'].includes(callState.value),
  );

  const isRinging = computed(() => callState.value === 'ringing');
  const isConnected = computed(() => callState.value === 'connected');
  const isIncoming = computed(() => currentCall.value !== null && !currentCall.value.isOutgoing && callState.value === 'ringing');

  const formattedDuration = computed(() => {
    const mins = Math.floor(callDuration.value / 60);
    const secs = callDuration.value % 60;
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  });

  const participantCount = computed(() => currentCall.value?.participants.size || 0);

  // ============================================================================
  // Actions
  // ============================================================================

  function setGlobalVolume(vol: number) {
      globalVolume.value = Math.max(0, Math.min(1, vol));
  }

  function setSelectedAudioDevice(deviceId: string) {
      selectedAudioDeviceId.value = deviceId;
  }

  function setSelectedVideoDevice(deviceId: string) {
      selectedVideoDeviceId.value = deviceId;
  }

  function registerActiveCall(chatId: string, callId: string, callType: CallType = 'video') {
      activeCalls.value.set(chatId, { callId, callType });
  }

  function unregisterActiveCall(chatId: string) {
      activeCalls.value.delete(chatId);
  }

  async function checkActiveCall(chatId: string) {
      try {
          const mod = await import('@/services/videocall.service');
          if (!mod?.videoCallService) {
              console.warn('videoCallService not available yet');
              return;
          }
          const { active, call_id, type } = await mod.videoCallService.getActiveCall(chatId);
          if (active && call_id) {
              registerActiveCall(chatId, call_id, type || 'video');
          } else {
              unregisterActiveCall(chatId);
          }
      } catch (e) {
          console.error('Failed to check active call:', e);
      }
  }


  function setCall(info: CallInfo) {
    currentCall.value = info;
    activeCallId.value = info.callId;
  }

  function addParticipant(p: Participant) {
    if (currentCall.value) {
        currentCall.value.participants.set(p.publicId, p);
    }
  }

  function removeParticipant(publicId: string) {
    if (currentCall.value) {
        currentCall.value.participants.delete(publicId);
    }
    remoteStreams.delete(publicId);
    remoteScreenStreams.delete(publicId);
  }

  function setState(state: CallState) {
    callState.value = state;

    if (state === 'connected') {
      if (currentCall.value && !currentCall.value.startedAt) {
          currentCall.value.startedAt = Date.now();
      }
      startDurationTimer();
    }

    if (state === 'ended' || state === 'idle') {
      stopDurationTimer();
      activeCallId.value = null;
    }
  }

  function setLocalStream(stream: MediaStream | null) {
    localStream.value = stream;
  }

  function addRemoteStream(publicId: string, stream: MediaStream) {
    const active = remoteStreams.get(publicId);
    if (active) {
      stream.getTracks().forEach((track) => {
        if (!active.getTracks().find((t) => t.id === track.id)) {
          active.addTrack(track);
        }
      });
      // Set again to trigger reactivity for any watchers on the Map itself
      remoteStreams.set(publicId, active);
    } else {
      remoteStreams.set(publicId, stream);
    }
  }

  function removeRemoteStream(publicId: string) {
    remoteStreams.delete(publicId);
    remoteVolumes.delete(publicId);
  }

  function addRemoteScreenStream(publicId: string, stream: MediaStream) {
    const active = remoteScreenStreams.get(publicId);
    if (active) {
      stream.getTracks().forEach((track) => {
        if (!active.getTracks().find((t) => t.id === track.id)) {
          active.addTrack(track);
        }
      });
      remoteScreenStreams.set(publicId, active);
    } else {
      remoteScreenStreams.set(publicId, stream);
    }
  }

  function removeRemoteScreenStream(publicId: string) {
    remoteScreenStreams.delete(publicId);
  }

  function toggleMute() {
    isMuted.value = !isMuted.value;
    if (localStream.value) {
      localStream.value.getAudioTracks().forEach((track) => {
        track.enabled = !isMuted.value;
      });
    }
  }

  function toggleCamera() {
    isCameraOff.value = !isCameraOff.value;
    if (localStream.value) {
      localStream.value.getVideoTracks().forEach((track) => {
        track.enabled = !isCameraOff.value;
      });
    }
  }

  function setError(msg: string | null) {
    error.value = msg;
  }

  function startDurationTimer() {
    // Only start if not running
    if (durationTimer) return;
    callDuration.value = 0;
    durationTimer = setInterval(() => {
      callDuration.value++;
    }, 1000);
  }

  function stopDurationTimer() {
    if (durationTimer) {
      clearInterval(durationTimer);
      durationTimer = null;
    }
  }

  function reset() {
    // Stop media tracks
    if (localStream.value) {
      localStream.value.getTracks().forEach((t) => t.stop());
    }

    callState.value = 'idle';
    currentCall.value = null;
    localStream.value = null;
    remoteStreams.clear(); 
    remoteScreenStreams.clear();
    remoteVolumes.clear();
    isMuted.value = false;
    isCameraOff.value = false;
    callDuration.value = 0;
    error.value = null;
    activeCallId.value = null;
    selfPublicId.value = null;
    stopDurationTimer();
  }

  return {
    // State
    callState,
    currentCall,
    localStream,
    remoteStreams,
    remoteScreenStreams,
    isMuted,
    isCameraOff,
    callDuration,
    error,
    activeCallId,
    activeCalls,
    selfPublicId,
    remoteVolumes,
    globalVolume,
    selectedAudioDeviceId,
    selectedVideoDeviceId,
    // Getters
    isCallActive,
    isRinging,
    isConnected,
    isIncoming,
    formattedDuration,
    participantCount,
    // Actions
    setCall,
    addParticipant,
    removeParticipant,
    setState,
    setLocalStream,
    addRemoteStream,
    removeRemoteStream,
    addRemoteScreenStream,
    removeRemoteScreenStream,
    toggleMute,
    toggleCamera,
    setError,
    reset,
    registerActiveCall,
    unregisterActiveCall,
    checkActiveCall,
    setGlobalVolume,
    setSelectedAudioDevice,
    setSelectedVideoDevice,
    setSelectedOutputDevice: (deviceId: string) => {
        selectedOutputDeviceId.value = deviceId;
    },
    selectedOutputDeviceId,
    setRemoteVolume: (publicId: string, volume: number) => {
        remoteVolumes.set(publicId, volume);
    },
    videoEffect,
    backgroundImage,
    setVideoEffect: (effect: 'none' | 'blur' | 'image') => {
        videoEffect.value = effect;
    },
    setBackgroundImage: (image: string | null) => {
        backgroundImage.value = image;
    },
    videoFitMode,
    setVideoFitMode: (mode: 'cover' | 'contain') => {
        videoFitMode.value = mode;
    },
    autoFraming,
    setAutoFraming: (enabled: boolean) => {
        autoFraming.value = enabled;
    }
  };
});
