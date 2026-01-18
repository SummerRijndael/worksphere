<template>
    <div
        class="w-64 flex-shrink-0 bg-gradient-to-b from-[var(--surface-secondary)] to-[var(--surface-primary)] border-r border-[var(--border-default)] flex flex-col max-h-[calc(100dvh-4rem)]"
        v-bind="$attrs"
    >
        <!-- Accounts Selector (Dropdown) & Sync -->
        <div class="px-3 py-3 border-b border-[var(--border-default)] flex items-center gap-2">
            <Dropdown :items="accountItems" align="start" class="flex-1 min-w-0">
                <template #trigger>
                    <button
                        class="flex items-center w-full px-2.5 py-2 text-sm font-medium text-left bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[var(--interactive-primary)] hover:bg-[var(--surface-tertiary)] transition-all hover:border-[var(--interactive-primary)]/30"
                    >
                        <div class="w-6 h-6 rounded-full mr-2 ring-1 ring-white/20 flex items-center justify-center text-[10px] font-bold text-white shrink-0" :style="{ background: selectedAccount ? '#6366f1' : '#9ca3af' }">
                            {{ selectedAccount ? selectedAccount.email.charAt(0).toUpperCase() : '?' }}
                        </div>
                        <span class="flex-1 truncate text-[var(--text-primary)] leading-tight">{{ selectedAccount?.email || 'No account' }}</span>
                        <ChevronDownIcon class="w-3.5 h-3.5 text-[var(--text-muted)] shrink-0 ml-1.5" />
                    </button>
                </template>
            </Dropdown>
        </div>

        <!-- Compose Button -->
        <div class="p-4 pt-3 flex items-center gap-2">
                      <button 
                @click="handleSync"
                :disabled="isSyncing || !selectedAccount"
                class="flex items-center justify-center w-8 h-8 flex-shrink-0 rounded-lg bg-[var(--surface-elevated)] border border-[var(--border-default)] text-[var(--text-secondary)] hover:text-[var(--interactive-primary)] hover:border-[var(--interactive-primary)]/30 hover:bg-[var(--surface-tertiary)] transition-all disabled:opacity-50 disabled:cursor-not-allowed group"
                title="Sync Account"
            >
                <RotateCwIcon class="w-4 h-4 group-hover:rotate-180 transition-transform duration-500" :class="{ 'animate-spin': isSyncing }" />
            </button>

            <button
                @click="$emit('compose')"
                class="w-full flex items-center justify-center gap-2 px-4 py-3 text-sm font-semibold rounded-xl text-white bg-gradient-to-r from-[var(--interactive-primary)] to-indigo-600 hover:from-[var(--interactive-primary-hover)] hover:to-indigo-700 shadow-lg shadow-[var(--interactive-primary)]/30 transition-all hover:scale-[1.02] active:scale-[0.98] cursor-pointer select-none"
            >
                <PencilIcon class="w-4 h-4" />
                New Email
            </button>
        </div>

        <!-- Navigation -->
        <nav class="flex-1 overflow-y-auto px-3 space-y-1">
            <!-- System Folders -->
            <a
                v-for="folder in systemFolders"
                :key="folder.id"
                href="#"
                @click.prevent="handleFolderClick(folder.id)"
                :class="[
                    selectedFolderId === folder.id
                        ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)] border-l-2 border-[var(--interactive-primary)]'
                        : 'text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] border-l-2 border-transparent',
                    'group flex items-center px-3 py-2.5 text-sm font-medium rounded-r-lg transition-all',
                ]"
            >
                <component
                    :is="folder.icon"
                    :class="[
                        selectedFolderId === folder.id
                            ? 'text-[var(--interactive-primary)]'
                            : 'text-[var(--text-muted)] group-hover:text-[var(--text-secondary)]',
                        'mr-3 flex-shrink-0 h-5 w-5 transition-colors',
                    ]"
                />
                {{ folder.name }}
                <span
                    v-if="folder.count"
                    :class="[
                        selectedFolderId === folder.id
                            ? 'bg-[var(--interactive-primary)] text-white'
                            : 'bg-[var(--surface-tertiary)] text-[var(--text-secondary)]',
                        'ml-auto py-0.5 px-2 rounded-full text-xs font-semibold transition-colors',
                    ]"
                >
                    {{ folder.count }}
                </span>
            </a>

            <!-- Custom Folders -->
            <template v-if="customFolders.length > 0">
                <div class="pt-2">
                    <a
                        v-for="folder in customFolders"
                        :key="folder.id"
                        href="#"
                        @click.prevent="handleFolderClick(folder.id)"
                        :class="[
                            selectedFolderId === folder.id
                                ? 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)] border-l-2 border-[var(--interactive-primary)]'
                                : 'text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)] border-l-2 border-transparent',
                            'group flex items-center px-3 py-2.5 text-sm font-medium rounded-r-lg transition-all',
                        ]"
                    >
                        <component
                            :is="folder.icon"
                            class="mr-3 flex-shrink-0 h-5 w-5 text-[var(--text-muted)]"
                        />
                        {{ folder.name }}
                    </a>
                </div>
            </template>

            <!-- New Folder Button -->
            <button
                @click="showCreateFolderModal = true"
                class="w-full flex items-center gap-2 px-3 py-2 text-sm text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] rounded-lg transition-colors"
            >
                <PlusIcon class="w-4 h-4" />
                New Folder
            </button>

            <!-- Labels Section -->
            <div class="mt-6 pt-4 border-t border-[var(--border-default)]">
                <div class="flex items-center justify-between px-3 mb-2">
                    <h3 class="text-xs font-semibold text-[var(--text-tertiary)] uppercase tracking-wider">
                        Labels
                    </h3>
                    <button
                        @click="showCreateLabelModal = true"
                        class="p-1 rounded hover:bg-[var(--surface-tertiary)] text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
                        title="Create label"
                    >
                        <PlusIcon class="w-3.5 h-3.5" />
                    </button>
                </div>
                <div class="space-y-1" role="group">
                    <a
                        v-for="label in labels"
                        :key="label.id"
                        href="#"
                        @click.prevent="handleLabelClick(label.id)"
                        class="group flex items-center px-3 py-2 text-sm font-medium text-[var(--text-secondary)] rounded-lg hover:text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] transition-all"
                    >
                        <span class="w-2.5 h-2.5 rounded-full mr-3" :class="label.color"></span>
                        <span class="truncate">{{ label.name }}</span>
                    </a>
                </div>
            </div>
        </nav>
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
import { ref, computed, onMounted } from 'vue';
import {
    ChevronDownIcon,
    PencilIcon,
    UserIcon,
    PlusIcon,
    SettingsIcon,
    RotateCwIcon,
} from "lucide-vue-next";

defineOptions({
    inheritAttrs: false
});
import Dropdown, { type DropdownItem } from "@/components/ui/Dropdown.vue";
import CreateFolderModal from "./CreateFolderModal.vue";
import CreateLabelModal from "./CreateLabelModal.vue";
import { useEmailStore } from '@/stores/emailStore';
import { storeToRefs } from 'pinia';
import { emailAccountService, type EmailAccount } from '@/services/email-account.service';

import { useRouter } from 'vue-router';

const router = useRouter();
const emit = defineEmits(["compose"]);

const store = useEmailStore();
const { systemFolders, customFolders, labels, selectedFolderId } = storeToRefs(store);

// Email Accounts State
const accounts = ref<EmailAccount[]>([]);
const selectedAccount = ref<EmailAccount | null>(null);

async function fetchAccounts() {
    try {
        const data = await emailAccountService.list();
        if (data && data.length > 0) {
            accounts.value = data;
            
            // Try to restore selection from store persistence
            if (store.selectedAccountId) {
                const persisted = data.find(a => a.id === store.selectedAccountId);
                if (persisted) {
                    selectedAccount.value = persisted;
                }
            }
            
            // Fallback to default or first
            if (!selectedAccount.value) {
                selectedAccount.value = data.find(a => a.is_default) || data[0];
                store.setSelectedAccount(selectedAccount.value.id);
            }
        }
    } catch (e) {
        console.error('Failed to fetch email accounts', e);
    }
}

onMounted(() => {
    fetchAccounts();
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
    const items: DropdownItem[] = accounts.value.map(account => ({
        label: `${account.name || account.email}`,
        icon: UserIcon,
        action: () => {
            selectAccount(account);
        }
    }));
    
    // Add static actions
    items.push(
        { label: "Add account", icon: PlusIcon, action: () => router.push('/email/settings?tab=accounts') },
        { label: "Settings", icon: SettingsIcon, action: () => router.push('/email/settings') }
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
        console.error('Sync failed', e);
    } finally {
        isSyncing.value = false;
    }
}

function selectAccount(account: EmailAccount) {
    selectedAccount.value = account;
    // @ts-ignore
    store.setSelectedAccount(account.id);
}
</script>
