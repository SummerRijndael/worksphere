<script setup lang="ts">
import { computed } from "vue";
import type { Chat } from "@/types/models/chat";
import { useAuthStore } from "@/stores/auth";
import { useThemeStore } from "@/stores/theme";
import {
    usePresence,
    getStatusColor,
    getStatusLabel,
} from "@/composables/usePresence";
import { animate, stagger } from "animejs";
import { ref, watch, nextTick, onMounted, onUnmounted } from "vue";
import { Icon, Avatar } from "@/components/ui";
import { useAvatar } from "@/composables/useAvatar";

interface Props {
    chat: Chat;
    headerTitle: string;
    typingIndicator: string | null;
    connectionState: "connected" | "connecting" | "disconnected";
    subscribedCount: number;
    isMobile: boolean;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    toggleDrawer: [];
    toggleSidebar: [];
    toggleSearch: [];
}>();

const authStore = useAuthStore();
const themeStore = useThemeStore();
const { presenceUsers } = usePresence({ manageLifecycle: false });
const avatar = useAvatar();

const chatAvatarData = computed(() => {
    return avatar.resolveChatAvatar(props.chat, authStore.user?.public_id);
});

const showThemeMenu = ref(false);

const themes = [
    { id: 'modern', label: 'Modern', color: '#6366f1' }, // Indigo/Default
    { id: 'ocean', label: 'Ocean', color: '#0ea5e9' },  // Sky/Blue
    { id: 'nature', label: 'Nature', color: '#10b981' }, // Emerald/Green
] as const;

function setTheme(theme: 'modern' | 'ocean' | 'nature') {
    themeStore.setChatTheme(theme);
    showThemeMenu.value = false;
}

// Close menu on click outside
function handleClickOutside(e: MouseEvent) {
    if (showThemeMenu.value && !(e.target as Element).closest('.relative')) {
        showThemeMenu.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});

const otherParticipant = computed(() => {
    if (props.chat.type !== "dm") return null;
    return (
        props.chat.participants?.find(
            (p) => p.public_id !== authStore.user?.public_id
        ) || null
    );
});

const userPresence = computed(() => {
    if (!otherParticipant.value) return null;
    // Check if user is in presence map
    const presence = presenceUsers.value.get(otherParticipant.value.public_id);
    if (presence) return presence;

    // Fallback to participant data if available (though presence map is source of truth for online)
    return {
        status: (otherParticipant.value as any).is_online
            ? "online"
            : "offline",
        name: otherParticipant.value.name,
    };
});

const participantCount = computed(() => props.chat.participants?.length || 0);

const onlineParticipantCount = computed(() => {
    if (props.chat.type !== "group" || !props.chat.participants) return 0;

    return props.chat.participants.filter((p) => {
        // Check presence map first
        const presence = presenceUsers.value.get(p.public_id);
        if (presence) {
            return presence.status !== "offline";
        }
        // Fallback
        return (p as any).is_online;
    }).length;
});

const typingDotsRef = ref<HTMLElement | null>(null);

watch(
    () => props.typingIndicator,
    async (newVal) => {
        if (newVal) {
            await nextTick();
            if (typingDotsRef.value) {
                animate({
                    targets: typingDotsRef.value.children,
                    translateY: [0, -3, 0],
                    opacity: [0.5, 1, 0.5],
                    easing: "easeInOutSine",
                    duration: 600,
                    delay: stagger(150),
                    loop: true,
                });
            }
        }
    }
);
</script>

<template>
    <header
        class="flex items-center justify-between gap-3 p-3 lg:p-4 border-b border-[var(--border-default)] bg-[var(--surface-elevated)]"
    >
        <!-- Left: Avatar & Name -->
        <div class="flex items-center gap-3 min-w-0">
            <!-- Mobile menu button -->
            <button
                v-if="isMobile"
                class="shrink-0 p-2 -ml-2 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-primary)] lg:hidden"
                @click="emit('toggleSidebar')"
            >
                <Icon name="Menu" size="20" />
            </button>

            <!-- Avatar -->
            <div class="relative shrink-0">
                <Avatar
                    :src="chatAvatarData.url"
                    :alt="headerTitle"
                    :fallback="chatAvatarData.initials"
                    size="md"
                    class="rounded-xl"
                />
            </div>

            <!-- Name & Status -->
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h1 class="text-sm text-[var(--text-primary)] font-medium truncate">
                        {{ headerTitle }}
                    </h1>
                    <span
                        v-if="chat.type === 'group'"
                        class="shrink-0 px-1.5 py-0.5 text-[10px] font-medium rounded-full bg-[var(--surface-tertiary)] text-[var(--text-secondary)]"
                    >
                        GROUP
                    </span>
                </div>
                <div class="text-xs text-[var(--text-secondary)] truncate">
                    <div v-if="typingIndicator" class="flex items-center gap-2">
                        <span class="text-[var(--interactive-primary)] truncate">{{
                            typingIndicator
                        }}</span>
                        <div class="flex space-x-0.5" ref="typingDotsRef">
                            <div class="w-1 h-1 bg-[var(--interactive-primary)] rounded-full"></div>
                            <div class="w-1 h-1 bg-[var(--interactive-primary)] rounded-full"></div>
                            <div class="w-1 h-1 bg-[var(--interactive-primary)] rounded-full"></div>
                        </div>
                    </div>
                    <template v-else>
                        <!-- DM Presence Status -->
                        <div
                            v-if="chat.type === 'dm' && userPresence"
                            class="flex items-center gap-1.5"
                        >
                            <span
                                class="w-1.5 h-1.5 lg:w-2 lg:h-2 rounded-full"
                                :class="getStatusColor(userPresence.status as any)"
                            />
                            <span>{{
                                getStatusLabel(userPresence.status as any)
                            }}</span>
                        </div>
                        <!-- Group Member Count -->
                        <span v-else>
                            {{ participantCount }} member{{
                                participantCount !== 1 ? "s" : ""
                            }}
                            <span
                                v-if="onlineParticipantCount > 0"
                                class="text-[var(--text-tertiary)]"
                            >
                                •
                                <span class="text-green-500 font-medium"
                                    >{{ onlineParticipantCount }} online</span
                                >
                            </span>
                        </span>
                    </template>
                </div>
            </div>
        </div>

        <!-- Right: Actions -->
        <div class="flex items-center gap-1 lg:gap-2 shrink-0">
            <!-- Theme Switcher -->
            <div class="relative">
                <button
                    class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-primary)]"
                    title="Change Theme"
                    @click="showThemeMenu = !showThemeMenu"
                >
                    <Icon name="Palette" size="18" />
                </button>
                
                <!-- Theme Menu -->
                <div 
                    v-if="showThemeMenu"
                    class="absolute right-0 top-full mt-2 w-48 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl shadow-lg z-50 overflow-hidden py-1"
                >
                    <button 
                        v-for="theme in themes" 
                        :key="theme.id"
                        class="w-full px-4 py-2 text-left text-sm hover:bg-[var(--surface-tertiary)] flex items-center gap-2"
                        :class="{'text-[var(--interactive-primary)] font-medium': themeStore.chatTheme === theme.id}"
                        @click="setTheme(theme.id)"
                    >
                        <div class="w-3 h-3 rounded-full" :style="{ backgroundColor: theme.color }"></div>
                        {{ theme.label }}
                    </button>
                </div>
            </div>

            <!-- Connection Indicator -->
            <div
                class="flex items-center gap-1.5 px-2 py-1 lg:px-2.5 lg:py-1 rounded-full text-[10px] lg:text-[11px] font-medium transition-colors duration-300"
                :class="{
                    'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400':
                        connectionState === 'connected',
                    'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400':
                        connectionState === 'connecting',
                    'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400':
                        connectionState === 'disconnected',
                }"
                :title="`Subscribed: ${subscribedCount} channels`"
            >
                <span
                    class="w-1.5 h-1.5 lg:w-2 lg:h-2 rounded-full"
                    :class="{
                        'bg-green-500 shadow-[0_0_6px_theme(colors.green.500)]':
                            connectionState === 'connected',
                        'bg-yellow-500 animate-pulse':
                            connectionState === 'connecting',
                        'bg-red-500': connectionState === 'disconnected',
                    }"
                />
                <span :class="{ 'hidden sm:inline': true }">
                    {{
                        connectionState === "connected"
                            ? "Live"
                            : connectionState === "connecting"
                            ? "Connecting..."
                            : "Offline"
                    }}
                </span>
            </div>

            <!-- Search (placeholder) -->
            <button
                class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-primary)]"
                title="Search messages"
                @click="emit('toggleSearch')"
            >
                <Icon name="Search" size="18" />
            </button>

            <!-- Info Drawer Toggle -->
            <button
                class="p-2 rounded-lg hover:bg-[var(--surface-tertiary)] text-[var(--text-primary)]"
                title="Chat details"
                @click="emit('toggleDrawer')"
            >
                <Icon name="PanelRightOpen" size="18" />
            </button>
        </div>
    </header>
</template>
