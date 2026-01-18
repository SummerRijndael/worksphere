<script setup lang="ts">
import { computed, ref, watch, onMounted, onUnmounted } from 'vue';
import { DialogRoot, DialogPortal, DialogOverlay, DialogContent, DialogClose, DialogTitle, DialogDescription, AccordionRoot, AccordionItem, AccordionTrigger, AccordionContent } from 'reka-ui';
import { Icon, Modal, ConfirmPasswordModal, Button } from '@/components/ui';
import { useAvatar } from '@/composables/useAvatar';
import { useChatStore } from '@/stores/chat';
import type { Chat, MediaItem, ChatParticipant } from '@/types/models/chat';
import AddMemberModal from './AddMemberModal.vue';

interface Props {
  open: boolean;
  chat: Chat;
  mediaItems: MediaItem[];
  mediaLoading: boolean;
  currentUserId: number | string;
  currentUserPublicId?: string;
  // New props for pagination
  hasMoreMedia?: boolean;
  loadMoreMedia?: () => void;
  // Storage stats
  storageStats?: { file_count: number; usage_mb: number; limit_mb: number; percentage_used: number } | null;
}

const props = defineProps<Props>();
const avatar = useAvatar();
const chatStore = useChatStore();
const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'fetch-media'): void;
    (e: 'delete-media', id: number): void;
    (e: 'rename-group', name: string): void;
}>();

const isGroup = computed(() => props.chat?.type === 'group');
const participantCount = computed(() => props.chat?.participants?.length || 0);

// User & Role Logic
const isOwner = computed(() => {
    if (!props.chat || !props.currentUserPublicId) return false;
    const self = props.chat.participants.find(p => p.public_id === props.currentUserPublicId);
    return self?.role === 'owner';
});

const canKick = (member: ChatParticipant) => {
    return isOwner.value && member.public_id !== props.currentUserPublicId;
};

const chatTitle = computed(() => {
  if (!props.chat) return '';
  if (props.chat.name) return props.chat.name;
  if (props.chat.type === 'dm' && props.chat.participants?.length) {
    if (props.currentUserPublicId) {
      const other = props.chat.participants.find(p => p.public_id !== props.currentUserPublicId);
      return other?.name || 'Chat';
    }
    return props.chat.participants[0]?.name || 'Chat';
  }
  return 'Group Chat';
});

const avatarData = computed(() => {
  if (!props.chat) return avatar.resolve(null);
  return avatar.resolveChatAvatar(props.chat, props.currentUserPublicId);
});

const avatarUrl = computed(() => avatarData.value.url);

const avatarError = ref(false);
const handleAvatarError = () => {
  avatarError.value = true;
};

// Reset error when url changes
watch(avatarUrl, () => {
    avatarError.value = false;
});

const handleOpenChange = (open: boolean) => {
  if (!open) emit('close');
};

const showAddMember = ref(false);
const handleMemberAdded = () => {
    // Refresh logic handled by store/events usually
};

// =============================================================================
// Actions: Leave, Kick, Delete
// =============================================================================

// Leave
const showLeaveConfirm = ref(false);
const leaveLoading = ref(false);
const handleLeaveClick = () => { showLeaveConfirm.value = true; };
const confirmLeave = async () => {
    if (!props.chat) return;
    leaveLoading.value = true;
    try {
        await chatStore.leaveGroup(props.chat.public_id);
        emit('close');
    } catch (e) {
        console.error(e);
    } finally {
        leaveLoading.value = false;
        showLeaveConfirm.value = false;
    }
};

// Kick
const showKickConfirm = ref(false);
const kickLoading = ref(false);
const kickTarget = ref<ChatParticipant | null>(null);
const handleKickClick = (member: ChatParticipant) => {
    kickTarget.value = member;
    showKickConfirm.value = true;
};
const confirmKick = async () => {
    if (!props.chat || !kickTarget.value) return;
    kickLoading.value = true;
    try {
        await chatStore.kickMember(props.chat.public_id, kickTarget.value.public_id);
    } catch (e) {
        console.error(e);
    } finally {
        kickLoading.value = false;
        showKickConfirm.value = false;
        kickTarget.value = null;
    }
};

// Rename Group
const isRenameModalOpen = ref(false);
const newGroupName = ref('');
const renamingGroup = ref(false);

function openRenameModal() {
  newGroupName.value = props.chat?.name || '';
  isRenameModalOpen.value = true;
}

async function handleRenameGroup() {
  if (!props.chat || !newGroupName.value.trim() || renamingGroup.value) return;
  
  renamingGroup.value = true;
  try {
    await chatStore.updateGroup(props.chat.public_id, { name: newGroupName.value });
    isRenameModalOpen.value = false;
  } catch (error) {
    console.error('Failed to rename group:', error);
  } finally {
    renamingGroup.value = false;
  }
}

// Avatar Change
const fileInput = ref<HTMLInputElement | null>(null);

function triggerAvatarUpload() {
  fileInput.value?.click();
}

async function handleAvatarChanged(event: Event) {
  const input = event.target as HTMLInputElement;
  if (!input.files?.length || !props.chat) return;
  
  const file = input.files[0];
  
  try {
      await chatStore.updateGroup(props.chat.public_id, { avatar: file });
  } catch (error) {
      console.error('Failed to update avatar:', error);
  } finally {
      input.value = ''; // Reset input
  }
}

// Delete
const showDeleteConfirm = ref(false);
const deleteLoading = ref(false);
const handleDeleteClick = () => { showDeleteConfirm.value = true; };
const confirmDelete = async (password: string) => {
    if (!props.chat) return;
    deleteLoading.value = true;
    try {
        await chatStore.deleteGroup(props.chat.public_id, password);
        emit('close');
    } catch (e) {
        console.error(e);
        // Error handling should be done in modal or toast
    } finally {
        deleteLoading.value = false;
        showDeleteConfirm.value = false;
    }
};

// =============================================================================
// Media Infinite Scroll
// =============================================================================
const mediaSentinel = ref<HTMLElement | null>(null);
let mediaObserver: IntersectionObserver | null = null;

onMounted(() => {
    mediaObserver = new IntersectionObserver((entries) => {
        if (entries[0]?.isIntersecting && props.hasMoreMedia && props.loadMoreMedia && !props.mediaLoading) {
            props.loadMoreMedia();
        }
    }, { threshold: 0.1 });
});

onUnmounted(() => {
    if (mediaObserver) {
        mediaObserver.disconnect();
        mediaObserver = null;
    }
});

// Watch for sentinel element changes
watch(mediaSentinel, (el) => {
    if (el && mediaObserver) {
        mediaObserver.observe(el);
    }
});
</script>

<template>
  <DialogRoot :open="open" @update:open="handleOpenChange">
    <DialogPortal>
      <DialogOverlay 
        class="fixed inset-0 bg-black/30 backdrop-blur-sm z-40 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" 
      />
      
      <DialogContent 
        class="fixed top-0 right-0 bottom-0 w-80 max-w-full z-50 bg-[var(--surface-elevated)] border-l border-[var(--border-default)] shadow-2xl flex flex-col data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right duration-300"
      >
        <!-- Header -->
        <div class="flex-none flex items-center justify-between p-4 border-b border-[var(--border-default)] bg-[var(--surface-elevated)]">
          <DialogTitle class="text-lg font-bold text-[var(--text-primary)]">
            Chat Info
          </DialogTitle>
          <DialogDescription class="sr-only">
            Chat information and settings
          </DialogDescription>
          <DialogClose as-child>
            <button class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)]">
              ✕
            </button>
          </DialogClose>
        </div>

        <!-- Scrollable Content -->
        <div v-if="chat" class="flex-1 overflow-y-auto">
          <div class="p-6 pb-4 text-center border-b border-[var(--border-default)]">
            <div 
              class="w-24 h-24 mx-auto rounded-3xl relative overflow-hidden shadow-sm"
              :class="(avatarUrl && !avatarError) ? '' : 'bg-gradient-to-br from-[var(--interactive-primary)] to-[var(--interactive-primary-hover)]'"
            >
              <img 
                v-if="avatarUrl && !avatarError"
                :src="avatarUrl"
                class="w-full h-full object-cover"
                alt="Chat Avatar"
                @error="handleAvatarError"
              />
              <span v-else class="flex items-center justify-center h-full text-white text-3xl font-bold">
                {{ avatarData.initials }}
              </span>
            </div>
            <h3 class="mt-4 text-xl font-bold text-[var(--text-primary)]">{{ chatTitle }}</h3>
            <p class="text-sm text-[var(--text-secondary)] mt-1">
              {{ participantCount }} member{{ participantCount !== 1 ? 's' : '' }}
            </p>
          </div>

          <AccordionRoot type="multiple" class="w-full">
            <!-- Group Settings -->
            <AccordionItem v-if="isGroup" value="settings" class="border-b border-[var(--border-default)]">
              <AccordionTrigger class="flex items-center justify-between w-full p-4 text-sm font-semibold text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] transition-colors">
                <span>Group Settings</span>
                <Icon name="ChevronDown" :size="16" class="transform transition-transform duration-200" />
              </AccordionTrigger>
              <AccordionContent class="bg-[var(--surface-secondary)]/30 animate-accordion-down overflow-hidden">
                 <div class="p-2 space-y-1">
                    <button 
                      class="w-full text-left p-3 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)] text-sm transition-colors flex items-center gap-3"
                      @click="openRenameModal" 
                    >
                      <Icon name="Pencil" :size="16" />
                      <span>Rename Group</span>
                    </button>
                    
                    <button 
                      class="w-full text-left p-3 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-secondary)] text-sm transition-colors flex items-center gap-3"
                      @click="triggerAvatarUpload"
                    >
                      <Icon name="Image" :size="16" />
                      <span>Change Avatar</span>
                    </button>
                    <!-- Hidden File Input -->
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/*"
                        class="hidden"
                        @change="handleAvatarChanged"
                    />
                 </div>
              </AccordionContent>
            </AccordionItem>

            <!-- Members -->
            <AccordionItem value="members" class="border-b border-[var(--border-default)]">
              <AccordionTrigger class="flex items-center justify-between w-full p-4 text-sm font-semibold text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] transition-colors">
                <span>Members</span>
                <Icon name="ChevronDown" :size="16" class="transform transition-transform duration-200" />
              </AccordionTrigger>
              <AccordionContent class="bg-[var(--surface-secondary)]/30 animate-accordion-down overflow-hidden">
                <div class="p-2">
                    <div class="flex items-center justify-between px-2 py-2 mb-2">
                      <span class="text-xs font-medium text-[var(--text-tertiary)]">{{ chat.participants.length }} people</span>
                      <button 
                        v-if="isGroup"
                        class="text-xs text-[var(--interactive-primary)] hover:underline flex items-center gap-1 font-medium"
                        @click="showAddMember = true"
                      >
                        <Icon name="Plus" :size="12" />
                        Add Member
                      </button>
                    </div>
                    <div class="space-y-1">
                      <div 
                        v-for="member in chat.participants" 
                        :key="member.public_id"
                        class="group flex items-center gap-3 p-2 rounded-lg hover:bg-[var(--surface-tertiary)] transition-colors"
                      >
                        <div 
                          class="w-8 h-8 rounded-lg bg-cover bg-center shrink-0"
                          :class="avatar.getAvatarUrl(member) ? '' : 'bg-[var(--interactive-primary)]'"
                          :style="avatar.getAvatarUrl(member) ? { backgroundImage: `url(${avatar.getAvatarUrl(member)})` } : { backgroundColor: avatar.getColorFromId(member.public_id) }"
                        >
                          <span v-if="!avatar.getAvatarUrl(member)" class="flex items-center justify-center h-full text-white text-xs font-semibold">
                            {{ avatar.getInitials(member.name) }}
                          </span>
                        </div>
                        <div class="flex-1 min-w-0">
                          <div class="font-medium text-sm text-[var(--text-primary)] truncate flex items-center gap-1">
                            {{ member.name }}
                            <span v-if="member.role === 'owner'" class="text-[10px] bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 px-1.5 py-0.5 rounded">Owner</span>
                          </div>
                        </div>
                        <!-- Kick Action -->
                        <button 
                            v-if="canKick(member)"
                            class="opacity-0 group-hover:opacity-100 p-1.5 rounded-md hover:bg-red-100 dark:hover:bg-red-900/30 text-red-500 transition-all"
                            title="Remove member"
                            @click.stop="handleKickClick(member)"
                        >
                            <Icon name="LogOut" :size="14" />
                        </button>
                      </div>
                    </div>
                </div>
              </AccordionContent>
            </AccordionItem>

            <!-- Shared Files -->
            <AccordionItem value="files" class="border-b border-[var(--border-default)]">
              <AccordionTrigger class="flex items-center justify-between w-full p-4 text-sm font-semibold text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] transition-colors">
                <span>Shared Files</span>
                <Icon name="ChevronDown" :size="16" class="transform transition-transform duration-200" />
              </AccordionTrigger>
              <AccordionContent class="bg-[var(--surface-secondary)]/30 animate-accordion-down overflow-hidden">
                <div class="p-4">
                     <div class="flex justify-between items-center mb-3">
                         <span class="text-xs text-[var(--text-tertiary)]">
                           {{ storageStats?.file_count ?? mediaItems.length }} file{{ (storageStats?.file_count ?? mediaItems.length) !== 1 ? 's' : '' }} uploaded
                         </span>
                        <button 
                            class="text-xs text-[var(--interactive-primary)] hover:underline flex items-center gap-1"
                            @click="emit('fetch-media')"
                        >
                            <Icon name="RefreshCw" :size="12" />
                            Refresh
                        </button>
                     </div>

                    <div v-if="mediaLoading && !mediaItems.length" class="flex justify-center py-4">
                      <div class="w-5 h-5 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin" />
                    </div>
                    
                    <!-- Media Grid -->
                    <template v-else-if="mediaItems.length">
                      <div class="mv-gallery grid grid-cols-3 gap-2">
                        <div 
                          v-for="item in mediaItems" 
                          :key="item.id"
                          class="aspect-square rounded-lg bg-[var(--surface-tertiary)] overflow-hidden border border-[var(--border-default)] cursor-pointer hover:ring-2 hover:ring-[var(--interactive-primary)] transition-all"
                        >
                          <img 
                            v-if="item.is_image" 
                            :src="item.thumb_url || item.url" 
                            :data-full="item.url"
                            :data-download="item.download_url"
                            :data-media-id="item.id"
                            :alt="item.name"
                            class="w-full h-full object-cover"
                          />
                          <div v-else class="flex items-center justify-center h-full text-[var(--text-tertiary)]">
                               <Icon name="FileText" :size="24" />
                          </div>
                        </div>
                      </div>

                      <!-- Infinite scroll sentinel -->
                      <div 
                        v-if="hasMoreMedia" 
                        ref="mediaSentinel" 
                        class="flex justify-center py-3"
                      >
                        <div class="w-5 h-5 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin opacity-50" />
                      </div>
                    </template>
                    
                    <!-- Empty State (only when no files exist) -->
                    <div v-else class="text-center py-8 text-sm text-[var(--text-tertiary)]">
                      <div class="mb-2 flex justify-center">
                        <Icon name="FolderOpen" :size="32" class="opacity-30" />
                      </div>
                      <div>No shared files yet</div>
                    </div>
                </div>
              </AccordionContent>
            </AccordionItem>
          </AccordionRoot>
        </div>
        
        <!-- Empty State -->
        <div v-else class="flex-1 flex items-center justify-center text-[var(--text-secondary)]">
          Select a chat to view info
        </div>

        <!-- Sticky Footer (Danger Zone) -->
        <div v-if="chat && isGroup" class="flex-none p-4 bg-[var(--surface-tertiary)] border-t border-[var(--border-default)]">
            <button 
                v-if="!isOwner"
                class="w-full flex items-center justify-center gap-2 p-3 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-900/10 dark:text-red-400 dark:hover:bg-red-900/20 transition-colors text-sm font-medium"
                @click="handleLeaveClick"
            >
                <Icon name="LogOut" :size="16" />
                Leave Group
            </button>
            <button 
                v-if="isOwner"
                class="w-full flex items-center justify-center gap-2 p-3 rounded-lg bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-900/10 dark:text-red-400 dark:hover:bg-red-900/20 transition-colors text-sm font-medium"
                @click="handleDeleteClick"
            >
                <Icon name="Trash2" :size="16" />
                Delete Group
            </button>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>

  <AddMemberModal 
    v-if="chat"
    v-model:isOpen="showAddMember"
    :chatId="chat.public_id"
    :excludeUserIds="chat.participants.map(p => p.public_id)"
    @added="handleMemberAdded"
  />

  <!-- Management Modals -->
  <Modal
    :open="showLeaveConfirm"
    title="Leave Group"
    description="Are you sure you want to leave this group? You won't be able to rejoin unless invited."
    size="sm"
    @update:open="showLeaveConfirm = $event"
    @close="showLeaveConfirm = false"
  >
      <template #footer>
          <Button variant="ghost" @click="showLeaveConfirm = false" :disabled="leaveLoading">Cancel</Button>
          <Button variant="destructive" :loading="leaveLoading" @click="confirmLeave">Leave Group</Button>
      </template>
  </Modal>

  <Modal
    :open="showKickConfirm"
    title="Remove Member"
    :description="`Are you sure you want to remove ${kickTarget?.name}?`"
    size="sm"
    @update:open="showKickConfirm = $event"
    @close="showKickConfirm = false"
  >
      <template #footer>
          <Button variant="ghost" @click="showKickConfirm = false" :disabled="kickLoading">Cancel</Button>
          <Button variant="destructive" :loading="kickLoading" @click="confirmKick">Remove</Button>
      </template>
  </Modal>

  <ConfirmPasswordModal
    :open="showDeleteConfirm"
    title="Delete Group"
    description="This action cannot be undone. All messages and media will be permanently deleted."
    submitText="Delete Group"
    submitVariant="destructive"
    :loading="deleteLoading"
    @update:open="showDeleteConfirm = $event"
    @confirm="confirmDelete"
    @cancel="showDeleteConfirm = false"
  />

  <Modal
    :open="isRenameModalOpen"
    title="Rename Group"
    description="Enter a new name for this group."
    size="sm"
    @update:open="isRenameModalOpen = $event"
    @close="isRenameModalOpen = false"
  >
    <div class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-[var(--text-secondary)] mb-1">Group Name</label>
            <input 
                v-model="newGroupName"
                type="text" 
                class="w-full px-3 py-2 bg-[var(--surface-secondary)] border border-[var(--border-default)] rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)] text-[var(--text-primary)]"
                placeholder="e.g. Project Alpha"
                @keyup.enter="handleRenameGroup"
            />
        </div>
    </div>
    <template #footer>
        <Button variant="ghost" @click="isRenameModalOpen = false" :disabled="renamingGroup">Cancel</Button>
        <Button :loading="renamingGroup" @click="handleRenameGroup" :disabled="!newGroupName.trim()">Save</Button>
    </template>
  </Modal>

</template>
