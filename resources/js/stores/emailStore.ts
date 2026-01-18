import { defineStore } from 'pinia';
import { ref, computed, watch } from 'vue';
import {
    InboxIcon,
    SendIcon,
    FileTextIcon,
    TrashIcon,
    ArchiveIcon,
    AlertOctagonIcon,
    StarIcon,
    FolderIcon
} from 'lucide-vue-next';
import { emailService } from '@/services/email.service';
import { startEcho } from '@/echo';
import type { Email, EmailFolder, EmailLabel } from '@/types/models/email';

// --- Constants ---
const PRESET_COLORS = [
    'bg-red-500', 'bg-orange-500', 'bg-amber-500', 'bg-yellow-500',
    'bg-lime-500', 'bg-green-500', 'bg-emerald-500', 'bg-teal-500',
    'bg-cyan-500', 'bg-sky-500', 'bg-blue-500', 'bg-indigo-500',
    'bg-violet-500', 'bg-purple-500', 'bg-fuchsia-500', 'bg-pink-500',
    'bg-rose-500',
];

const defaultFolders: EmailFolder[] = [
    { id: 'inbox', name: 'Inbox', icon: InboxIcon, type: 'system', count: 0 },
    { id: 'starred', name: 'Starred', icon: StarIcon, type: 'system' },
    { id: 'sent', name: 'Sent', icon: SendIcon, type: 'system' },
    { id: 'drafts', name: 'Drafts', icon: FileTextIcon, type: 'system', count: 0 },
    { id: 'archive', name: 'Archive', icon: ArchiveIcon, type: 'system' },
    { id: 'spam', name: 'Spam', icon: AlertOctagonIcon, type: 'system' },
    { id: 'trash', name: 'Trash', icon: TrashIcon, type: 'system' },
];

import { useStorage } from '@vueuse/core';

// ...

export const useEmailStore = defineStore('email', () => {
    // State
    const emails = ref<Email[]>([]);
    const folders = ref<EmailFolder[]>([...defaultFolders]);
    const labels = ref<EmailLabel[]>([]);
    const loading = ref(false);
    
    // Pagination
    const currentPage = ref(1);
    const lastPage = ref(1);
    const totalEmails = ref(0);
    const isLoadingMore = ref(false);

    // Selection & Navigation
    const selectedFolderId = ref('inbox');
    const selectedEmailId = ref<string | null>(null);
    const selectedEmailIds = ref<Set<string>>(new Set());
    
    // Filters
    const searchQuery = ref('');
    const filterDateFrom = ref('');
    const filterDateTo = ref('');
    
    // Persist selected account ID
    const selectedAccountId = useStorage<string | null>('coresync_selected_email_account', null);

    // Getters
    const systemFolders = computed(() => folders.value.filter(f => f.type === 'system'));
    const customFolders = computed(() => folders.value.filter(f => f.type === 'custom'));
    
    const selectedFolder = computed(() => 
        folders.value.find(f => f.id === selectedFolderId.value) || folders.value[0]
    );

    const filteredEmails = computed(() => {
        // Backend handles filtering, but we keep this for optimistic UI updates if needed
        // or for client-side filtering on cached data.
        // For now, we assume this array holds what we want to show.
        return emails.value;
    });

    const hasActiveFilters = computed(() => !!(filterDateFrom.value || filterDateTo.value || searchQuery.value));

    // Actions
    async function fetchEmails(page = 1) {
        if (page === 1) {
            loading.value = true;
            emails.value = [];
        } else {
            isLoadingMore.value = true;
        }

        try {
            const params: any = {
                page,
                per_page: 20, // Batch size
                folder: selectedFolderId.value,
                search: searchQuery.value,
                email_account_id: selectedAccountId.value,
            };

            // Handle date filters if present
            if (filterDateFrom.value) params.date_from = filterDateFrom.value;
            if (filterDateTo.value) params.date_to = filterDateTo.value;

            // Handle Label filter (if selectedFolderId is actually a label ID)
            const isLabel = labels.value.some(l => l.id === selectedFolderId.value);
            if (isLabel) {
                 delete params.folder;
                 params.label = selectedFolderId.value;
            }

            const response = await emailService.list(params);
            
            if (response) {
                const mappedEmails: Email[] = response.data.map((e: any) => ({
                    id: e.id,
                    public_id: e.public_id,
                    message_id: e.message_id,
                    
                    // Direct mapping to snake_case as per Email interface
                    from_name: e.from_name || e.from_email || 'Unknown',
                    from_email: e.from_email,
                    
                    to: e.to || [],
                    cc: e.cc || [],
                    bcc: e.bcc || [],
                    
                    subject: e.subject,
                    preview: e.preview,
                    
                    body_html: e.body_html || e.body, // Fallback if API varies
                    body_plain: e.body_plain,
                    
                    date: e.date || e.received_at, // Prefer 'date' accessor
                    
                    is_read: Boolean(e.is_read),
                    is_starred: Boolean(e.is_starred),
                    is_draft: Boolean(e.is_draft),
                    
                    has_attachments: Boolean(e.has_attachments),
                    attachments: e.attachments || e.media || [], 
                    
                    folder: e.folder,
                    labels: e.labels ? e.labels.map((l: any) => l.name) : [],
                    headers: e.headers || {}
                }));

                if (page === 1) {
                    emails.value = mappedEmails;
                } else {
                    emails.value = [...emails.value, ...mappedEmails];
                }
                
                // Handle both Laravel ResourceCollection (meta object) and standard pagination formats
                const meta = response.meta || response;
                currentPage.value = meta.current_page || 1;
                lastPage.value = meta.last_page || 1;
                totalEmails.value = meta.total || 0;
            }
        } catch (error) {
            console.error('Failed to fetch emails:', error);
        } finally {
            loading.value = false;
            isLoadingMore.value = false;
        }
    }

    async function loadMore() {
        if (currentPage.value < lastPage.value && !isLoadingMore.value && !loading.value) {
            await fetchEmails(currentPage.value + 1);
        }
    }

    async function fetchInitialData() {
        // Fetch custom folders and labels
        try {
            const [customFoldersRes, labelsRes] = await Promise.all([
                emailService.getFolders(),
                emailService.getLabels()
            ]);
            
            // Map folders
            const mappedFolders: EmailFolder[] = customFoldersRes.map((f: any) => ({
                id: f.id,
                name: f.name,
                type: 'custom',
                icon: FolderIcon,
                count: 0 // Optional: fetch counts if needed
            }));
            
            // Map labels
            const mappedLabels: EmailLabel[] = labelsRes.map((l: any) => ({
                id: l.id,
                name: l.name,
                color: l.color || 'bg-gray-500' // Default color if missing
            }));

            // Merge with system folders, keeping system ones first
            folders.value = [...defaultFolders, ...mappedFolders];
            labels.value = mappedLabels;
        } catch (e) {
            console.error(e);
        }
    }

    function selectFolder(id: string) {
        if (selectedFolderId.value === id) return;
        selectedFolderId.value = id;
        selectedEmailId.value = null;
        selectedEmailIds.value.clear();
        searchQuery.value = ''; // Reset search logic?
        
        // Reset pagination and fetch
        currentPage.value = 1;
        fetchEmails(1);
    }
    
    // --- Actions ---

    async function sendEmail(formData: FormData) {
        try {
            await emailService.send(formData);
            // Refresh if in sent folder or just notify
            if (selectedFolderId.value === 'sent') {
                fetchEmails(1);
            }
            return true;
        } catch (error) {
            return false;
        }
    }

    // --- CRUD Folders ---
    async function addFolder(name: string) {
        try {
            const res = await emailService.createFolder(name);
            if (res) {
                const newFolder: EmailFolder = { 
                    id: res.id, 
                    name: res.name, 
                    type: 'custom', 
                    icon: FolderIcon 
                };
                folders.value.push(newFolder);
                return newFolder;
            }
        } catch (e) {
            console.error(e);
        }
    }
    
    async function deleteFolder(id: string) {
        try {
            await emailService.deleteFolder(id);
            folders.value = folders.value.filter(f => f.id !== id);
            return true;
        } catch (e) {
            console.error(e);
            return false;
        }
    }

    // --- CRUD Labels ---
    async function addLabel(name: string, color: string = 'bg-blue-500') {
        try {
            const res = await emailService.createLabel(name, color);
            if (res) {
                const newLabel: EmailLabel = { 
                    id: res.id, 
                    name: res.name, 
                    color: res.color 
                };
                labels.value.push(newLabel);
                return newLabel;
            }
        } catch (e) {
            console.error(e);
        }
    }

    async function deleteLabel(id: string) {
        try {
            await emailService.deleteLabel(id);
            labels.value = labels.value.filter(l => l.id !== id);
            return true;
        } catch (e) {
            console.error(e);
            return false;
        }
    }

    // --- Email Actions ---
    async function moveEmail(emailId: string, folderId: string) {
        // Optimistic update
        const email = emails.value.find(e => e.id === emailId);
        if (email) {
            // Remove from current list if looking at a specific folder
            if (selectedFolderId.value !== 'search') { 
                 emails.value = emails.value.filter(e => e.id !== emailId);
            }
        }
        await emailService.move(emailId, folderId);
        return true;
    }

    async function moveEmails(ids: string[], folderId: string) {
        // Optimistic
        ids.forEach(id => {
             const index = emails.value.findIndex(e => e.id === id);
             if (index !== -1) emails.value.splice(index, 1);
        });
        selectedEmailIds.value.clear();
        
        // Parallel requests or bulk API
        await Promise.all(ids.map(id => emailService.move(id, folderId)));
        return ids.length;
    }

    async function deleteEmail(id: string) {
        // Optimistic
        emails.value = emails.value.filter(e => e.id !== id);
        await emailService.delete(id);
        return true;
    }

    async function deleteEmails(ids: string[]) {
        ids.forEach(id => {
            const index = emails.value.findIndex(e => e.id === id);
            if (index !== -1) emails.value.splice(index, 1);
        });
        selectedEmailIds.value.clear();
        await Promise.all(ids.map(id => emailService.delete(id)));
    }

    async function toggleStar(id: string) {
        // Optimistic update
        const email = emails.value.find(e => e.id === id);
        if (email) email.is_starred = !email.is_starred;
        
        await emailService.toggleStar(id);
    }

    async function markAsRead(id: string, isRead: boolean) {
        const email = emails.value.find(e => e.id === id);
        if (email) email.is_read = isRead;
        
        await emailService.markAsRead(id, isRead);
        
        // Update global unread count logic if needed here (computed handles it automatically based on active list)
    }
    
    async function markEmailsAsRead(ids: string[], isRead: boolean) {
        // Optimistic
        ids.forEach(id => {
            const email = emails.value.find(e => e.id === id);
            if (email) email.is_read = isRead;
        });
        selectedEmailIds.value.clear();
        
        // Note: Ideally backend supports bulk op, looping for now
        await Promise.all(ids.map(id => emailService.markAsRead(id, isRead)));
    }

    function getEmailById(id: string) {
        return emails.value.find(e => e.id === id);
    }
    
    // --- Search & Filters ---
    // These now trigger a fetch rather than filtering locally
    function setSelectedAccount(id: string | null) {
        selectedAccountId.value = id;
        fetchEmails(1);
    }

    function applyFilters() {
        currentPage.value = 1;
        fetchEmails(1);
    }

    // Realtime State
    const newEmailCount = ref(0);
    const accountStatus = ref<{
        status: string;
        error: string | null;
        needsReauth: boolean;
    } | null>(null);

    // Watch for account changes to handle subscriptions


    // Subscribe to account channel
    function subscribeToAccount(accountId: string) {
        const echo = window.Echo || startEcho();
        if (!echo) return;

        // Leave any existing subscription for this account just in case (though watcher handles oldId)
        // echo.leave(`email-account.${accountId}`); 

        echo.private(`email-account.${accountId}`)
            .listen('.App\\Events\\Email\\EmailReceived', (e: any) => {
                // If the email belongs to the currently viewed folder, notify user
                // For now, valid for Inbox/Priority. 
                // We just increment counter and let user refresh regardless of folder 
                // (or optimize to only if in inbox/folder matches).
                newEmailCount.value++;
            })
            .listen('.App\\Events\\Email\\SyncStatusChanged', (e: any) => {
                accountStatus.value = {
                    status: e.status,
                    error: e.error,
                    needsReauth: e.status === 'needs_reauth' || e.error?.includes('reconnect') || false
                };
            });
    }

    // Watch selected account for realtime connection
    watch(selectedAccountId, async (newId, oldId) => {
        const echo = window.Echo || startEcho();
        if (!echo) return;

        if (oldId) {
            echo.leave(`email-account.${oldId}`);
            newEmailCount.value = 0;
            accountStatus.value = null;
        }

        if (newId) {
            subscribeToAccount(newId);
            // Fetch initial status
            const account = await emailService.getAccount(newId);
            if (account) {
                updateAccountStatusFromModel(account);
            }
        }
    }, { immediate: true });

    function updateAccountStatusFromModel(account: any) {
        accountStatus.value = {
            status: account.sync_status, // Ensure API returns snake_case 'sync_status'
            error: account.sync_error || account.last_error,
            needsReauth: !!account.needs_reauth
        };
    }

    function loadNewEmails() {
        if (newEmailCount.value > 0) {
            fetchEmails(currentPage.value); // Refresh current page (usually page 1)
            newEmailCount.value = 0;
        }
    }

    return {
        // State
        emails,
        folders,
        labels,
        loading,
        isLoadingMore,
        selectedFolderId,
        selectedEmailId,
        selectedEmailIds,
        selectedAccountId,
        searchQuery,
        filterDateFrom,
        filterDateTo,
        newEmailCount,
        accountStatus,
        
        // Getters
        systemFolders,
        customFolders,
        selectedFolder,
        filteredEmails,
        hasActiveFilters,
        presetColors: PRESET_COLORS,

        // Actions
        fetchEmails,
        fetchInitialData,
        loadMore,
        selectFolder,
        addFolder,
        deleteFolder,
        addLabel,
        deleteLabel,
        setSelectedAccount,
        moveEmail,
        moveEmails,
        deleteEmail,
        deleteEmails,
        toggleStar,
        markAsRead,
        markEmailsAsRead,
        getEmailById,
        sendEmail,
        applyFilters,
        loadNewEmails
    };
});
