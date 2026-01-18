<script setup lang="ts">
import { ref, computed } from "vue";
import { User, ChevronDown, ChevronUp } from "lucide-vue-next";
import { format } from "date-fns";

const props = defineProps<{
    comment: {
        id: number;
        name: string;
        content: string;
        created_at: string;
        user_avatar?: string | null;
    };
    maxChars?: number;
}>();

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
        <div class="flex-shrink-0">
            <div
                v-if="comment.user_avatar"
                class="w-10 h-10 rounded-full overflow-hidden border border-[var(--border-default)]"
            >
                <img
                    :src="comment.user_avatar"
                    alt="Avatar"
                    class="w-full h-full object-cover"
                />
            </div>
            <div
                v-else
                class="w-10 h-10 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center border border-[var(--border-default)] text-[var(--text-secondary)]"
            >
                <User class="w-5 h-5" />
            </div>
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
                        >{{
                            format(
                                new Date(comment.created_at),
                                "MMM d, yyyy h:mm a"
                            )
                        }}</span
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
