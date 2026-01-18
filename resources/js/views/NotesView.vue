```
<script setup lang="ts">
import { ref, onMounted, computed, onUnmounted, watch, nextTick } from "vue";
import { useRouter } from "vue-router";
import { useNoteStore, type Note } from "@/stores/note";
import { useAuthStore } from "@/stores/auth";
import NoteCard from "@/components/notes/NoteCard.vue";
import NoteEditor from "@/components/notes/NoteEditor.vue";
import { Button, Input, Modal } from "@/components/ui";
import { Icon } from "@/components/ui";
import { animate, stagger } from "animejs";

const router = useRouter();
const noteStore = useNoteStore();
const authStore = useAuthStore();

const showModal = ref(false);
const editingNote = ref<Partial<Note>>({});
const isEditing = ref(false);

const searchQuery = ref("");
const viewMode = ref<"grid" | "list">("grid");
const currentPage = ref(1);
const perPage = ref(20);

// Colors for editor
const colors = ["default", "red", "blue", "green", "yellow", "purple"];

onMounted(async () => {
    // Load preference from local storage if exists
    const savedViewMode = localStorage.getItem("notes_view_mode");
    if (savedViewMode === "list" || savedViewMode === "grid") {
        viewMode.value = savedViewMode;
    }

    await fetchNotes();

    // Subscribe to realtime
    if (authStore.user?.public_id && (window as any).Echo) {
        (window as any).Echo.private(
            `personal-notes.${authStore.user.public_id}`,
        )
            .listen("PersonalNoteCreated", (e: any) =>
                noteStore.handleNoteCreated(e),
            )
            .listen("PersonalNoteUpdated", (e: any) =>
                noteStore.handleNoteUpdated(e),
            )
            .listen("PersonalNoteDeleted", (e: any) =>
                noteStore.handleNoteDeleted(e),
            );
    }
});

onUnmounted(() => {
    if (authStore.user?.public_id && (window as any).Echo) {
        (window as any).Echo.leave(
            `personal-notes.${authStore.user.public_id}`,
        );
    }
});

async function fetchNotes() {
    await noteStore.fetchNotes(currentPage.value, perPage.value);
    await nextTick();
    animateEntry();
}

function animateEntry() {
    const targets = document.querySelectorAll(".note-card-wrapper");
    if (targets.length > 0) {
        try {
            animate(targets, {
                opacity: [0, 1],
                translateY: [20, 0],
                delay: stagger(50),
                easing: "easeOutQuad",
            });
        } catch (e) {
            console.warn("Animation failed", e);
            // Fallback to visible
            targets.forEach((el: any) => {
                el.style.opacity = "1";
                el.style.transform = "none";
            });
        }
    }
}

// Watchers
watch([currentPage, perPage], () => {
    fetchNotes();
});

watch(viewMode, (val) => {
    localStorage.setItem("notes_view_mode", val);
    // Re-animate on view change?
    nextTick(() => animateEntry());
});

const filteredNotes = computed(() => {
    let notes = noteStore.sortedNotes;

    // Client-side search filtering (if needed on top of server pagination?
    // Ideally search should be server-side if paginated.
    // For now keeping client-side search on the *current page* notes which is standard for simple lists,
    // OR ideally we implement server search.
    // Implementation plan didn't specify server search, but standard behavior for paginated lists is server search.
    // Given the constraints, I will apply filter to the currently fetched notes.

    if (!searchQuery.value) return notes;
    const lower = searchQuery.value.toLowerCase();

    return notes.filter(
        (n) =>
            n.title?.toLowerCase().includes(lower) ||
            n.content?.toLowerCase().includes(lower),
    );
});

// Computed for Pagination Info
const paginationInfo = computed(() => {
    const meta = noteStore.meta;
    if (!meta || !meta.from) return "No notes";
    return `Showing ${meta.from} to ${meta.to} of ${meta.total}`;
});

function handleNewNote() {
    editingNote.value = { color: "default", title: "", content: "" };
    isEditing.value = false;
    showModal.value = true;
}

function openNote(note: Note) {
    router.push({
        name: "note-detail",
        params: { public_id: note.public_id || note.id },
    });
}

function handleEdit(note: Note) {
    editingNote.value = { ...note };
    isEditing.value = true;
    showModal.value = true;
}

async function handleSave() {
    if (isEditing.value && editingNote.value.public_id) {
        await noteStore.updateNote(
            editingNote.value.public_id,
            editingNote.value,
        );
    } else {
        await noteStore.createNote(editingNote.value);
    }
    showModal.value = false;
}

async function handleDelete(note: Note) {
    if (confirm("Are you sure you want to delete this note?")) {
        await noteStore.deleteNote(note.public_id);
        // If page becomes empty, go back?
        if (filteredNotes.value.length === 0 && currentPage.value > 1) {
            currentPage.value--;
        }
    }
}

async function handlePin(note: Note) {
    await noteStore.updateNote(note.public_id, { is_pinned: !note.is_pinned });
}

// Bulk Actions
const selectedNotes = ref<Set<string>>(new Set());

const isAllSelected = computed(() => {
    return (
        filteredNotes.value.length > 0 &&
        filteredNotes.value.every((n) => selectedNotes.value.has(n.public_id))
    );
});

const isIndeterminate = computed(() => {
    return selectedNotes.value.size > 0 && !isAllSelected.value;
});

function toggleSelection(note: Note) {
    const id = note.public_id;
    if (selectedNotes.value.has(id)) {
        selectedNotes.value.delete(id);
    } else {
        selectedNotes.value.add(id);
    }
}

function toggleSelectAll() {
    if (isAllSelected.value) {
        selectedNotes.value.clear();
    } else {
        filteredNotes.value.forEach((n) =>
            selectedNotes.value.add(n.public_id),
        );
    }
}

function clearSelection() {
    selectedNotes.value.clear();
}

async function bulkDelete() {
    if (
        !confirm(
            `Are you sure you want to delete ${selectedNotes.value.size} notes?`,
        )
    )
        return;

    const ids = Array.from(selectedNotes.value);
    await noteStore.bulkDeleteNotes(ids);

    selectedNotes.value.clear();
    if (filteredNotes.value.length === 0 && currentPage.value > 1) {
        currentPage.value--;
    }
}

async function bulkPin() {
    const ids = Array.from(selectedNotes.value);
    const notes = filteredNotes.value.filter((n) =>
        selectedNotes.value.has(n.public_id),
    );
    const allPinned = notes.every((n) => n.is_pinned);
    const targetState = !allPinned;

    await noteStore.bulkUpdateNotes(ids, { is_pinned: targetState });
    selectedNotes.value.clear();
}
</script>

<template>
    <div class="h-full flex flex-col p-4 sm:p-6 lg:p-8 overflow-hidden">
        <!-- Full Width Container -->
        <div class="w-full flex flex-col h-full">
            <!-- Header -->
            <div class="flex flex-col gap-4 mb-6 shrink-0">
                <!-- Title Row -->
                <div
                    class="flex flex-col md:flex-row md:items-center justify-between gap-4"
                >
                    <div class="min-w-0">
                        <h1
                            class="text-xl sm:text-2xl lg:text-3xl font-bold tracking-tight text-[var(--text-primary)]"
                        >
                            Personal Notes
                        </h1>
                        <p
                            class="text-sm sm:text-base text-[var(--text-secondary)] mt-1"
                        >
                            Manage your personal thoughts and ideas.
                        </p>
                    </div>

                    <!-- Desktop: Search + Button inline -->
                    <div class="flex items-center gap-3">
                        <!-- Search (desktop) -->
                        <div class="relative hidden md:block">
                            <Icon
                                name="Search"
                                class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-tertiary)]"
                                :size="16"
                            />
                            <Input
                                v-model="searchQuery"
                                placeholder="Search notes..."
                                class="pl-9 w-64 lg:w-80"
                            />
                        </div>

                        <!-- New Note Button -->
                        <Button
                            @click="handleNewNote"
                            class="gap-2 shrink-0 shadow-sm"
                        >
                            <Icon name="Plus" :size="18" />
                            <span class="hidden sm:inline">New Note</span>
                        </Button>
                    </div>
                </div>

                <!-- Search Row (mobile only) -->
                <div class="relative w-full md:hidden">
                    <Icon
                        name="Search"
                        class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--text-tertiary)]"
                        :size="16"
                    />
                    <Input
                        v-model="searchQuery"
                        placeholder="Search notes..."
                        class="pl-9 w-full"
                    />
                </div>

                <!-- Controls Row -->
                <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                    <!-- Select All -->
                    <label
                        class="flex items-center gap-2 cursor-pointer group select-none"
                    >
                        <input
                            type="checkbox"
                            :checked="isAllSelected"
                            :indeterminate="isIndeterminate"
                            @change="toggleSelectAll"
                            class="w-4 h-4 rounded border-[var(--border-default)] text-[var(--accent)] focus:ring-offset-0 focus:ring-2 focus:ring-[var(--accent)]/20 cursor-pointer"
                        />
                        <span
                            class="text-sm font-medium text-[var(--text-secondary)] group-hover:text-[var(--text-primary)] transition-colors"
                            >Select All</span
                        >
                    </label>

                    <div class="flex-1"></div>

                    <!-- View Mode -->
                    <div
                        class="hidden sm:flex items-center border rounded-md overflow-hidden border-[var(--border-default)]"
                    >
                        <button
                            @click="viewMode = 'grid'"
                            class="p-2 transition-colors"
                            :class="
                                viewMode === 'grid'
                                    ? 'bg-[var(--surface-active)] text-[var(--text-primary)]'
                                    : 'bg-[var(--surface-primary)] text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                            "
                            title="Grid View"
                        >
                            <Icon name="LayoutGrid" :size="18" />
                        </button>
                        <div class="w-px bg-[var(--border-default)] h-4"></div>
                        <button
                            @click="viewMode = 'list'"
                            class="p-2 transition-colors"
                            :class="
                                viewMode === 'list'
                                    ? 'bg-[var(--surface-active)] text-[var(--text-primary)]'
                                    : 'bg-[var(--surface-primary)] text-[var(--text-secondary)] hover:text-[var(--text-primary)]'
                            "
                            title="List View"
                        >
                            <Icon name="LayoutList" :size="18" />
                        </button>
                    </div>

                    <!-- Refresh -->
                    <Button
                        variant="outline"
                        size="icon"
                        @click="fetchNotes"
                        :disabled="noteStore.isLoading"
                        title="Refresh"
                    >
                        <Icon
                            name="RotateCw"
                            :size="18"
                            :class="{ 'animate-spin': noteStore.isLoading }"
                        />
                    </Button>
                </div>
            </div>

            <!-- Data Table Viewport (with min-height for pagination clamping) -->
            <div class="flex-1 flex flex-col min-h-[calc(100vh-435px)]">
                <!-- Content -->
                <div class="flex-1 overflow-y-auto min-h-0 pr-2 scrollbar-thin">
                    <div
                        v-if="noteStore.isLoading && !noteStore.notes.length"
                        class="flex items-center justify-center h-40"
                    >
                        <Icon
                            name="Loader2"
                            class="animate-spin text-[var(--text-tertiary)]"
                            :size="32"
                        />
                    </div>

                    <div
                        v-else-if="filteredNotes.length === 0"
                        class="flex flex-col items-center justify-center h-64 text-[var(--text-tertiary)] border-2 border-dashed border-[var(--border-secondary)] rounded-xl"
                    >
                        <Icon
                            name="StickyNote"
                            :size="48"
                            class="mb-4 opacity-20"
                        />
                        <p>No notes found.</p>
                    </div>

                    <div v-else>
                        <!-- Bulk Actions Bar -->
                        <div
                            v-if="selectedNotes.size > 0"
                            class="mb-4 p-2 sm:p-3 bg-[var(--surface-secondary)] border border-[var(--border-default)] rounded-lg flex items-center justify-between gap-2"
                        >
                            <span
                                class="text-sm font-medium text-[var(--text-primary)] px-1 sm:px-2"
                                >{{ selectedNotes.size }}
                                <span class="hidden sm:inline"
                                    >selected</span
                                ></span
                            >
                            <div class="flex items-center gap-1 sm:gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="bulkPin"
                                    class="gap-1"
                                >
                                    <Icon name="Pin" :size="16" />
                                    <span class="hidden sm:inline"
                                        >Toggle Pin</span
                                    >
                                </Button>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    @click="bulkDelete"
                                    class="gap-1"
                                >
                                    <Icon name="Trash2" :size="16" />
                                    <span class="hidden sm:inline">Delete</span>
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="clearSelection"
                                >
                                    <Icon name="X" :size="16" />
                                </Button>
                            </div>
                        </div>

                        <!-- Grid View -->
                        <div
                            v-if="viewMode === 'grid'"
                            class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4"
                        >
                            <NoteCard
                                v-for="note in filteredNotes"
                                :key="note.public_id"
                                :note="note"
                                :view-mode="viewMode"
                                :selected="selectedNotes.has(note.public_id)"
                                @edit="handleEdit"
                                @delete="handleDelete"
                                @pin="handlePin"
                                @open-full="openNote"
                                @toggle-select="toggleSelection"
                                class="note-card-wrapper h-[200px]"
                            />
                        </div>

                        <!-- List View -->
                        <div v-else class="flex flex-col gap-2">
                            <div
                                v-for="note in filteredNotes"
                                :key="note.public_id"
                                class="note-card-wrapper"
                            >
                                <NoteCard
                                    :note="note"
                                    :view-mode="'list'"
                                    :selected="
                                        selectedNotes.has(note.public_id)
                                    "
                                    @edit="handleEdit"
                                    @delete="handleDelete"
                                    @pin="handlePin"
                                    @open-full="openNote"
                                    @toggle-select="toggleSelection"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination Footer (clamped to bottom) -->
                <div
                    class="mt-auto pt-3 border-t border-[var(--border-default)] flex items-center justify-between gap-2 shrink-0 flex-wrap"
                >
                    <!-- Left side: Info & Per page -->
                    <div
                        class="flex items-center gap-2 text-xs sm:text-sm text-[var(--text-secondary)]"
                    >
                        <span class="hidden sm:inline">{{
                            paginationInfo
                        }}</span>
                        <span class="sm:hidden"
                            >{{ noteStore.meta?.from || 0 }}-{{
                                noteStore.meta?.to || 0
                            }}/{{ noteStore.meta?.total || 0 }}</span
                        >
                        <span class="text-[var(--border-default)]">•</span>
                        <select
                            v-model="perPage"
                            class="h-7 rounded border border-[var(--border-default)] bg-[var(--surface-primary)] text-xs sm:text-sm text-[var(--text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--interactive-primary)] px-1.5"
                        >
                            <option :value="20">20</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                    </div>

                    <!-- Right side: Navigation -->
                    <div class="flex items-center gap-1">
                        <button
                            :disabled="currentPage <= 1 || noteStore.isLoading"
                            @click="currentPage--"
                            class="h-7 w-7 rounded border border-[var(--border-default)] flex items-center justify-center text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        >
                            <Icon name="ChevronLeft" :size="14" />
                        </button>

                        <!-- Page indicator -->
                        <span
                            class="text-xs sm:text-sm text-[var(--text-secondary)] px-1.5 min-w-[3rem] text-center"
                        >
                            {{ currentPage
                            }}<span class="text-[var(--text-tertiary)]">/</span
                            >{{ noteStore.meta.last_page || 1 }}
                        </span>

                        <button
                            :disabled="
                                currentPage >=
                                    (noteStore.meta.last_page || 1) ||
                                noteStore.isLoading
                            "
                            @click="currentPage++"
                            class="h-7 w-7 rounded border border-[var(--border-default)] flex items-center justify-center text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        >
                            <Icon name="ChevronRight" :size="14" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Editor Modal -->
        <Modal
            v-model:open="showModal"
            :title="isEditing ? 'Edit Note' : 'Create Note'"
            :size="'3xl'"
        >
            <div class="flex flex-col h-[60vh] gap-6">
                <!-- Title Input -->
                <div class="relative group">
                    <input
                        v-model="editingNote.title"
                        placeholder="Note Title"
                        class="text-2xl font-bold bg-transparent border-none focus:outline-none placeholder:text-[var(--text-tertiary)] text-[var(--text-primary)] w-full py-2 px-1 focus:ring-0 transition-all"
                    />
                    <div
                        class="absolute bottom-0 left-0 w-full h-px bg-[var(--border-default)] group-focus-within:bg-[var(--interactive-primary)] transition-colors"
                    ></div>
                </div>

                <!-- Color Picker -->
                <div class="flex flex-col gap-2">
                    <label
                        class="text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider"
                        >Color Tag</label
                    >
                    <div class="flex gap-3">
                        <button
                            v-for="color in colors"
                            :key="color"
                            @click="editingNote.color = color"
                            class="w-8 h-8 rounded-full border-2 transition-all hover:scale-110 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[var(--interactive-primary)]"
                            :class="[
                                color === 'default'
                                    ? 'border-[var(--border-default)] dark:border-zinc-700'
                                    : 'border-transparent',
                                editingNote.color === color
                                    ? 'ring-2 ring-offset-2 ring-[var(--interactive-primary)] scale-110'
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

                <!-- Editor Container -->
                <div class="flex-1 min-h-0 flex flex-col pt-2">
                    <NoteEditor
                        :model-value="editingNote.content || ''"
                        @update:model-value="
                            (val) => (editingNote.content = val)
                        "
                        class="flex-1 h-full"
                        :editable="true"
                    />
                </div>
            </div>

            <template #footer>
                <Button variant="ghost" @click="showModal = false"
                    >Cancel</Button
                >
                <Button @click="handleSave">Save</Button>
            </template>
        </Modal>
    </div>
</template>
