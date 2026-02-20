<template>
    <div
        class="flex flex-col h-full bg-(--surface-primary)/80 backdrop-blur-xl transition-all duration-500 ease-[cubic-bezier(0.4,0,0.2,1)] border-r border-(--border-default)/50 relative group/sidebar shadow-2xl"
        v-bind="$attrs"
    >
        <!-- Top Section: Account & Identity -->
        <div class="p-4 mb-2">
            <div
                class="relative flex items-center transition-all duration-300"
                :class="store.isSidebarCollapsed ? 'justify-center' : 'gap-3'"
            >
                <Dropdown :items="accountItems" align="start" class="shrink-0">
                    <template #trigger>
                        <button
                            class="relative group/avatar flex items-center justify-center transition-transform hover:scale-105 active:scale-95 cursor-pointer"
                            :title="selectedAccount?.email"
                        >
                            <div
                                class="w-10 h-10 rounded-2xl flex items-center justify-center text-sm font-bold text-white shadow-lg overflow-hidden relative ring-2 ring-indigo-500/20 group-hover/avatar:ring-indigo-500/40 transition-all font-sans"
                                :style="{
                                    background:
                                        'linear-gradient(135deg, #6366f1 0%, #4338ca 100%)',
                                }"
                            >
                                <span class="relative z-10">{{
                                    selectedAccount
                                        ? selectedAccount.email
                                              .charAt(0)
                                              .toUpperCase()
                                        : "?"
                                }}</span>
                                <div
                                    class="absolute inset-0 bg-white/10 opacity-0 group-hover/avatar:opacity-100 transition-opacity"
                                ></div>
                            </div>

                            <!-- Status Indicator -->
                            <div
                                class="absolute -bottom-0.5 -right-0.5 w-3.5 h-3.5 rounded-full border-2 border-(--surface-primary) flex items-center justify-center"
                                :class="
                                    isBackgroundSyncing
                                        ? 'bg-blue-500'
                                        : 'bg-emerald-500'
                                "
                            >
                                <div
                                    v-if="isBackgroundSyncing"
                                    class="w-1.5 h-1.5 bg-white rounded-full animate-ping"
                                ></div>
                            </div>
                        </button>
                    </template>
                </Dropdown>

                <div
                    v-show="!store.isSidebarCollapsed"
                    class="flex flex-col min-w-0 pr-2"
                >
                    <span
                        class="text-sm font-semibold text-(--text-primary) truncate"
                        >{{ selectedAccount?.name || "My Account" }}</span
                    >
                    <span
                        class="text-[10px] text-(--text-muted) truncate opacity-70"
                        >{{ selectedAccount?.email }}</span
                    >
                </div>
            </div>
        </div>

        <!-- Action Section: New Email -->
        <div class="px-3 mb-6">
            <button
                @click="$emit('compose')"
                :disabled="!selectedAccount"
                class="group/compose w-full relative flex items-center justify-center gap-3 p-3 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white shadow-lg shadow-indigo-500/25 transition-all active:scale-[0.97] disabled:opacity-50 disabled:cursor-not-allowed overflow-hidden"
                :title="store.isSidebarCollapsed ? 'New Email' : ''"
            >
                <PencilIcon
                    class="w-5 h-5 shrink-0 transition-transform group-hover/compose:rotate-12"
                />
                <span
                    v-if="!store.isSidebarCollapsed"
                    class="text-sm font-bold tracking-tight"
                    >New Email</span
                >

                <!-- Shine effect -->
                <div
                    class="absolute inset-0 w-1/2 h-full bg-linear-to-r from-transparent via-white/10 to-transparent -translate-x-full group-hover/compose:translate-x-[200%] transition-transform duration-1000"
                ></div>
            </button>
        </div>

        <!-- Scrollable Navigation Area -->
        <nav
            class="flex-1 overflow-y-auto overflow-x-hidden px-3 space-y-6 pb-20"
        >
            <!-- Mailbox Section -->
            <div class="space-y-1">
                <div v-if="!store.isSidebarCollapsed" class="px-2 mb-2">
                    <span
                        class="text-[10px] font-bold text-(--text-muted) uppercase tracking-[0.15em] opacity-50"
                        >Mailbox</span
                    >
                </div>

                <a
                    v-for="folder in systemFolders"
                    :key="folder.id"
                    href="#"
                    @click.prevent="handleFolderClick(folder.id)"
                    :title="store.isSidebarCollapsed ? folder.name : ''"
                    class="group flex items-center h-10 px-2.5 rounded-xl transition-all relative overflow-hidden active:scale-95"
                    :class="[
                        selectedFolderId === folder.id
                            ? 'bg-indigo-500/10 text-indigo-600'
                            : 'text-(--text-secondary) hover:bg-black/5',
                        store.isSidebarCollapsed ? 'justify-center' : '',
                    ]"
                >
                    <!-- Active Indicator Pill -->
                    <div
                        v-if="selectedFolderId === folder.id"
                        class="absolute left-0 w-1.5 h-6 bg-indigo-500 rounded-r-full shadow-[0_0_12px_rgba(99,102,241,0.5)]"
                    ></div>

                    <component
                        :is="folder.icon"
                        class="w-5 h-5 shrink-0 transition-all group-hover:scale-110"
                        :class="[
                            selectedFolderId === folder.id
                                ? 'text-indigo-600'
                                : 'text-(--text-muted) group-hover:text-indigo-500',
                            store.isSidebarCollapsed ? '' : 'mr-3',
                        ]"
                    />

                    <span
                        v-if="!store.isSidebarCollapsed"
                        class="flex-1 text-sm font-medium truncate"
                        >{{ folder.name }}</span
                    >

                    <span
                        v-if="folder.count && !store.isSidebarCollapsed"
                        class="ml-auto text-[10px] font-bold px-1.5 py-0.5 rounded-lg"
                        :class="
                            selectedFolderId === folder.id
                                ? 'bg-indigo-500 text-white'
                                : 'bg-black/5 text-(--text-muted)'
                        "
                    >
                        {{ folder.count }}
                    </span>

                    <!-- Notification dot for collapsed state -->
                    <div
                        v-if="folder.count && store.isSidebarCollapsed"
                        class="absolute top-2 right-2 w-2 h-2 bg-indigo-500 rounded-full border-2 border-(--surface-primary)"
                    ></div>
                </a>
            </div>

            <!-- Folders Section -->
            <div
                v-if="
                    customFolders.length > 0 ||
                    subscribedRemoteFolders.length > 0
                "
                class="space-y-1"
            >
                <div
                    v-if="!store.isSidebarCollapsed"
                    class="px-2 mb-2 flex items-center justify-between"
                >
                    <span
                        class="text-[10px] font-bold text-(--text-muted) uppercase tracking-[0.15em] opacity-50"
                        >Custom</span
                    >
                    <button
                        @click="showCreateFolderModal = true"
                        class="p-1 hover:bg-black/5 rounded-md text-(--text-muted) hover:text-indigo-600 transition-colors"
                    >
                        <PlusIcon class="w-3 h-3" />
                    </button>
                </div>

                <div class="space-y-1">
                    <a
                        v-for="folder in [
                            ...customFolders,
                            ...subscribedRemoteFolders,
                        ]"
                        :key="folder.id"
                        href="#"
                        @click.prevent="handleFolderClick(folder.id)"
                        :title="store.isSidebarCollapsed ? folder.name : ''"
                        class="group flex items-center h-10 px-2.5 rounded-xl text-(--text-secondary) hover:bg-black/5 transition-all overflow-hidden"
                        :class="[
                            selectedFolderId === folder.id
                                ? 'bg-indigo-500/10 text-indigo-600'
                                : '',
                            store.isSidebarCollapsed ? 'justify-center' : '',
                        ]"
                    >
                        <FolderIcon
                            class="w-5 h-5 shrink-0 transition-transform group-hover:scale-110"
                            :class="[
                                store.isSidebarCollapsed ? '' : 'mr-3',
                                selectedFolderId === folder.id
                                    ? 'text-indigo-600'
                                    : 'text-(--text-muted)',
                            ]"
                        />
                        <span
                            v-if="!store.isSidebarCollapsed"
                            class="flex-1 text-sm font-medium truncate capitalize leading-relaxed"
                        >
                            {{ folder.name.toLowerCase() }}
                        </span>
                    </a>
                </div>
            </div>

            <!-- Labels Section -->
            <div class="space-y-3">
                <div
                    v-if="!store.isSidebarCollapsed"
                    class="px-2 mb-1 flex items-center justify-between"
                >
                    <span
                        class="text-[10px] font-bold text-(--text-muted) uppercase tracking-[0.15em] opacity-50"
                        >Labels</span
                    >
                    <button
                        @click="showCreateLabelModal = true"
                        class="p-1 hover:bg-black/5 rounded-md text-(--text-muted) hover:text-indigo-600 transition-colors"
                    >
                        <PlusIcon class="w-3 h-3" />
                    </button>
                </div>

                <div class="space-y-1 transition-all duration-300">
                    <a
                        v-for="label in labels"
                        :key="label.id"
                        href="#"
                        @click.prevent="handleLabelClick(label.id)"
                        :title="store.isSidebarCollapsed ? label.name : ''"
                        class="group flex items-center h-9 px-3 rounded-xl hover:bg-black/5 transition-all text-sm font-medium text-(--text-secondary)"
                        :class="
                            store.isSidebarCollapsed
                                ? 'justify-center px-0'
                                : ''
                        "
                    >
                        <div
                            class="w-2.5 h-2.5 rounded-full ring-2 ring-white shadow-sm shrink-0"
                            :class="[
                                label.color,
                                store.isSidebarCollapsed ? '' : 'mr-4',
                            ]"
                        ></div>
                        <span
                            v-if="!store.isSidebarCollapsed"
                            class="truncate opacity-80 group-hover:opacity-100"
                            >{{ label.name }}</span
                        >
                    </a>
                </div>
            </div>
        </nav>

        <!-- Flat Minimalist Footer -->
        <div class="px-4 py-4 mt-auto">
            <div
                class="flex items-center gap-4"
                :class="
                    store.isSidebarCollapsed
                        ? 'flex-col justify-center'
                        : 'flex-row justify-end'
                "
            >
                <button
                    @click="handleSync"
                    :disabled="
                        isSyncing || isBackgroundSyncing || !selectedAccount
                    "
                    class="flex items-center justify-center w-7 h-7 rounded-lg text-(--text-muted) hover:text-indigo-600 border border-(--border-default)/30 hover:border-indigo-500/50 transition-all disabled:opacity-30 group shrink-0 relative active:scale-95"
                    :title="isBackgroundSyncing ? 'Syncing...' : 'Sync Account'"
                >
                    <RotateCwIcon
                        class="w-3.5 h-3.5 transition-transform duration-700 shrink-0"
                        :class="{
                            'animate-spin': isSyncing || isBackgroundSyncing,
                            'group-hover:rotate-180': !isSyncing,
                        }"
                    />
                </button>

                <button
                    @click="store.toggleSidebar"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-(--text-muted) hover:text-indigo-600 border border-(--border-default)/30 hover:border-indigo-500/50 transition-all group shrink-0 active:scale-95"
                    :title="
                        store.isSidebarCollapsed
                            ? 'Expand Sidebar'
                            : 'Collapse Sidebar'
                    "
                >
                    <ChevronLeftIcon
                        v-if="!store.isSidebarCollapsed"
                        class="w-3.5 h-3.5 group-hover:-translate-x-0.5 transition-transform shrink-0"
                    />
                    <ChevronRightIcon
                        v-else
                        class="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform shrink-0"
                    />
                </button>
            </div>
        </div>
    </div>

    <!-- Create Folder Modal -->
    <CreateFolderModal
        :isOpen="showCreateFolderModal"
        @close="showCreateFolderModal = false"
        @created="handleFolderCreated"
    />

    <!-- Create Label Modal -->
    <CreateLabelModal
        :isOpen="showCreateLabelModal"
        @close="showCreateLabelModal = false"
        @created="handleLabelCreated"
    />
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import {
    ChevronLeftIcon,
    ChevronRightIcon,
    PencilIcon,
    UserIcon,
    PlusIcon,
    SettingsIcon,
    RotateCwIcon,
    FolderIcon,
} from "lucide-vue-next";

defineOptions({
    inheritAttrs: false,
});
import Dropdown, { type DropdownItem } from "@/components/ui/Dropdown.vue";
import CreateFolderModal from "./CreateFolderModal.vue";
import CreateLabelModal from "./CreateLabelModal.vue";
import { useEmailStore } from "@/stores/emailStore";
import { storeToRefs } from "pinia";
import {
    emailAccountService,
    type 
} from "@/services/email-account.service";

import { useRouter } from "vue-router";

const router = useRouter();
const emit = defineEmits(["compose"]);

const store = useEmailStore();
const {
    systemFolders,
    customFolders,
    subscribedRemoteFolders,
    labels,
    selectedFolderId,
    accounts,
    selectedAccount,
    accountStatus,
    
} = storeToRefs(store);

const isBackgroundSyncing = computed(() => {
    return (
        accountStatus.value?.status === "syncing" ||
        accountStatus.value?.status === "seeding"
    );
});

onMounted(() => {
    store.fetchInitialData();
});

const showCreateFolderModal = ref(false);
const showCreateLabelModal = ref(false);

function handleFolderClick(folderId: string) {
    store.selectFolder(folderId);
}

function handleLabelClick(labelId: string) {
    // Treat clicking a label like selecting a folder for filtering
    store.selectFolder(labelId);
}

function handleFolderCreated(folderId: string) {
    store.selectFolder(folderId);
}

function handleLabelCreated(_labelId: string) {
    // Optionally auto-select the new label filter
    // store.selectFolder(labelId);
}

// Dynamic accountItems computed from fetched accounts
const accountItems = computed<DropdownItem[]>(() => {
    const items: DropdownItem[] = (accounts.value || []).map((account) => ({
        label: `${account.name || account.email}`,
        icon: UserIcon,
        action: () => {
            selectAccount(account);
        },
    }));

    // Add static actions
    items.push(
        {
            label: "Add account",
            icon: PlusIcon,
            action: () => router.push("/email/settings?tab=accounts"),
        },
        {
            label: "Settings",
            icon: SettingsIcon,
            action: () => router.push("/email/settings"),
        },
    );

    return items;
});

const isSyncing = ref(false);

async function handleSync() {
    if (!selectedAccount.value || isSyncing.value) return;

    isSyncing.value = true;
    try {
        await emailAccountService.sync(selectedAccount.value.id);
        // Refresh emails after sync trigger (might need polling or reliable socket, but this helps)
        setTimeout(() => {
            store.fetchEmails(1);
        }, 2000);
    } catch (e) {
        console.error("Sync failed", e);
    } finally {
        isSyncing.value = false;
    }
}

function selectAccount(account: any) {
    store.setSelectedAccount(account.id);
}
</script>
