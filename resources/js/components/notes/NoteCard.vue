<script setup lang="ts">
import { computed } from "vue";
import { Note } from "@/stores/note";
import { Icon } from "@/components/ui"; // Assuming wrapper for Lucide icons
import DOMPurify from "dompurify";

const props = withDefaults(
    defineProps<{
        note: Note;
        viewMode?: "grid" | "list";
        selected?: boolean;
    }>(),
    {
        viewMode: "grid",
        selected: false,
    },
);

const emit = defineEmits<{
    (e: "edit", note: Note): void;
    (e: "delete", note: Note): void;
    (e: "pin", note: Note): void;
    (e: "open-full", note: Note): void;
    (e: "toggle-select", note: Note): void;
}>();

const sanitizedContent = computed(() => {
    return DOMPurify.sanitize(props.note.content || "");
});

// Map backend colors to Tailwind classes (or CSS variables)
const colorClass = computed(() => {
    switch (props.note.color) {
        case "red":
            return "bg-red-100 dark:bg-red-900/30 border-red-200 dark:border-red-800";
        case "blue":
            return "bg-blue-100 dark:bg-blue-900/30 border-blue-200 dark:border-blue-800";
        case "green":
            return "bg-green-100 dark:bg-green-900/30 border-green-200 dark:border-green-800";
        case "yellow":
            return "bg-yellow-100 dark:bg-yellow-900/30 border-yellow-200 dark:border-yellow-800";
        case "purple":
            return "bg-purple-100 dark:bg-purple-900/30 border-purple-200 dark:border-purple-800";
        default:
            return ""; // Allow default classes to apply
    }
});
</script>

<template>
    <div
        class="note-card group relative rounded-lg border bg-[var(--surface-secondary)] transition-all duration-200 hover:shadow-md"
        :class="[
            colorClass,
            viewMode === 'list'
                ? 'flex items-center p-3 gap-4 min-h-[60px]'
                : 'flex flex-col p-4 gap-2 h-full',
            selected
                ? 'border-[var(--accent)] ring-1 ring-[var(--accent)] shadow-sm z-10'
                : 'border-[var(--border-default)] hover:border-[var(--accent)]/50',
        ]"
    >
        <!-- Selection Checkbox (Absolute for Grid) -->
        <div
            v-if="viewMode === 'grid'"
            class="absolute top-2 left-2 z-10 transition-opacity duration-200"
            :class="
                selected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'
            "
        >
            <input
                type="checkbox"
                :checked="selected"
                @change.stop="emit('toggle-select', note)"
                class="w-4 h-4 rounded border-[var(--border-default)] text-[var(--accent)] focus:ring-offset-0 focus:ring-2 focus:ring-[var(--accent)]/20 cursor-pointer"
            />
        </div>
        <!-- Grid View Content -->
        <template v-if="viewMode === 'grid'">
            <div class="flex justify-between items-start gap-2 w-full">
                <h3
                    class="font-semibold text-[var(--text-primary)] line-clamp-1 flex-1 text-lg"
                >
                    {{ note.title || "Untitled" }}
                </h3>
                <div
                    class="opacity-0 group-hover:opacity-100 transition-opacity flex gap-1"
                >
                    <button
                        @click.stop="emit('open-full', note)"
                        class="p-1.5 rounded hover:bg-black/5 dark:hover:bg-white/10 text-[var(--text-secondary)] transition-colors"
                        title="Open Full Page"
                    >
                        <Icon name="Maximize2" :size="16" />
                    </button>
                    <button
                        @click.stop="emit('pin', note)"
                        class="p-1.5 rounded hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
                        :class="{
                            'text-[var(--interactive-primary)] opacity-100':
                                note.is_pinned,
                            'text-[var(--text-tertiary)]': !note.is_pinned,
                        }"
                    >
                        <Icon
                            name="Pin"
                            :size="16"
                            class="transform rotate-45"
                            :class="{ 'fill-current': note.is_pinned }"
                        />
                    </button>
                    <button
                        @click.stop="emit('edit', note)"
                        class="p-1.5 rounded hover:bg-black/5 dark:hover:bg-white/10 text-[var(--text-secondary)] transition-colors"
                    >
                        <Icon name="Edit2" :size="16" />
                    </button>
                    <button
                        @click.stop="emit('delete', note)"
                        class="p-1.5 rounded hover:bg-red-100 dark:hover:bg-red-900/30 text-red-500 transition-colors"
                    >
                        <Icon name="Trash2" :size="16" />
                    </button>
                </div>
            </div>

            <div
                class="prose prose-sm dark:prose-invert max-w-none text-[var(--text-secondary)] line-clamp-5 overflow-hidden text-ellipsis flex-1"
                v-html="sanitizedContent"
            ></div>

            <div
                class="mt-auto pt-2 text-xs text-[var(--text-tertiary)] flex justify-between w-full"
            >
                <span>{{
                    new Date(note.updated_at).toLocaleDateString()
                }}</span>
            </div>
        </template>

        <!-- List View Content (Structured Columns) -->
        <template v-else>
            <!-- Color Indicator Bar -->
            <div
                class="absolute left-0 top-0 bottom-0 w-1 rounded-l-lg"
                :style="{
                    backgroundColor:
                        note.color === 'default'
                            ? 'transparent'
                            : {
                                  red: '#ef4444',
                                  blue: '#3b82f6',
                                  green: '#22c55e',
                                  yellow: '#eab308',
                                  purple: '#a855f7',
                              }[note.color],
                }"
            ></div>

            <!-- Checkbox Column -->
            <div class="shrink-0 flex items-center pl-2">
                <input
                    type="checkbox"
                    :checked="selected"
                    @change.stop="emit('toggle-select', note)"
                    class="w-4 h-4 rounded border-[var(--border-default)] text-[var(--accent)] focus:ring-offset-0 focus:ring-2 focus:ring-[var(--accent)]/20 cursor-pointer transition-opacity duration-200"
                    :class="
                        selected
                            ? 'opacity-100'
                            : 'opacity-0 group-hover:opacity-100'
                    "
                />
            </div>

            <!-- Pin Indicator -->
            <div v-if="note.is_pinned" class="shrink-0">
                <Icon
                    name="Pin"
                    :size="14"
                    class="text-[var(--interactive-primary)] transform rotate-45 fill-current"
                />
            </div>

            <!-- Title Column -->
            <div class="w-48 lg:w-64 xl:w-80 shrink-0">
                <h3
                    class="font-semibold text-[var(--text-primary)] truncate text-sm lg:text-base"
                >
                    {{ note.title || "Untitled" }}
                </h3>
            </div>

            <!-- Preview Column -->
            <div class="flex-1 min-w-0 hidden sm:block">
                <p class="text-sm text-[var(--text-secondary)] truncate">
                    {{ (note.content || "").replace(/<[^>]*>?/gm, "") }}
                </p>
            </div>

            <!-- Date Column -->
            <div class="w-24 lg:w-28 shrink-0 text-right">
                <span class="text-xs text-[var(--text-tertiary)]">{{
                    new Date(note.updated_at).toLocaleDateString()
                }}</span>
            </div>

            <!-- Actions Column -->
            <div
                class="w-24 lg:w-28 shrink-0 flex justify-end gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity"
            >
                <button
                    @click.stop="emit('open-full', note)"
                    class="p-1.5 rounded hover:bg-black/5 dark:hover:bg-white/10 text-[var(--text-secondary)] transition-colors"
                    title="Open Full Page"
                >
                    <Icon name="Maximize2" :size="15" />
                </button>
                <button
                    @click.stop="emit('pin', note)"
                    class="p-1.5 rounded hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
                    :class="{
                        'text-[var(--interactive-primary)]': note.is_pinned,
                        'text-[var(--text-tertiary)]': !note.is_pinned,
                    }"
                    :title="note.is_pinned ? 'Unpin' : 'Pin'"
                >
                    <Icon
                        name="Pin"
                        :size="15"
                        class="transform rotate-45"
                        :class="{ 'fill-current': note.is_pinned }"
                    />
                </button>
                <button
                    @click.stop="emit('edit', note)"
                    class="p-1.5 rounded hover:bg-black/5 dark:hover:bg-white/10 text-[var(--text-secondary)] transition-colors"
                    title="Edit"
                >
                    <Icon name="Edit2" :size="15" />
                </button>
                <button
                    @click.stop="emit('delete', note)"
                    class="p-1.5 rounded hover:bg-red-100 dark:hover:bg-red-900/30 text-red-500 transition-colors"
                    title="Delete"
                >
                    <Icon name="Trash2" :size="15" />
                </button>
            </div>
        </template>
    </div>
</template>
