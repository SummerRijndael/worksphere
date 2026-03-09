<script setup lang="ts">
import { computed } from "vue";
import { useDate } from "@/composables/useDate";
import { useAvatar } from "@/composables/useAvatar";
import { Avatar } from "@/components/ui";
const { formatDate } = useDate();
const avatar = useAvatar();
import type { Chat } from "@/types/models/chat";

interface Props {
    chat: Chat;
    isActive?: boolean;
    currentUserPublicId?: string;
}

const props = withDefaults(defineProps<Props>(), {
    isActive: false,
    currentUserPublicId: "",
});

const emit = defineEmits<{
    click: [];
}>();

const chatTitle = computed(() => {
    if (props.chat.name) return props.chat.name;
    if (props.chat.type === "dm" && props.chat.participants.length) {
        const other =
            props.chat.participants.find(
                (p) => p.public_id !== props.currentUserPublicId,
            ) ?? props.chat.participants[0];
        return other?.name || "Chat";
    }
    return "Group Chat";
});

const avatarData = computed(() =>
    avatar.resolveChatAvatar(props.chat, props.currentUserPublicId || null),
);

const isOnline = computed(() => {
    if (props.chat.type === "dm") {
        const other = props.chat.participants.find(
            (p) => p.public_id !== props.currentUserPublicId,
        );
        return other?.is_online;
    }
    return false;
});

const lastMessagePreview = computed(() => {
    const msg = props.chat.last_message;
    if (!msg) return "";
    if (msg.has_media) return "📎 Attachment";
    return msg.content ?? "";
});

const lastMessageTime = computed(() => {
    const msg = props.chat.last_message;
    if (!msg) return "";
    return formatDate(msg.created_at, "smart");
});

const unreadCount = computed(() => props.chat.unread_count ?? 0);

const itemClasses = computed(() => {
    const base =
        "w-full flex items-center gap-3 p-3 rounded-lg transition-colors text-left hover:bg-gray-100 dark:hover:bg-gray-800";
    const active = props.isActive ? "bg-blue-50 dark:bg-blue-900/20" : "";
    return [base, active].filter(Boolean).join(" ");
});
</script>

<template>
    <button type="button" :class="itemClasses" @click="emit('click')">
        <div class="relative shrink-0">
            <Avatar
                :src="avatarData.url"
                :fallback="avatarData.initials"
                :color="avatarData.color"
                size="md"
                class="w-12 h-12"
            />

            <!-- Online indicator for DMs -->
            <span
                v-if="chat.type === 'dm'"
                class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-white dark:border-gray-900"
                :class="isOnline ? 'bg-green-500' : 'bg-gray-400'"
            />
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between gap-2">
                <span
                    class="font-medium text-gray-900 dark:text-white truncate"
                    >{{ chatTitle }}</span
                >
                <span
                    v-if="lastMessageTime"
                    class="text-xs text-gray-500 dark:text-gray-400 shrink-0"
                    >{{ lastMessageTime }}</span
                >
            </div>

            <div class="flex items-center justify-between gap-2 mt-0.5">
                <span
                    class="text-sm text-gray-600 dark:text-gray-400 truncate"
                    :class="{ 'font-semibold': unreadCount > 0 }"
                >
                    {{ lastMessagePreview }}
                </span>
                <span
                    v-if="unreadCount > 0"
                    class="shrink-0 min-w-[20px] h-5 px-1.5 rounded-full bg-blue-600 text-white text-xs font-medium flex items-center justify-center"
                >
                    {{ unreadCount > 99 ? "99+" : unreadCount }}
                </span>
            </div>
        </div>
    </button>
</template>
