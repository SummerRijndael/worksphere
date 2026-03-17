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
import { useVideoCallStore } from "@/stores/videocall";
import { useVideoCall } from "@/composables/useVideoCall";

interface Props {
    chat: Chat;
    headerTitle: string;
    typingIndicator: string | null;
    isMobile: boolean;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    toggleDrawer: [];
    toggleSidebar: [];
    toggleSearch: [];
    startVideoCall: [];
    startVoiceCall: [];
}>();

const authStore = useAuthStore();
const themeStore = useThemeStore();
const videoCallStore = useVideoCallStore();
const avatar = useAvatar();
const { presenceUsers } = usePresence({ manageLifecycle: false });
const videoCall = useVideoCall();

const activeCall = computed(() => {
    return videoCallStore.activeCalls.get(props.chat.public_id);
});

function joinActiveCall() {
    if (activeCall.value) {
        videoCall.joinActiveCall(
            props.chat.public_id,
            activeCall.value.callId,
            activeCall.value.callType,
        );
    }
}

const chatAvatarData = computed(() => {
    return avatar.resolveChatAvatar(props.chat, authStore.user?.public_id);
});

const showThemeMenu = ref(false);

const themes = [
    { id: "modern", label: "Modern", color: "#6366f1" }, // Indigo/Default
    { id: "ocean", label: "Ocean", color: "#0ea5e9" }, // Sky/Blue
    { id: "nature", label: "Nature", color: "#10b981" }, // Emerald/Green
] as const;

function setTheme(theme: "modern" | "ocean" | "nature") {
    themeStore.setChatTheme(theme);
    showThemeMenu.value = false;
}

// Close menu on click outside
function handleClickOutside(e: MouseEvent) {
    if (showThemeMenu.value && !(e.target as Element).closest(".relative")) {
        showThemeMenu.value = false;
    }
}

onMounted(() => {
    document.addEventListener("click", handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener("click", handleClickOutside);
});

const otherParticipant = computed(() => {
    if (props.chat.type !== "dm") return null;
    return (
        props.chat.participants?.find(
            (p) => p.public_id !== authStore.user?.public_id,
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
            if (typingDotsRef.value && typingDotsRef.value.children) {
                animate(typingDotsRef.value.children, {
                    translateY: [0, -3, 0],
                    opacity: [0.5, 1, 0.5],
                    easing: "easeInOutSine",
                    duration: 600,
                    delay: stagger(150),
                    loop: true,
                });
            }
        }
    },
);
</script>

<template>
    <header
        class="flex items-center justify-between gap-3 p-3 lg:p-4 border-b border-(--border-default) bg-(--surface-primary)/85 backdrop-blur-md sticky top-0 z-10"
    >
        <!-- Left: Avatar & Name -->
        <div class="flex items-center gap-3 min-w-0">
            <!-- Mobile menu button -->
            <button
                v-if="isMobile"
                class="shrink-0 p-2 -ml-2 rounded-lg hover:bg-(--surface-tertiary) text-(--text-primary) lg:hidden"
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
                    <h1
                        class="text-sm text-(--text-primary) font-medium truncate"
                    >
                        {{ headerTitle }}
                    </h1>
                    <span
                        v-if="chat.type === 'group'"
                        class="shrink-0 px-1.5 py-0.5 text-[10px] font-medium rounded-full bg-(--surface-tertiary) text-(--text-secondary)"
                    >
                        GROUP
                    </span>
                </div>
                <div class="text-xs text-(--text-secondary) truncate">
                    <div v-if="typingIndicator" class="flex items-center gap-2">
                        <span class="text-(--interactive-primary) truncate">{{
                            typingIndicator
                        }}</span>
                        <div class="flex space-x-0.5" ref="typingDotsRef">
                            <div
                                class="w-1 h-1 bg-(--interactive-primary) rounded-full"
                            ></div>
                            <div
                                class="w-1 h-1 bg-(--interactive-primary) rounded-full"
                            ></div>
                            <div
                                class="w-1 h-1 bg-(--interactive-primary) rounded-full"
                            ></div>
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
                                :class="
                                    getStatusColor(userPresence.status as any)
                                "
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
                                class="text-(--text-tertiary)"
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
            <!-- Call Buttons (DM only) -->
            <template v-if="chat.type === 'dm'">
                <button
                    class="p-2 rounded-lg hover:bg-(--surface-tertiary) text-(--text-primary) transition-colors"
                    title="Voice Call"
                    @click="emit('startVoiceCall')"
                >
                    <Icon name="Phone" size="18" />
                </button>
                <button
                    class="p-2 rounded-lg hover:bg-(--surface-tertiary) text-(--text-primary) transition-colors"
                    title="Video Call"
                    @click="emit('startVideoCall')"
                >
                    <Icon name="Video" size="18" />
                </button>
            </template>
            <template v-else-if="chat.type === 'group'">
                <!-- Join Button if call active -->
                <button
                    v-if="activeCall"
                    class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-green-500 hover:bg-green-600 text-white transition-all shadow-sm transform hover:scale-105"
                    title="Join Call"
                    @click="joinActiveCall"
                >
                    <Icon name="PhoneForwarded" size="16" />
                    <span class="text-xs font-semibold uppercase tracking-wide"
                        >Join</span
                    >
                </button>
                <button
                    v-else
                    class="p-2 rounded-lg hover:bg-(--surface-tertiary) text-(--text-primary) transition-colors"
                    title="Start Video Call"
                    @click="emit('startVideoCall')"
                >
                    <Icon name="Video" size="18" />
                </button>
            </template>

            <!-- Join Call Button for DM if current user is not in call but it is active -->
            <button
                v-if="chat.type === 'dm' && activeCall"
                class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-green-500 hover:bg-green-600 text-white transition-all shadow-sm transform hover:scale-105"
                title="Join Call"
                @click="joinActiveCall"
            >
                <Icon name="PhoneForwarded" size="16" />
                <span class="text-xs font-semibold uppercase tracking-wide"
                    >Join</span
                >
            </button>

            <!-- Theme Switcher -->
            <div class="relative">
                <button
                    class="p-2 rounded-lg hover:bg-(--surface-tertiary) text-(--text-primary)"
                    title="Change Theme"
                    @click="showThemeMenu = !showThemeMenu"
                >
                    <Icon name="Palette" size="18" />
                </button>

                <!-- Theme Menu -->
                <div
                    v-if="showThemeMenu"
                    class="absolute right-0 top-full mt-2 w-48 bg-(--surface-elevated) border border-(--border-default) rounded-xl shadow-lg z-50 overflow-hidden py-1"
                >
                    <button
                        v-for="theme in themes"
                        :key="theme.id"
                        class="w-full px-4 py-2 text-left text-sm hover:bg-(--surface-tertiary) flex items-center gap-2"
                        :class="{
                            'text-(--interactive-primary) font-medium':
                                themeStore.chatTheme === theme.id,
                        }"
                        @click="setTheme(theme.id)"
                    >
                        <div
                            class="w-3 h-3 rounded-full"
                            :style="{ backgroundColor: theme.color }"
                        ></div>
                        {{ theme.label }}
                    </button>
                </div>
            </div>

            <!-- Search (placeholder) -->
            <button
                class="p-2 rounded-lg hover:bg-(--surface-tertiary) text-(--text-primary)"
                title="Search messages"
                @click="emit('toggleSearch')"
            >
                <Icon name="Search" size="18" />
            </button>

            <!-- Info Drawer Toggle -->
            <button
                class="p-2 rounded-lg hover:bg-(--surface-tertiary) text-(--text-primary)"
                title="Chat details"
                @click="emit('toggleDrawer')"
            >
                <Icon name="PanelRightOpen" size="18" />
            </button>
        </div>
    </header>
</template>
