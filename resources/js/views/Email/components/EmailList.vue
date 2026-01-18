<template>
    <div
        class="flex flex-col max-h-[calc(100vh-64px)] bg-[var(--surface-primary)] border-r border-[var(--border-default)] w-96 flex-shrink-0"
    >
        <!-- Toolbar Row 1: Actions & Search -->
        <div
            class="flex items-center gap-2 p-2 border-b border-[var(--border-default)] bg-[var(--surface-secondary)]"
        >
            <!-- Left: Checkbox & Bulk Actions -->
            <div class="flex items-center gap-1.5 shrink-0">
                <button
                    @click="$emit('toggle-sidebar')"
                    class="md:hidden p-1.5 text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
                >
                    <MenuIcon class="w-5 h-5" />
                </button>

                <input
                    type="checkbox"
                    :checked="
                        selectedEmailIds.size > 0 &&
                        selectedEmailIds.size === filteredEmails.length
                    "
                    :indeterminate="
                        selectedEmailIds.size > 0 &&
                        selectedEmailIds.size < filteredEmails.length
                    "
                    @change="toggleSelectAll"
                    class="h-4 w-4 text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] border-[var(--border-default)] rounded"
                />

                <div v-if="selectedEmailIds.size > 0" class="flex gap-0.5">
                    <button
                        @click="deleteSelected"
                        class="p-1.5 text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] rounded transition-colors"
                        title="Delete"
                    >
                        <TrashIcon class="w-4 h-4" />
                    </button>
                    <button
                        @click="markSelectedRead(true)"
                        class="p-1.5 text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] rounded transition-colors"
                        title="Mark Read"
                    >
                        <MailOpenIcon class="w-4 h-4" />
                    </button>
                    <button
                        @click="markSelectedRead(false)"
                        class="p-1.5 text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] rounded transition-colors"
                        title="Mark Unread"
                    >
                        <MailIcon class="w-4 h-4" />
                    </button>

                    <MoveToFolderDropdown
                        :emailIds="Array.from(selectedEmailIds)"
                        align="start"
                    />

                    <span
                        class="text-xs text-[var(--text-muted)] self-center ml-1"
                        >{{ selectedEmailIds.size }}</span
                    >
                </div>
            </div>

            <!-- Right: Search -->
            <div class="flex-1 relative min-w-0">
                <SearchIcon
                    class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text-muted)]"
                />
                <input
                    type="text"
                    v-model="searchQuery"
                    placeholder="Search emails..."
                    class="w-full pl-8 pr-3 py-1.5 text-sm border border-[var(--border-default)] rounded-lg bg-[var(--surface-elevated)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/50 focus:border-[var(--interactive-primary)] text-[var(--text-primary)] placeholder-[var(--text-muted)] transition-all"
                />
            </div>
        </div>

        <!-- Toolbar Row 2: Sort & Filters -->
        <div
            class="flex items-center justify-between gap-2 px-2 py-1.5 border-b border-[var(--border-default)] bg-[var(--surface-primary)]"
        >
            <!-- Sort -->
            <Dropdown :items="sortItems" align="start">
                <template #trigger>
                    <button
                        class="flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] transition-colors"
                        title="Sort emails"
                    >
                        <ArrowUpDownIcon class="w-3.5 h-3.5" />
                        {{ sortLabel }}
                    </button>
                </template>
            </Dropdown>

            <!-- Filter Button -->
            <button
                @click="showFilters = !showFilters"
                class="flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-md transition-colors"
                :class="
                    hasActiveFilters
                        ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]'
                        : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)]'
                "
            >
                <FilterIcon class="w-3.5 h-3.5" />
                Filters
                <span
                    v-if="hasActiveFilters"
                    class="w-1.5 h-1.5 rounded-full bg-[var(--interactive-primary)]"
                ></span>
            </button>
        </div>

        <!-- Filter Panel (Collapsible) -->
        <Transition name="slide">
            <div
                v-if="showFilters"
                class="px-3 py-2 border-b border-[var(--border-default)] bg-[var(--surface-secondary)] space-y-2"
            >
                <div class="flex items-center gap-2">
                    <label
                        class="text-xs text-[var(--text-muted)] w-10 shrink-0"
                        >From</label
                    >
                    <input
                        type="date"
                        v-model="filterDateFrom"
                        class="flex-1 px-2 py-1 text-xs border border-[var(--border-default)] rounded bg-[var(--surface-elevated)] text-[var(--text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--interactive-primary)]"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <label
                        class="text-xs text-[var(--text-muted)] w-10 shrink-0"
                        >To</label
                    >
                    <input
                        type="date"
                        v-model="filterDateTo"
                        class="flex-1 px-2 py-1 text-xs border border-[var(--border-default)] rounded bg-[var(--surface-elevated)] text-[var(--text-primary)] focus:outline-none focus:ring-1 focus:ring-[var(--interactive-primary)]"
                    />
                </div>
                <div class="flex justify-end gap-2 pt-1">
                    <button
                        @click="clearFilters"
                        class="px-2 py-1 text-xs text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
                    >
                        Clear
                    </button>
                    <button
                        @click="showFilters = false"
                        class="px-2 py-1 text-xs bg-[var(--interactive-primary)] text-white rounded hover:bg-[var(--interactive-primary-hover)] transition-colors"
                    >
                        Apply
                    </button>
                </div>
            </div>
        </Transition>

        <!-- Health Banners -->
        <div
            v-if="
                accountStatus?.status === 'failed' && !accountStatus.needsReauth
            "
            class="bg-red-50 text-red-700 px-3 py-2 text-xs flex items-center gap-2 border-b border-red-100"
        >
            <AlertOctagonIcon class="w-3 h-3" />
            <span>Sync failed: {{ accountStatus.error }}</span>
        </div>
        <div
            v-else-if="
                accountStatus?.status === 'syncing' ||
                accountStatus?.status === 'seeding'
            "
            class="bg-blue-50 text-blue-700 px-3 py-2 text-xs flex items-center gap-2 border-b border-blue-100"
        >
            <LoaderIcon class="w-3 h-3 animate-spin" />
            <span>Syncing emails...</span>
        </div>

        <!-- Blocking View for Re-auth -->
        <div
            v-if="accountStatus?.needsReauth"
            class="flex-1 flex flex-col items-center justify-center p-6 text-center bg-[var(--surface-primary)]"
        >
            <AlertOctagonIcon class="w-12 h-12 text-red-500 mb-2" />
            <h3 class="font-bold text-slate-900 mb-1">Account Disconnected</h3>
            <p class="text-sm text-slate-500 mb-4">{{ accountStatus.error }}</p>
            <router-link
                to="/email/settings"
                class="px-3 py-1.5 bg-red-600 text-white rounded-md text-sm font-medium hover:bg-red-700"
            >
                Reconnect Account
            </router-link>
        </div>

        <!-- Email List -->
        <div
            v-else
            ref="listRef"
            class="flex-1 overflow-y-auto min-h-0 relative"
        >
            <!-- New Email Toast -->
            <button
                v-if="newEmailCount > 0"
                @click="store.loadNewEmails()"
                class="absolute top-2 left-1/2 -translate-x-1/2 z-10 bg-[var(--interactive-primary)] text-white px-3 py-1.5 rounded-full text-xs font-medium shadow-lg hover:bg-[var(--interactive-primary-hover)] transition flex items-center gap-1 cursor-pointer"
            >
                <ArrowUpDownIcon class="w-3 h-3" />
                {{ newEmailCount }} new email(s)
            </button>
            <!-- Loading Skeleton -->
            <div v-if="loading" class="p-4 space-y-4">
                <div v-for="i in 6" :key="i" class="flex gap-3 animate-pulse">
                    <div
                        class="w-5 h-5 bg-[var(--surface-tertiary)] rounded shrink-0"
                    ></div>
                    <div class="flex-1 space-y-2">
                        <div
                            class="h-4 bg-[var(--surface-tertiary)] rounded w-3/4"
                        ></div>
                        <div
                            class="h-3 bg-[var(--surface-tertiary)] rounded w-1/2"
                        ></div>
                    </div>
                </div>
            </div>

            <ul v-else class="divide-y divide-[var(--border-subtle)]">
                <li
                    v-for="email in sortedEmails"
                    :key="email.id"
                    @click="handleSelect(email)"
                    class="email-item relative flex items-start px-4 py-3 cursor-pointer group transition-colors duration-150"
                    :class="[
                        selectedEmailId === email.id
                            ? 'bg-[var(--surface-tertiary)]'
                            : 'hover:bg-[var(--surface-secondary)]',
                        !email.is_read
                            ? 'bg-[var(--surface-elevated)] font-semibold'
                            : 'bg-[var(--surface-primary)]',
                    ]"
                >
                    <!-- Checkbox -->
                    <div class="flex items-center h-5">
                        <input
                            @click.stop
                            type="checkbox"
                            :checked="selectedEmailIds.has(email.id)"
                            @change="toggleSelection(email.id)"
                            class="h-4 w-4 text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)] border-[var(--border-default)] rounded opacity-0 group-hover:opacity-100 transition-opacity"
                            :class="{
                                'opacity-100': selectedEmailIds.has(email.id),
                            }"
                        />
                    </div>

                    <!-- Star -->
                    <div
                        @click.stop="store.toggleStar(email.id)"
                        class="ml-2 mr-3 pt-0.5 transform active:scale-125 transition-transform"
                    >
                        <StarIcon
                            :class="[
                                email.is_starred
                                    ? 'text-yellow-400 fill-current'
                                    : 'text-[var(--text-muted)] hover:text-yellow-400',
                                'w-4 h-4 transition-colors',
                            ]"
                        />
                    </div>

                    <!-- Content -->
                    <div class="min-w-0 flex-1">
                        <div class="flex justify-between items-baseline mb-1">
                            <p
                                class="text-sm font-medium text-[var(--text-primary)] truncate pr-2"
                            >
                                {{
                                    email.from_name ||
                                    email.from_email ||
                                    "Unknown"
                                }}
                            </p>
                            <span
                                class="text-xs text-[var(--text-muted)] whitespace-nowrap"
                            >
                                {{ formatDate(email.date) }}
                            </span>
                        </div>
                        <p
                            class="text-sm text-[var(--text-secondary)] truncate font-medium"
                        >
                            {{ email.subject }}
                        </p>
                        <div class="flex items-center gap-1 mt-0.5">
                            <PaperclipIcon
                                v-if="email.has_attachments"
                                class="w-3 h-3 text-[var(--text-secondary)] shrink-0"
                            />
                            <p
                                class="text-xs text-[var(--text-tertiary)] line-clamp-2"
                            >
                                {{ email.preview }}
                            </p>
                        </div>
                        <!-- Labels -->
                        <div
                            v-if="email.labels && email.labels.length"
                            class="mt-2 flex flex-wrap gap-1"
                        >
                            <span
                                v-for="label in email.labels"
                                :key="label"
                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--color-info-bg)] text-[var(--color-info-fg)]"
                            >
                                {{ label }}
                            </span>
                        </div>
                    </div>
                </li>

                <!-- Sentinel for Infinite Scroll -->
                <li
                    ref="sentinel"
                    class="p-4 flex justify-center"
                    v-if="!loading && sortedEmails.length > 0"
                >
                    <div
                        v-if="isLoadingMore"
                        class="w-5 h-5 border-2 border-[var(--text-muted)] border-t-[var(--interactive-primary)] rounded-full animate-spin"
                    ></div>
                    <span v-else class="h-1 w-full"></span>
                </li>
            </ul>
        </div>

        <!-- Sticky Bottom Stats Bar -->
        <div
            class="px-3 py-2 border-t border-[var(--border-default)] bg-[var(--surface-secondary)] text-xs text-[var(--text-muted)] flex items-center justify-between shrink-0"
        >
            <span>{{ filteredEmails.length }} emails</span>
            <span v-if="unreadCount > 0">{{ unreadCount }} unread</span>
        </div>
    </div>
</template>

<script setup lang="ts">
import {
    SearchIcon,
    TrashIcon,
    MailOpenIcon,
    MailIcon, // Imported
    MenuIcon,
    ArrowUpDownIcon,
    StarIcon,
    FilterIcon,
    PaperclipIcon,
    AlertOctagonIcon,
    LoaderIcon,
} from "lucide-vue-next";
import { useEmailStore } from "@/stores/emailStore";
import { storeToRefs } from "pinia";
import { isToday, format } from "date-fns";
import {
    ref,
    computed,
    onMounted,
    watch,
    nextTick,
    onBeforeUnmount,
} from "vue";
import { animate, stagger } from "animejs";
import Dropdown from "@/components/ui/Dropdown.vue";
import MoveToFolderDropdown from "./MoveToFolderDropdown.vue";
import { debounce } from "lodash";

type SortField = "date" | "sender" | "subject";
type SortOrder = "asc" | "desc";

const emit = defineEmits(["toggle-sidebar", "select"]);

const store = useEmailStore();
const {
    filteredEmails,
    selectedEmailId,
    selectedEmailIds,
    loading,
    searchQuery,
    filterDateFrom,
    filterDateTo,
    hasActiveFilters,
    isLoadingMore,
    newEmailCount,
    accountStatus,
} = storeToRefs(store);

const listRef = ref<HTMLElement | null>(null);
let animation: any = null;

const sortField = ref<SortField>("date");
const sortOrder = ref<SortOrder>("desc");
const showFilters = ref(false);

// Filter Watcher
const debouncedFilter = debounce(() => {
    store.applyFilters();
}, 500);

watch([searchQuery, filterDateFrom, filterDateTo], () => {
    debouncedFilter();
});

const sortedEmails = computed(() => {
    // Take the filtered emails from store and apply sorting
    let result = [...filteredEmails.value];

    return result.sort((a, b) => {
        let comparison = 0;
        switch (sortField.value) {
            case "date":
                comparison =
                    new Date(a.date).getTime() - new Date(b.date).getTime();
                break;
            case "sender":
                comparison = a.from_name.localeCompare(b.from_name);
                break;
            case "subject":
                comparison = a.subject.localeCompare(b.subject);
                break;
        }
        return sortOrder.value === "asc" ? comparison : -comparison;
    });
});

const sortLabel = computed(() => {
    const fieldMap = { date: "Date", sender: "Sender", subject: "Subject" };
    const orderArrow = sortOrder.value === "asc" ? "↑" : "↓";
    return `${fieldMap[sortField.value]} ${orderArrow}`;
});

const unreadCount = computed(
    () => filteredEmails.value.filter((e) => !e.is_read).length
);

const sortItems = [
    { label: "Date", action: () => toggleSort("date") },
    { label: "Sender", action: () => toggleSort("sender") },
    { label: "Subject", action: () => toggleSort("subject") },
];

function toggleSort(field: SortField) {
    if (sortField.value === field) {
        sortOrder.value = sortOrder.value === "asc" ? "desc" : "asc";
    } else {
        sortField.value = field;
        sortOrder.value = "desc"; // Default to desc for new field
    }
}

function clearFilters() {
    store.filterDateFrom = "";
    store.filterDateTo = "";
}

function formatDate(dateString: string) {
    if (!dateString) return "";
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return "Invalid Date";
    return isToday(date) ? format(date, "h:mm a") : format(date, "MMM d");
}

function toggleSelectAll() {
    if (
        selectedEmailIds.value.size === filteredEmails.value.length &&
        filteredEmails.value.length > 0
    ) {
        selectedEmailIds.value.clear();
    } else {
        filteredEmails.value.forEach((e) => selectedEmailIds.value.add(e.id));
    }
}

function toggleSelection(id: string) {
    if (selectedEmailIds.value.has(id)) {
        selectedEmailIds.value.delete(id);
    } else {
        selectedEmailIds.value.add(id);
    }
}

function deleteSelected() {
    if (confirm(`Delete ${selectedEmailIds.value.size} emails?`)) {
        store.deleteEmails(Array.from(selectedEmailIds.value));
    }
}

function markSelectedRead(isRead: boolean = true) {
    store.markEmailsAsRead(Array.from(selectedEmailIds.value), isRead);
}

function handleSelect(email: any) {
    store.selectedEmailId = email.id;
    // Mark as read immediately for UI feedback AND persist
    if (!email.is_read) {
        email.is_read = true;
        store.markAsRead(email.id, true);
    }
    emit("select", email);
}

function animateList() {
    if (!listRef.value) return;

    // Stop any previous animation
    if (animation) animation.pause();

    const targets = listRef.value.querySelectorAll(".email-item");
    if (targets.length === 0) return;

    animation = animate(targets, {
        opacity: [0, 1],
        translateY: [10, 0],
        duration: 300,
        delay: stagger(30),
        easing: "easeOutQuad",
    });
}

// Watchers
watch(sortedEmails, () => {
    // Only animate on mount or filtered list changes
    // Debounce a bit or allow Vue to render first
    nextTick(() => animateList());
});

watch(loading, (newVal) => {
    if (!newVal) {
        nextTick(() => animateList());
    }
});

const sentinel = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | null = null;

function setupObserver() {
    if (observer) observer.disconnect();

    observer = new IntersectionObserver(
        (entries) => {
            const entry = entries[0];
            if (entry.isIntersecting) {
                store.loadMore();
            }
        },
        {
            root: listRef.value,
            threshold: 0.1,
            rootMargin: "100px",
        }
    );

    if (sentinel.value) {
        observer.observe(sentinel.value);
    }
}

watch(sentinel, (el) => {
    if (el) setupObserver();
});

onMounted(() => {
    store.fetchEmails();
    animateList();
});

onBeforeUnmount(() => {
    if (animation) animation.pause();
});
</script>

<style scoped>
.slide-enter-active,
.slide-leave-active {
    transition: all 0.2s ease;
    overflow: hidden;
}
.slide-enter-from,
.slide-leave-to {
    opacity: 0;
    max-height: 0;
    padding-top: 0;
    padding-bottom: 0;
}
.slide-enter-to,
.slide-leave-from {
    max-height: 100px;
}
</style>
