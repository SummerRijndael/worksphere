import { defineStore } from "pinia";
import { ref, onMounted, watch, computed } from "vue";
import {
    InboxIcon,
    SendIcon,
    FileTextIcon,
    TrashIcon,
    ArchiveIcon,
    AlertOctagonIcon,
    StarIcon,
    FolderIcon,
    ClockIcon,
} from "lucide-vue-next";
import { emailService } from "@/services/email.service";
import { startEcho } from "@/echo";
import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import type { Email, EmailFolder, EmailLabel } from "@/types/models/email";
export type { Email, EmailFolder, EmailLabel };

// --- Constants ---
const PRESET_COLORS = [
    "bg-red-500",
    "bg-orange-500",
    "bg-amber-500",
    "bg-yellow-500",
    "bg-lime-500",
    "bg-green-500",
    "bg-emerald-500",
    "bg-teal-500",
    "bg-cyan-500",
    "bg-sky-500",
    "bg-blue-500",
    "bg-indigo-500",
    "bg-violet-500",
    "bg-purple-500",
    "bg-fuchsia-500",
    "bg-pink-500",
    "bg-rose-500",
];

const defaultFolders: EmailFolder[] = [
    { id: "inbox", name: "Inbox", icon: InboxIcon, type: "system", count: 0 },
    { id: "starred", name: "Starred", icon: StarIcon, type: "system" },
    { id: "sent", name: "Sent", icon: SendIcon, type: "system" },
    {
        id: "drafts",
        name: "Drafts",
        icon: FileTextIcon,
        type: "system",
        count: 0,
    },
    { id: "archive", name: "Archive", icon: ArchiveIcon, type: "system" },
    {
        id: "scheduled",
        name: "Scheduled",
        icon: ClockIcon,
        type: "system",
        count: 0,
    },
    { id: "spam", name: "Spam", icon: AlertOctagonIcon, type: "system" },
    { id: "trash", name: "Trash", icon: TrashIcon, type: "system" },
];

import { useStorage } from "@vueuse/core";

// ...

export const useEmailStore = defineStore("email", () => {
    // Manual user-scoped persistence
    const authStore = useAuthStore();
    const getStorageKey = () =>
        `worksphere_email_account_${authStore.user?.public_id || "guest"}`;

    // State
    const emails = ref<Email[]>([]);
    const folders = ref<EmailFolder[]>([...defaultFolders]);
    const labels = ref<EmailLabel[]>([]);
    const remoteFolders = ref<any[]>([]); // This will be replaced by accountFolders for specific account
    const accountFolders = ref<Record<string, any[]>>({}); // Stores remote folders per account
    const accounts = ref<any[]>([]);
//     const accountsLoading = ref(false);
    const foldersLoading = ref<Record<string, boolean>>({});
//     const initialLoadDone = ref(false);
    const loading = ref(false);

    // Pagination
    const currentPage = ref(1);
    const lastPage = ref(1);
    const totalEmails = ref(0);
    const isLoadingMore = ref(false);

    // Sorting (Persisted)
    const sortField = useStorage<"date" | "sender" | "subject">(
        () => `${getStorageKey()}_sort_field`,
        "date"
    );
    const sortOrder = useStorage<"asc" | "desc">(
        () => `${getStorageKey()}_sort_order`,
        "desc"
    );

    // Selection & Navigation (selectedEmailId Persisted)
    const selectedFolderId = ref("inbox");
    const selectedEmailId = useStorage<string | null>(
        () => `${getStorageKey()}_last_email`,
        null
    );
    const selectedEmailIds = ref<Set<string>>(new Set());
    const isSidebarCollapsed = useStorage<boolean>(
        "worksphere_email_sidebar_collapsed",
        false
    );

    // Filters
    const searchQuery = ref("");
    const filterDateFrom = ref("");
    const filterDateTo = ref("");

    // Persist selected account ID

    // Manual user-scoped persistence (Moved to top)
    const selectedAccountId = ref<string | null>(
        localStorage.getItem(getStorageKey()) || null,
    );

    // watch(selectedEmailId, (newVal, oldVal) => {
    //     console.log(`[Store] selectedEmailId changed from ${oldVal} to ${newVal}`);
    //     if (newVal === null) {
    //         console.trace("[Store] selectedEmailId cleared!");
    //     }
    // });

    watch(selectedAccountId, (newVal) => {
        if (newVal) {
            localStorage.setItem(getStorageKey(), newVal);
        } else {
            localStorage.removeItem(getStorageKey());
        }
    });

    // Handle user switch
    watch(
        () => authStore.user?.public_id,
        () => {
            selectedAccountId.value =
                localStorage.getItem(getStorageKey()) || null;
        },
    );

    // Getters
    const selectedAccount = computed(() =>
        accounts.value.find((a) => a.id === selectedAccountId.value),
    );

    const systemFolders = computed(() => {
        const baseFolders = folders.value.filter((f) => f.type === "system");
        if (!selectedAccount.value) return baseFolders;

        const disabled = selectedAccount.value.disabled_folders || [];
        // Map common system folders to their logical types for filtering
        return baseFolders.filter((f) => !disabled.includes(f.id));
    });

    const customFolders = computed(() =>
        folders.value.filter((f) => f.type === "custom"),
    );

    const subscribedRemoteFolders = computed(() => {
        if (!selectedAccount.value) return [];
        const disabled = selectedAccount.value.disabled_folders || [];
        const currentAccountRemoteFolders = selectedAccountId.value
            ? accountFolders.value[selectedAccountId.value] || []
            : [];
        return currentAccountRemoteFolders.filter((f) => !disabled.includes(f.id));
    });

    const selectedFolder = computed(
        () =>
            folders.value.find((f) => f.id === selectedFolderId.value) ||
            subscribedRemoteFolders.value.find(
                (f) => f.id === selectedFolderId.value,
            ) ||
            folders.value[0],
    );

    const filteredEmails = computed(() => {
        // Backend handles filtering, but we keep this for optimistic UI updates if needed
        // or for client-side filtering on cached data.
        // For now, we assume this array holds what we want to show.
        return emails.value;
    });

    const hasActiveFilters = computed(
        () =>
            !!(filterDateFrom.value || filterDateTo.value || searchQuery.value),
    );

    function toggleSort(field: "date" | "sender" | "subject") {
        if (sortField.value === field) {
            sortOrder.value = sortOrder.value === "asc" ? "desc" : "asc";
        } else {
            sortField.value = field;
            // Default newest first for date, alphabetical for others
            sortOrder.value = field === "date" ? "desc" : "asc";
        }
        // Reset pagination and fetch
        currentPage.value = 1;
        fetchEmails(1);
    }

    const sortedEmails = computed(() => {
        return emails.value;
    });

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
                sort_by: sortField.value,
                order: sortOrder.value,
            };

            // Handle date filters if present
            if (filterDateFrom.value) params.date_from = filterDateFrom.value;
            if (filterDateTo.value) params.date_to = filterDateTo.value;

            // Handle Label filter (if selectedFolderId is actually a label ID)
            const isLabel = labels.value.some(
                (l) => l.id === selectedFolderId.value,
            );
            if (isLabel) {
                delete params.folder;
                params.label = selectedFolderId.value;
            }

            const response = await emailService.list(params);

            if (response) {
                const data = Array.isArray(response)
                    ? response
                    : response.data || [];
                const mappedEmails: Email[] = data.map((e: any) => ({
                    id: e.id,
                    public_id: e.public_id,
                    message_id: e.message_id,

                    // Direct mapping to snake_case as per Email interface
                    from_name: e.from_name || e.from_email || "Unknown",
                    from_email: e.from_email,

                    to: e.to || [],
                    cc: e.cc || [],
                    bcc: e.bcc || [],

                    subject: e.subject,
                    preview: e.preview,

                    body_html: e.body_html || e.body, // Fallback if API varies
                    body_plain: e.body_plain,

                    date: e.date || e.received_at, // Prefer 'date' accessor

                    thread_id: e.thread_id || null,

                    is_read: Boolean(e.is_read),
                    is_starred: Boolean(e.is_starred),
                    is_pinned: Boolean(e.is_pinned),
                    is_important: Boolean(e.is_important),
                    is_draft: Boolean(e.is_draft),

                    has_attachments: Boolean(e.has_attachments),
                    attachments: e.attachments || e.media || [],

                    folder: e.folder,
                    labels: e.labels ? e.labels.map((l: any) => l.name) : [],
                    headers: e.headers || {},
                }));

                if (page === 1) {
                    emails.value = mappedEmails;
                } else {
                    // Filter out duplicates when appending
                    const existingIds = new Set(emails.value.map(e => e.id));
                    const newUniqueEmails = mappedEmails.filter(e => !existingIds.has(e.id));
                    emails.value = [...emails.value, ...newUniqueEmails];
                }

                // Handle both Laravel ResourceCollection (meta object) and standard pagination formats
                const meta = (response as any).meta || response;
                currentPage.value = meta.current_page || 1;
                lastPage.value = meta.last_page || 1;
                totalEmails.value = meta.total || 0;
            }
        } catch (error) {
            console.error("Failed to fetch emails:", error);
        } finally {
            loading.value = false;
            isLoadingMore.value = false;
        }
    }

    async function fetchAccountFolders(accountId: string) {
        if (foldersLoading.value[accountId]) return;

        foldersLoading.value = { ...foldersLoading.value, [accountId]: true };
        try {
            const response = await axios.get(
                `/api/email-accounts/${accountId}/remote-folders`,
            );
            accountFolders.value = { ...accountFolders.value, [accountId]: response.data.data };
        } catch (error: any) {
            console.error("Failed to fetch account folders:", error);
            accountFolders.value = { ...accountFolders.value, [accountId]: [] }; // Clear folders for this account on error

            // If 404/403, the account might be deleted or inaccessible
            if (
                error.response &&
                (error.response.status === 404 || error.response.status === 403)
            ) {
                if (selectedAccountId.value === accountId) {
                    selectedAccountId.value = null;
                }
            }
        }
    }

    async function fetchThread(threadId: string) {
        try {
            const response = await emailService.getThread(threadId);
            // The service now returns response.data directly. 
            // Most APIs wrap the list in a 'data' property.
            return Array.isArray(response) ? response : (response?.data || []);
        } catch (error) {
            console.error("Failed to fetch thread:", error);
            return [];
        }
    }

    async function fetchEmailBody(id: string) {
        try {
            const response = await axios.get(`/api/emails/${id}/body`);
            // The response is now a full EmailResource (wrapped in 'data' by Laravel)
            const e = response.data?.data || response.data;
            
            if (e) {
                const email = emails.value.find((item) => item.id === id);
                if (email) {
                    // Map common fields to ensure consistency with fetchEmails logic
                    email.body_html = e.body_html;
                    email.body_plain = e.body_plain;
                    email.has_attachments = Boolean(e.has_attachments);
                    email.attachments = e.attachments || [];
                    
                    // Update flags just in case they changed
                    email.is_read = Boolean(e.is_read);
                    email.is_starred = Boolean(e.is_starred);
                    
                    // You could also do Object.assign(email, mappedData) if you trust the mapping
                }
                return e;
            }
        } catch (error) {
            console.error("Failed to fetch email body:", error);
        }
        return null;
    }

    async function fetchEmailById(id: string) {
        try {
            const email = await emailService.find(id);
            if (email) {
                // Check if already in list to avoid duplicates
                if (!emails.value.find((e) => e.id === email.id)) {
                    // We don't want to mess up the sorted list for most views,
                    // but having it in emails.value allows computed properties to find it.
                    // If we are in a paginated list, adding to the end is safest.
                    emails.value.push(email);
                }
                return email;
            }
        } catch (error) {
            console.error("Failed to fetch single email:", error);
        }
        return null;
    }

    async function loadMore() {
        if (
            currentPage.value < lastPage.value &&
            !isLoadingMore.value &&
            !loading.value
        ) {
            await fetchEmails(currentPage.value + 1);
        }
    }

    async function fetchInitialData() {
        // Fetch custom folders and labels
        try {
            const [customFoldersRes, labelsRes, accountsRes] =
                await Promise.all([
                    emailService.getFolders(),
                    emailService.getLabels(),
                    axios
                        .get("/api/email-accounts")
                        .then((res) => res.data.data),
                ]);

            accounts.value = accountsRes;

            // Map folders
            const mappedFolders: EmailFolder[] = customFoldersRes.map(
                (f: any) => ({
                    id: f.id,
                    name: f.name,
                    type: "custom",
                    icon: FolderIcon,
                    count: 0, // Optional: fetch counts if needed
                }),
            );

            // Map labels
            const mappedLabels: EmailLabel[] = labelsRes.map((l: any) => ({
                id: l.id,
                name: l.name,
                color: l.color || "bg-gray-500", // Default color if missing
            }));

            // Merge with system folders, keeping system ones first
            folders.value = [...defaultFolders, ...mappedFolders];
            labels.value = mappedLabels;

            // Set default account if none selected
            if (accountsRes.length > 0) {
                if (
                    !selectedAccountId.value ||
                    !accountsRes.find((a: any) => a.id === selectedAccountId.value)
                ) {
                    const defaultAcc =
                        accountsRes.find((a: any) => a.is_default) ||
                        accountsRes[0];
                    selectedAccountId.value = defaultAcc.id;
                }
            } else {
                // No accounts left, clear selection
                selectedAccountId.value = null;
                remoteFolders.value = [];
            }
        } catch (e) {
            console.error(e);
        }
    }

    function selectFolder(id: string) {
        if (selectedFolderId.value === id) return;
        selectedFolderId.value = id;
        selectedEmailId.value = null;
        selectedEmailIds.value.clear();
        searchQuery.value = ""; // Reset search logic?

        // Reset pagination and fetch
        currentPage.value = 1;
        fetchEmails(1);
    }

    // --- Actions ---

    async function sendEmail(formData: FormData) {
        try {
            await emailService.send(formData);
            // Refresh if in sent folder or just notify
            if (selectedFolderId.value === "sent") {
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
                    type: "custom",
                    icon: FolderIcon,
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
            folders.value = folders.value.filter((f) => f.id !== id);
            return true;
        } catch (e) {
            console.error(e);
            return false;
        }
    }

    // --- CRUD Labels ---
    async function addLabel(name: string, color: string = "bg-blue-500") {
        try {
            const res = await emailService.createLabel(name, color);
            if (res) {
                const newLabel: EmailLabel = {
                    id: res.id,
                    name: res.name,
                    color: res.color,
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
            labels.value = labels.value.filter((l) => l.id !== id);
            return true;
        } catch (e) {
            console.error(e);
            return false;
        }
    }


    async function sendEmail(formData: FormData) {
        try {
            await emailService.send(formData);
            return true;
        } catch (e) {
            console.error(e);
            return false;
        }
    }

    // --- Email Actions ---
    async function moveEmail(emailId: string, folderId: string) {
        // Optimistic update
        const email = emails.value.find((e) => e.id === emailId);
        if (email) {
            // Remove from current list if looking at a specific folder
            if (selectedFolderId.value !== "search") {
                emails.value = emails.value.filter((e) => e.id !== emailId);
            }
        }
        await emailService.move(emailId, folderId);
        return true;
    }

    async function moveEmails(ids: string[], folderId: string) {
        // Optimistic
        ids.forEach((id) => {
            const index = emails.value.findIndex((e) => e.id === id);
            if (index !== -1) emails.value.splice(index, 1);
        });
        selectedEmailIds.value.clear();

        // Parallel requests or bulk API
        await Promise.all(ids.map((id) => emailService.move(id, folderId)));
        return ids.length;
    }

    async function deleteEmail(id: string) {
        // Optimistic
        emails.value = emails.value.filter((e) => e.id !== id);
        await emailService.delete(id);
        return true;
    }

    async function deleteEmails(ids: string[]) {
        ids.forEach((id) => {
            const index = emails.value.findIndex((e) => e.id === id);
            if (index !== -1) emails.value.splice(index, 1);
        });
        selectedEmailIds.value.clear();
        await Promise.all(ids.map((id) => emailService.delete(id)));
    }

    async function toggleStar(id: string) {
        // Optimistic update
        const email = emails.value.find((e) => e.id === id);
        if (email) email.is_starred = !email.is_starred;

        await emailService.toggleStar(id);
    }

    async function togglePin(id: string) {
        // Optimistic update
        const email = emails.value.find((e) => e.id === id);
        if (email) email.is_pinned = !email.is_pinned;

        await axios.patch(`/api/emails/${id}`, { is_pinned: true }); // Value is toggled server-side
    }

    async function togglePinEmails(ids: string[], pin: boolean) {
        // Optimistic
        ids.forEach((id) => {
            const email = emails.value.find((e) => e.id === id);
            if (email) email.is_pinned = pin;
        });
        
        // Parallel requests for now, or could implement a bulk endpoint later
        await Promise.all(ids.map((id) => axios.patch(`/api/emails/${id}`, { is_pinned: true })));
    }

    async function toggleImportant(id: string) {
        // Optimistic update
        const email = emails.value.find((e) => e.id === id);
        if (email) email.is_important = !email.is_important;

        await emailService.toggleImportant(id);
    }

    async function markAsRead(id: string, isRead: boolean) {
        const email = emails.value.find((e) => e.id === id);
        if (email) email.is_read = isRead;

        await emailService.markAsRead(id, isRead);

        // Update global unread count logic if needed here (computed handles it automatically based on active list)
    }

    async function markEmailsAsRead(ids: string[], isRead: boolean) {
        // Optimistic
        ids.forEach((id) => {
            const email = emails.value.find((e) => e.id === id);
            if (email) email.is_read = isRead;
        });
        selectedEmailIds.value.clear();

        // Note: Ideally backend supports bulk op, looping for now
        await Promise.all(ids.map((id) => emailService.markAsRead(id, isRead)));
    }

    function toggleEmailSelection(id: string) {
        console.log("toggleEmailSelection called for", id);
        console.log("Current selectedEmailId (preview):", selectedEmailId.value);
        const newSet = new Set(selectedEmailIds.value);
        if (newSet.has(id)) {
            newSet.delete(id);
        } else {
            newSet.add(id);
        }
        selectedEmailIds.value = newSet;
        console.log("New selectedEmailIds set size:", selectedEmailIds.value.size);
        // Safeguard: Ensure we don't accidentally clear the currently viewed email
        // Logic: checking boxes should not affect the preview pane
    }

    function toggleSelectAll(allIds: string[]) {
        console.log("toggleSelectAll called. Count:", allIds.length);
        console.log("Current selectedEmailId inside toggleSelectAll:", selectedEmailId.value);
        if (selectedEmailIds.value.size === allIds.length && allIds.length > 0) {
            selectedEmailIds.value = new Set();
        } else {
            selectedEmailIds.value = new Set(allIds);
        }
        console.log("New selectedEmailIds size after toggleAll:", selectedEmailIds.value.size);
    }

    function getEmailById(id: string) {
        return emails.value.find((e) => e.id === id);
    }

    // --- Search & Filters ---
    // These now trigger a fetch rather than filtering locally
    function setSelectedAccount(id: string | null) {
        selectedAccountId.value = id;
        if (id) {
            fetchAccountFolders(id);
        } else {
            remoteFolders.value = [];
        }
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
            .listen(".App\\Events\\Email\\EmailReceived", (e: any) => {
                // Determine if we should prepend the email or just increment the counter
                const isCorrectAccount =
                    selectedAccountId.value === e.account_id;
                const matchesFolder =
                    selectedFolderId.value === e.email.folder ||
                    (selectedFolderId.value === "inbox" &&
                        e.email.folder === "inbox");

                if (
                    isCorrectAccount &&
                    matchesFolder &&
                    currentPage.value === 1 &&
                    !hasActiveFilters.value
                ) {
                    // Only prepend if sorting by Date DESC (Newest First)
                    if (sortField.value === 'date' && sortOrder.value === 'desc') {
                        // Prepend to the list if on page 1 and no search filters are active
                        // We check if it already exists to avoid duplicates
                        if (!emails.value.some((msg) => msg.id === e.email.id)) {
                            emails.value.unshift(e.email);
                            totalEmails.value++;
                        }
                    } else {
                         // Otherwise just increment the notification counter
                         newEmailCount.value++;
                    }
                } else {
                    // Otherwise just increment the notification counter
                    newEmailCount.value++;
                }
            })
            .listen(".App\\Events\\Email\\SyncStatusChanged", (e: any) => {
                accountStatus.value = {
                    status: e.status,
                    error: e.error,
                    needsReauth:
                        e.status === "needs_reauth" ||
                        e.error?.includes("reconnect") ||
                        false,
                };
            });
    }

    // Watch selected account for realtime connection
    watch(
        selectedAccountId,
        async (newId, oldId) => {
            const echo = window.Echo || startEcho();
            if (!echo) return;

            if (oldId) {
                echo.leave(`email-account.${oldId}`);
                newEmailCount.value = 0;
                accountStatus.value = null;
            }

            if (newId) {
                // Fetch remote folders for the newly selected account
                fetchAccountFolders(newId);

                try {
                    // Fetch initial status first to verify existence
                    const account = await emailService.getAccount(newId);

                    if (account) {
                        updateAccountStatusFromModel(account);
                        // Only subscribe if account exists and we have access
                        subscribeToAccount(newId);
                    } else {
                        // Should be caught by catch block if 404, but just in case
                        selectedAccountId.value = null;
                    }
                } catch (error: any) {
                    // If 404 Not Found or 403 Forbidden, clear the stale ID silently
                    if (
                        error.response &&
                        (error.response.status === 404 ||
                            error.response.status === 403)
                    ) {
                        selectedAccountId.value = null;
                    } else {
                        console.error(
                            "Failed to load selected email account:",
                            error,
                        );
                    }

                    accountStatus.value = null;
                }
            }
        },
        { immediate: true },
    );

    function updateAccountStatusFromModel(account: any) {
        accountStatus.value = {
            status: account.sync_status, // Ensure API returns snake_case 'sync_status'
            error: account.sync_error || account.last_error,
            needsReauth: !!account.needs_reauth,
        };
    }

    function loadNewEmails() {
        if (newEmailCount.value > 0) {
            // Force switch to Date Desc so user sees the new emails
            if (sortField.value !== 'date' || sortOrder.value !== 'desc') {
                sortField.value = 'date';
                sortOrder.value = 'desc';
            }
            
            currentPage.value = 1;
            fetchEmails(1); // Refresh page 1
            newEmailCount.value = 0;
        }
    }

    function toggleSidebar() {
        isSidebarCollapsed.value = !isSidebarCollapsed.value;
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
        accounts,
        remoteFolders,
        searchQuery,
        filterDateFrom,
        filterDateTo,
        newEmailCount,
        totalEmails,
        accountStatus,
        isSidebarCollapsed, // Added isSidebarCollapsed

        // Getters
        selectedAccount,
        systemFolders,
        customFolders,
        subscribedRemoteFolders,
        selectedFolder,
        filteredEmails,
        hasActiveFilters,
        presetColors: PRESET_COLORS,

        // Actions
        fetchEmails,
        fetchThread,
        fetchEmailBody,
        fetchEmailById,
        toggleEmailSelection,
        toggleSelectAll,
        fetchInitialData,
        loadMore,
        selectFolder,
        addFolder,
        deleteFolder,
        addLabel,
        deleteLabel,
        sendEmail,
        togglePin,
        togglePinEmails,
        toggleImportant,
        setSelectedAccount,
        moveEmail,
        moveEmails,
        deleteEmail,
        deleteEmails,
        toggleStar,
        markAsRead,
        markEmailsAsRead,
        getEmailById,
        applyFilters,
        loadNewEmails,
        fetchAccountFolders,
        toggleSidebar,

        // Sort Actions
        sortField,
        sortOrder,
        sortedEmails,
        toggleSort,
    };
});
