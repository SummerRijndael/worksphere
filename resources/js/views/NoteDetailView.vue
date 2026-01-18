<script setup lang="ts">
import { ref, onMounted, computed, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useToast } from "@/composables/useToast";
import api from "@/lib/api";
import NoteEditor from "@/components/notes/NoteEditor.vue";
import { Button, Card, Badge, Input } from "@/components/ui";
import {
    ArrowLeft,
    Calendar,
    Clock,
    Pin,
    Trash2,
    Save,
    Palette,
} from "lucide-vue-next";

// Types
interface Note {
    id: string; // public_id
    title: string | null;
    content: string | null;
    color: string;
    is_pinned: boolean;
    created_at: string;
    updated_at: string;
}

const route = useRoute();
const router = useRouter();
const toast = useToast();

const noteId = computed(() => route.params.public_id as string);
const isLoading = ref(true);
const isSaving = ref(false);
const note = ref<Note | null>(null);

// Edit State
const editedTitle = ref("");
const editedContent = ref("");
const editedColor = ref("default");

const colors = ["default", "red", "blue", "green", "yellow", "purple"];

onMounted(() => {
    fetchNote();
});

async function fetchNote() {
    try {
        isLoading.value = true;
        const response = await api.get(`/api/notes/${noteId.value}`);
        note.value = response.data.data;

        // Init edit state
        if (note.value) {
            editedTitle.value = note.value.title || "";
            editedContent.value = note.value.content || "";
            editedColor.value = note.value.color || "default";
        }
    } catch (error) {
        console.error("Failed to fetch note:", error);
        toast.error("Failed to load note");
        router.push({ name: "notes" });
    } finally {
        isLoading.value = false;
    }
}

async function saveNote() {
    if (!note.value) return;

    try {
        isSaving.value = true;
        await api.put(`/api/notes/${note.value.id}`, {
            title: editedTitle.value,
            content: editedContent.value,
            color: editedColor.value,
        });

        // Update local ref
        note.value.title = editedTitle.value;
        note.value.content = editedContent.value;
        note.value.color = editedColor.value;
        note.value.updated_at = new Date().toISOString();

        toast.success("Note saved successfully");
    } catch (error) {
        console.error("Failed to save note:", error);
        toast.error("Failed to save changes");
    } finally {
        isSaving.value = false;
    }
}

async function togglePin() {
    if (!note.value) return;

    try {
        const newStatus = !note.value.is_pinned;
        // In Store we normally do this, but here we call API directly for now
        // Assuming API supports PUT /notes/:id matching standard resource
        await api.put(`/api/notes/${note.value.id}`, {
            is_pinned: newStatus,
        });

        note.value.is_pinned = newStatus;
        toast.success(newStatus ? "Note pinned" : "Note unpinned");
    } catch (error) {
        console.error("Failed to toggle pin:", error);
        toast.error("Failed to update pin status");
    }
}

async function deleteNote() {
    if (!confirm("Are you sure you want to delete this note?")) return;
    if (!note.value) return;

    try {
        await api.delete(`/api/notes/${note.value.id}`);
        toast.success("Note deleted");
        router.push({ name: "notes" });
    } catch (error) {
        console.error("Failed to delete note:", error);
        toast.error("Failed to delete note");
    }
}

// Auto-save debounce could be added here preferably, but manual save for now as per "Full Page" robustness
</script>

<template>
    <div
        class="w-full flex flex-col overflow-hidden bg-[var(--background-default)]"
    >
        <!-- Header -->
        <header
            class="h-14 sm:h-16 border-b border-[var(--border-default)] bg-[var(--surface-primary)] flex items-center justify-between px-3 sm:px-6 shrink-0 z-20"
        >
            <div class="flex items-center gap-2 sm:gap-4">
                <Button
                    variant="ghost"
                    size="icon"
                    @click="router.push({ name: 'notes' })"
                    class="hover:bg-[var(--surface-hover)] h-8 w-8 sm:h-10 sm:w-10"
                >
                    <ArrowLeft
                        :size="18"
                        class="text-[var(--text-secondary)]"
                    />
                </Button>
                <div class="flex flex-col min-w-0">
                    <div class="flex items-center gap-2">
                        <h1
                            class="text-sm sm:text-base font-semibold text-[var(--text-primary)] truncate"
                        >
                            Note Details
                        </h1>
                        <Badge
                            v-if="isSaving"
                            variant="secondary"
                            class="h-5 text-[10px] px-1.5 animate-pulse shrink-0"
                            >Saving...</Badge
                        >
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-1 sm:gap-2">
                <!-- Last saved indicator -->
                <span
                    v-if="note"
                    class="text-xs text-[var(--text-tertiary)] mr-2 hidden md:block"
                >
                    Last saved
                    {{
                        new Date(note.updated_at).toLocaleTimeString([], {
                            hour: "2-digit",
                            minute: "2-digit",
                        })
                    }}
                </span>

                <div
                    class="h-6 w-px bg-[var(--border-default)] mx-1 hidden md:block"
                ></div>

                <!-- Actions -->
                <Button
                    variant="ghost"
                    size="icon"
                    :class="
                        note?.is_pinned
                            ? 'text-[var(--interactive-primary)] bg-[var(--surface-active)]'
                            : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                    "
                    @click="togglePin"
                    title="Pin Note"
                    class="h-8 w-8 sm:h-9 sm:w-9"
                >
                    <Pin
                        :size="16"
                        class="transform rotate-45"
                        :class="{ 'fill-current': note?.is_pinned }"
                    />
                </Button>

                <Button
                    variant="ghost"
                    size="icon"
                    class="text-[var(--text-secondary)] hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 h-8 w-8 sm:h-9 sm:w-9"
                    @click="deleteNote"
                    title="Delete Note"
                >
                    <Trash2 :size="16" />
                </Button>

                <Button
                    variant="primary"
                    class="ml-1 sm:ml-2 gap-1 sm:gap-2 shadow-sm shadow-[var(--interactive-primary)]/20 h-8 sm:h-9 px-2.5 sm:px-4 text-sm"
                    :disabled="isSaving"
                    @click="saveNote"
                >
                    <Save :size="14" />
                    <span class="hidden sm:inline">Save</span>
                </Button>
            </div>
        </header>

        <!-- Main Content -->
        <div
            class="flex-1 overflow-hidden flex flex-col lg:flex-row"
            v-if="note"
        >
            <!-- Central Editor Canvas -->
            <div
                class="flex-1 flex flex-col overflow-y-auto bg-[var(--background-secondary)] min-w-0 py-4 sm:py-8 px-3 sm:px-8"
            >
                <div class="w-full flex flex-col gap-4 sm:gap-6 h-full">
                    <!-- Color & Title Section -->
                    <div class="flex flex-col gap-3 sm:gap-4">
                        <div class="flex items-center justify-between">
                            <!-- Color Picker -->
                            <div
                                class="flex items-center gap-1.5 sm:gap-2 p-1 sm:p-1.5 bg-[var(--surface-primary)] rounded-full border border-[var(--border-default)] shadow-sm self-start"
                            >
                                <button
                                    v-for="color in colors"
                                    :key="color"
                                    @click="editedColor = color"
                                    class="w-5 h-5 sm:w-6 sm:h-6 rounded-full border-2 transition-all hover:scale-110 focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                                    :class="[
                                        color === 'default'
                                            ? 'border-[var(--border-default)]'
                                            : 'border-transparent',
                                        editedColor === color
                                            ? 'border-[var(--interactive-primary)] ring-2 ring-[var(--interactive-primary)] ring-offset-1'
                                            : '',
                                    ]"
                                    :style="{
                                        backgroundColor:
                                            color === 'default'
                                                ? 'var(--surface-primary)'
                                                : {
                                                      red: '#ef4444',
                                                      blue: '#3b82f6',
                                                      green: '#22c55e',
                                                      yellow: '#eab308',
                                                      purple: '#a855f7',
                                                  }[color],
                                    }"
                                    :title="color"
                                ></button>
                            </div>
                        </div>

                        <!-- Title Input with animated underline -->
                        <div class="relative group">
                            <input
                                v-model="editedTitle"
                                type="text"
                                placeholder="Note Title"
                                class="text-2xl sm:text-4xl font-bold bg-transparent border-none outline-none text-[var(--text-primary)] placeholder-[var(--text-tertiary)] w-full py-1 sm:py-2 px-1 focus:ring-0"
                            />
                            <div
                                class="absolute bottom-0 left-0 w-full h-px bg-[var(--border-default)] group-focus-within:bg-[var(--interactive-primary)] group-focus-within:h-0.5 transition-all duration-300"
                            ></div>
                        </div>
                    </div>

                    <!-- Editor Surface -->
                    <!-- We use the NoteEditor component now -->
                    <div
                        class="flex-1 bg-[var(--surface-primary)] rounded-xl border border-[var(--border-default)] shadow-sm overflow-hidden min-h-[300px] sm:min-h-[500px] flex flex-col"
                    >
                        <NoteEditor
                            :model-value="editedContent"
                            @update:model-value="
                                (val: string) => (editedContent = val)
                            "
                            class="flex-1"
                            :editable="true"
                            placeholder="Start writing your thoughts..."
                        />
                    </div>
                </div>
            </div>

            <!-- Right Sidebar Details (becomes bottom panel on mobile) -->
            <div
                class="lg:w-72 xl:w-80 border-t lg:border-t-0 lg:border-l border-[var(--border-default)] bg-[var(--surface-primary)] flex flex-col shrink-0 overflow-y-auto"
            >
                <div class="p-4 sm:p-6">
                    <h3
                        class="text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider mb-4 sm:mb-6"
                    >
                        Details
                    </h3>

                    <div
                        class="flex flex-row lg:flex-col gap-4 sm:gap-6 flex-wrap"
                    >
                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-[var(--text-tertiary)]"
                                >Created</span
                            >
                            <div
                                class="flex items-center gap-2 text-sm font-medium text-[var(--text-primary)]"
                            >
                                <Calendar
                                    :size="14"
                                    class="text-[var(--text-secondary)]"
                                />
                                {{
                                    new Date(
                                        note.created_at,
                                    ).toLocaleDateString(undefined, {
                                        dateStyle: "medium",
                                    })
                                }}
                            </div>
                        </div>

                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-[var(--text-tertiary)]"
                                >Last Updated</span
                            >
                            <div
                                class="flex items-center gap-2 text-sm font-medium text-[var(--text-primary)]"
                            >
                                <Clock
                                    :size="14"
                                    class="text-[var(--text-secondary)]"
                                />
                                {{
                                    new Date(note.updated_at).toLocaleString(
                                        undefined,
                                        {
                                            dateStyle: "medium",
                                            timeStyle: "short",
                                        },
                                    )
                                }}
                            </div>
                        </div>

                        <div
                            class="hidden lg:block h-px bg-[var(--border-default)] w-full"
                        ></div>

                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-[var(--text-tertiary)]"
                                >Status</span
                            >
                            <div class="flex items-center gap-2 mt-0.5">
                                <Badge
                                    :variant="
                                        note.is_pinned ? 'primary' : 'outline'
                                    "
                                    class="rounded-md px-2 py-0.5 text-xs"
                                >
                                    <Pin
                                        :size="10"
                                        class="mr-1"
                                        v-if="note.is_pinned"
                                    />
                                    {{ note.is_pinned ? "Pinned" : "Normal" }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loading State -->
        <div v-else class="flex-1 flex items-center justify-center">
            <div
                class="flex flex-col items-center gap-3 text-[var(--text-tertiary)]"
            >
                <div
                    class="w-10 h-10 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin"
                ></div>
                <span class="text-sm font-medium">Loading note...</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Ensure editor takes full height */
:deep(.ProseMirror) {
    min-height: 100%;
}
</style>
