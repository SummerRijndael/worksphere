<script setup lang="ts">
import { computed } from 'vue';
import { useVideoCallStore } from '@/stores/videocall';
import { useVideoCall } from '@/composables/useVideoCall';
import { Icon, Avatar } from '@/components/ui';
import { useAvatar } from '@/composables/useAvatar';

const store = useVideoCallStore();
const { acceptCall, declineCall } = useVideoCall();
const avatar = useAvatar();

const remoteAvatarData = computed(() => {
  if (!store.currentCall) return { initials: '?' };
  const caller = store.currentCall.participants.values().next().value;
  return { initials: avatar.getInitials(caller?.name || '?') };
});

const caller = computed(() => {
    if (!store.currentCall) return null;
    return store.currentCall.participants.values().next().value;
});

const callTypeLabel = computed(() => {
  return store.currentCall?.callType === 'video' ? 'Video Call' : 'Audio Call';
});
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
    >
      <div
        v-if="store.isIncoming"
        class="fixed bottom-4 right-4 left-4 sm:left-auto sm:right-6 sm:bottom-6 z-10000 sm:w-80 bg-(--surface-elevated) border border-(--border-default) rounded-2xl shadow-2xl overflow-hidden"
      >
        <!-- Animated gradient top bar -->
        <div class="h-1 bg-linear-to-r from-green-400 via-emerald-500 to-teal-500 animate-pulse" />

        <div class="p-5">
          <!-- Caller Info -->
          <div class="flex items-center gap-3 mb-4">
            <div class="relative">
              <Avatar
                v-if="caller?.avatar"
                :src="caller.avatar"
                :alt="caller.name || ''"
                :fallback="remoteAvatarData.initials"
                size="lg"
                class="rounded-full ring-2 ring-green-400/50"
              />
              <div
                v-else
                class="w-12 h-12 rounded-full bg-(--surface-tertiary) flex items-center justify-center text-lg font-semibold text-(--text-primary) ring-2 ring-green-400/50"
              >
                {{ remoteAvatarData.initials }}
              </div>
              <!-- Pulsing ring -->
              <div class="absolute inset-0 rounded-full ring-2 ring-green-400/30 animate-ping" />
            </div>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-(--text-primary) truncate">
                {{ caller?.name }}
                <span v-if="store.currentCall?.chatType === 'group' && store.currentCall?.chatName" class="text-(--text-tertiary) font-normal">
                  in {{ store.currentCall.chatName }}
                </span>
              </p>
              <p class="text-xs text-(--text-secondary)">
                Incoming {{ callTypeLabel }}
              </p>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="flex items-center gap-3">
            <button
              class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-red-500 transition-colors text-sm font-medium"
              @click="declineCall()"
            >
              <Icon name="PhoneOff" size="16" />
              Decline
            </button>
            <button
              class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-green-500 hover:bg-green-600 text-white transition-colors text-sm font-medium shadow-lg shadow-green-500/25"
              @click="acceptCall()"
            >
              <Icon :name="store.currentCall?.callType === 'video' ? 'Video' : 'Phone'" size="16" />
              Accept
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
