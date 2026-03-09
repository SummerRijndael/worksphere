<script setup lang="ts">
import { Avatar } from "@/components/ui";
import { ChevronDown, ChevronUp } from "lucide-vue-next";
import { useDate } from "@/composables/useDate";

const { formatDateTime } = useDate();

const props = defineProps<{
    comment: {
        id: number;
        name: string;
        content: string;
        created_at: string;
        user_avatar?: string | null;
        user_color?: string | null;
        user_initials?: string | null;
    };
    maxChars?: number;
    showAvatar?: boolean;
}>();

const { showAvatar = true } = props;

const isExpanded = ref(false);
const MAX_CHARS = props.maxChars || 1000;

const shouldTruncate = computed(() => {
    return props.comment.content.length > MAX_CHARS;
});

const displayedContent = computed(() => {
    if (!shouldTruncate.value || isExpanded.value) {
        return props.comment.content;
    }
    return props.comment.content.slice(0, MAX_CHARS) + "...";
});

const toggleExpand = () => {
    isExpanded.value = !isExpanded.value;
};
</script>

<template>
    <div class="flex gap-4 animate-fade-in-up">
        <div v-if="showAvatar" class="flex-shrink-0">
            <Avatar
                :src="comment.user_avatar"
                :fallback="
                    comment.user_initials || comment.name?.charAt(0) || '?'
                "
                :color="comment.user_color"
                size="md"
            />
        </div>
        <div class="flex-grow min-w-0">
            <div
                class="bg-[var(--surface-secondary)] rounded-2xl rounded-tl-none px-5 py-3 overflow-hidden"
            >
                <div class="flex items-center justify-between mb-1 gap-2">
                    <span
                        class="font-bold text-sm text-[var(--text-primary)] truncate"
                        >{{ comment.name }}</span
                    >
                    <span
                        class="text-xs text-[var(--text-tertiary)] whitespace-nowrap flex-shrink-0"
                        >{{ formatDateTime(comment.created_at) }}</span
                    >
                </div>
                <p
                    class="text-sm text-[var(--text-primary)] whitespace-pre-wrap break-words overflow-wrap-anywhere"
                >
                    {{ displayedContent }}
                </p>
                <button
                    v-if="shouldTruncate"
                    @click="toggleExpand"
                    class="mt-2 text-xs font-medium text-[var(--interactive-primary)] hover:underline flex items-center gap-1"
                >
                    <template v-if="!isExpanded">
                        Show more <ChevronDown class="w-3 h-3" />
                    </template>
                    <template v-else>
                        Show less <ChevronUp class="w-3 h-3" />
                    </template>
                </button>
            </div>
        </div>
    </div>
</template>
