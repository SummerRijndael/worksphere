<script setup lang="ts">
import { ref, computed } from 'vue';
import type { Chat, ChatMedia } from '@/types/models/chat';

interface Props {
  open: boolean;
  activeChat: Chat | null;
  mediaItems: ChatMedia[];
  mediaLoading: boolean;
  isMobile: boolean;
  currentUserId: number | string;
}

const props = defineProps<Props>();

const emit = defineEmits<{
  'close': [];
  'fetch-media': [];
  'delete-media': [id: number];
  'rename-group': [name: string];
  'add-member': [userId: number];
  'remove-member': [userId: number];
}>();

const drawerTab = ref<'files' | 'group'>('files');
const mediaFilter = ref<'all' | 'images' | 'documents'>('all');
const renameForm = ref('');

// Computed
const isGroupChat = computed(() => props.activeChat?.type !== 'dm');

const filteredMedia = computed(() => {
    if (mediaFilter.value === 'all') return props.mediaItems;
    if (mediaFilter.value === 'images') return props.mediaItems.filter(m => m.mime_type.startsWith('image/'));
    return props.mediaItems.filter(m => !m.mime_type.startsWith('image/'));
});

const images = computed(() => filteredMedia.value.filter(m => m.mime_type.startsWith('image/')));
const docs = computed(() => filteredMedia.value.filter(m => !m.mime_type.startsWith('image/')));

const activeHeader = computed(() => {
    if (!props.activeChat) return '';
    return props.activeChat.name || (props.activeChat.participants[0]?.name);
});

const activeAvatarStyle = computed(() => {
    const url = props.activeChat?.avatar_url || props.activeChat?.participants[0]?.avatar;
    if (url) return { backgroundImage: `url(${url})`, backgroundSize: 'cover' };
    return { background: 'var(--brand)' };
});

function prettySize(bytes: number) {
    if (bytes < 1024) return bytes + ' B';
    const k = 1024;
    const sizes = ['KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// Actions
function saveRename() {
    emit('rename-group', renameForm.value);
}
</script>

<template>
    <div v-if="open" class="v3-backdrop" @click="emit('close')"></div>
    
    <Transition name="slide-left">
        <aside v-if="open" class="v3-drawer">
            <!-- Header -->
            <div class="v3-d-head">
                 <div class="v3-d-peer">
                    <div class="v3-d-ava" :style="activeAvatarStyle"></div>
                    <div>
                        <div class="v3-d-name">{{ activeHeader }}</div>
                         <div class="v3-subtle">{{ isGroupChat ? 'Group Chat' : 'Direct Message' }}</div>
                    </div>
                </div>
                <button class="v3-icon v3-icon--ghost" @click="emit('close')">✕</button>
            </div>

            <!-- Tabs -->
            <div v-if="isGroupChat" class="v3-d-tabs">
                 <button 
                    class="v3-filter" 
                    :class="{ 'is-active': drawerTab === 'group' }"
                    @click="drawerTab = 'group'"
                >Group</button>
                 <button 
                    class="v3-filter" 
                    :class="{ 'is-active': drawerTab === 'files' }"
                    @click="drawerTab = 'files'"
                >Files</button>
            </div>

            <!-- Files Tab -->
            <div v-if="drawerTab === 'files' || !isGroupChat" class="v3-d-block">
                <div class="v3-d-title-row">
                     <div class="v3-d-title">File manager</div>
                     <div class="v3-d-filters">
                        <button class="v3-filter" :class="{ 'is-active': mediaFilter === 'all' }" @click="mediaFilter = 'all'">All</button>
                        <button class="v3-filter" :class="{ 'is-active': mediaFilter === 'images' }" @click="mediaFilter = 'images'">Images</button>
                        <button class="v3-filter" :class="{ 'is-active': mediaFilter === 'documents' }" @click="mediaFilter = 'documents'">Docs</button>
                     </div>
                </div>

                <!-- Images Grid -->
                <div v-if="images.length" class="v3-d-media">
                    <button 
                        v-for="img in images" 
                        :key="img.id" 
                        class="v3-d-thumb"
                    >
                         <img :src="img.url" :alt="img.file_name" />
                         <span class="v3-d-thumb__shine"></span>
                    </button>
                </div>

                 <!-- Docs List -->
                <div v-if="docs.length" class="v3-d-files">
                    <div v-for="file in docs" :key="file.id" class="v3-d-file v3-d-file--row">
                        <div class="v3-d-file__ic">⬇</div>
                        <div class="v3-d-file__meta">
                            <div class="v3-d-file__name">{{ file.file_name }}</div>
                            <div class="v3-subtle">{{ prettySize(file.size_bytes) }}</div>
                        </div>
                        <a :href="file.url" target="_blank" class="v3-d-file__cta">Open</a>
                    </div>
                </div>

                <div v-if="!mediaLoading && !mediaItems.length" class="v3-subtle">
                    No media yet.
                </div>
            </div>

            <!-- Group Tab -->
            <div v-if="isGroupChat && drawerTab === 'group'" class="v3-d-block">
                <div class="v3-d-title">Group Overview</div>
                <!-- Rename -->
                <form class="v3-d-form" @submit.prevent="saveRename" style="margin-top: 10px;">
                    <label class="v3-label">Name</label>
                    <input class="v3-input" v-model="renameForm" :placeholder="activeHeader" />
                </form>

                <div class="v3-d-title" style="margin-top: 20px;">Members</div>
                <div class="v3-d-list">
                    <div v-for="member in activeChat?.participants" :key="member.id" class="v3-d-member">
                         <div class="v3-d-ava v3-d-ava--sm" :style="{ backgroundImage: `url(${member.avatar || ''})` }"></div>
                         <div class="v3-d-member__meta">
                            <div class="font-semibold">{{ member.name }}</div>
                            <div class="v3-d-chip">Member</div>
                         </div>
                    </div>
                </div>
            </div>

        </aside>
    </Transition>
</template>

<style scoped>
.v3-drawer {
    /* Ensure it overlays correctly */
    box-shadow: -5px 0 20px rgba(0,0,0,0.2);
}
.active {
    color: var(--brand);
}

.slide-left-enter-active,
.slide-left-leave-active {
  transition: transform 0.3s ease;
}

.slide-left-enter-from,
.slide-left-leave-to {
  transform: translateX(100%);
}
</style>
