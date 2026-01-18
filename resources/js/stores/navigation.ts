import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import type { Ref, ComputedRef } from 'vue';
import api from '@/lib/api';
import type {
    NavigationItem,
    NavigationPreferences,
    NavigationBadges,
    NavigationResponse,
} from '@/types';

export const useNavigationStore = defineStore('navigation', () => {
    // State
    const isSidebarCollapsed = ref(false);
    const isMobileSidebarOpen = ref(false);
    const expandedItems: Ref<Set<string>> = ref(new Set());
    const isLoading = ref(false);
    const badges: Ref<NavigationBadges> = ref({});
    const isSearchOpen = ref(false);

    // Navigation items from server
    const navItems: Ref<NavigationItem[]> = ref([
        {
            id: 'dashboard',
            label: 'Dashboard',
            icon: 'layout-dashboard',
            route: '/dashboard',
            pinned: true,
            pinnable: true,
        },
        {
            id: 'email',
            label: 'Email',
            icon: 'mail',
            route: '/email',
            pinned: true,
            pinnable: true,
        },
        {
            id: 'analytics',
            label: 'Analytics',
            icon: 'chart-bar',
            route: '/analytics',
            pinned: true,
            pinnable: true,
        },
        {
            id: 'projects',
            label: 'Projects',
            icon: 'folder',
            route: '/projects',
            pinned: true,
            pinnable: true,
            children: [
                { id: 'projects-all', label: 'All Projects', route: '/projects' },
                { id: 'projects-my', label: 'My Projects', route: '/projects/my' },
                { id: 'projects-archived', label: 'Archived', route: '/projects/archived' },
            ],
        },
        {
            id: 'tickets',
            label: 'Tickets',
            icon: 'ticket',
            route: '/tickets',
            pinned: true,
            pinnable: true,
            badge_key: 'open_tickets_count',
            children: [
                { id: 'tickets-all', label: 'All Tickets', route: '/tickets' },
                { id: 'tickets-my', label: 'My Tickets', route: '/tickets/my' },
                { id: 'tickets-assigned', label: 'Assigned to Me', route: '/tickets/assigned' },
            ],
        },
        {
            id: 'notifications',
            label: 'Notifications',
            icon: 'bell',
            route: '/notifications',
            pinned: false,
            pinnable: false,
            badge_key: 'unread_notifications_count',
        },
        {
            id: 'settings',
            label: 'Settings',
            icon: 'settings',
            route: '/settings',
            pinned: false,
            pinnable: false,
        },
        {
            id: 'services',
            label: 'Services',
            icon: 'credit-card', // Using credit-card alias for Services/Pricing
            route: '/services',
            pinned: false,
            pinnable: true,
        },
    ]);

    // User preferences for pinned items
    const userPreferences: Ref<NavigationPreferences> = ref({
        pinnedItems: [],
    });

    // Computed
    const teamsItem: ComputedRef<NavigationItem | undefined> = computed(() =>
        navItems.value.find(item => item.id === 'teams')
    );

    const pinnedItems: ComputedRef<NavigationItem[]> = computed(() => {
        const pinnedIds = userPreferences.value.pinned_items || userPreferences.value.pinnedItems || [];
        return navItems.value.filter(item =>
            item.type !== 'divider' &&
            item.pinnable !== false &&
            pinnedIds.includes(item.id)
        );
    });

    const unpinnedItems: ComputedRef<NavigationItem[]> = computed(() => {
        const pinnedIds = userPreferences.value.pinned_items || userPreferences.value.pinnedItems || [];
        return navItems.value.filter(item =>
            item.type !== 'divider' &&
            item.pinnable !== false &&
            !pinnedIds.includes(item.id)
        );
    });

    const bottomItems: ComputedRef<NavigationItem[]> = computed(() =>
        navItems.value.filter(item =>
            item.type !== 'divider' &&
            item.pinnable === false
        )
    );

    const sidebarWidth: ComputedRef<string> = computed(() =>
        isSidebarCollapsed.value ? 'var(--sidebar-width-collapsed)' : 'var(--sidebar-width)'
    );

    // Get badge count for an item
    function getBadge(item: NavigationItem): number | string | undefined {
        if (item.badge_key && badges.value[item.badge_key]) {
            return badges.value[item.badge_key];
        }
        return item.badge;
    }

    // Check if item has children
    function hasChildren(item: NavigationItem): boolean {
        return !!(item.children && item.children.length > 0);
    }

    // Check if item is expanded
    function isExpanded(itemId: string): boolean {
        return expandedItems.value.has(itemId);
    }

    // Toggle expanded state
    function toggleExpanded(itemId: string): void {
        if (expandedItems.value.has(itemId)) {
            expandedItems.value.delete(itemId);
        } else {
            expandedItems.value.add(itemId);
        }
        // Force reactivity
        expandedItems.value = new Set(expandedItems.value);
    }

    // Expand a specific item
    function expandItem(itemId: string): void {
        expandedItems.value.add(itemId);
        expandedItems.value = new Set(expandedItems.value);
    }

    // Collapse a specific item
    function collapseItem(itemId: string): void {
        expandedItems.value.delete(itemId);
        expandedItems.value = new Set(expandedItems.value);
    }

    // Collapse all items
    function collapseAll(): void {
        expandedItems.value = new Set();
    }

    // Actions
    function toggleSidebar(): void {
        isSidebarCollapsed.value = !isSidebarCollapsed.value;
        // Collapse all expanded items when sidebar collapses
        if (isSidebarCollapsed.value) {
            collapseAll();
        }
    }

    function setSidebarCollapsed(collapsed: boolean): void {
        isSidebarCollapsed.value = collapsed;
        if (collapsed) {
            collapseAll();
        }
    }

    function toggleMobileSidebar(): void {
        isMobileSidebarOpen.value = !isMobileSidebarOpen.value;
    }

    function closeMobileSidebar(): void {
        isMobileSidebarOpen.value = false;
    }

    function openSearch(): void {
        isSearchOpen.value = true;
    }

    function closeSearch(): void {
        isSearchOpen.value = false;
    }

    function togglePin(itemId: string): void {
        const pinnedIds = userPreferences.value.pinned_items || userPreferences.value.pinnedItems || [];
        const index = pinnedIds.indexOf(itemId);
        if (index > -1) {
            pinnedIds.splice(index, 1);
        } else {
            pinnedIds.push(itemId);
        }
        userPreferences.value.pinned_items = [...pinnedIds];
        userPreferences.value.pinnedItems = [...pinnedIds];
        savePreferences();
    }

    function pinItem(itemId: string): void {
        const pinnedIds = userPreferences.value.pinned_items || userPreferences.value.pinnedItems || [];
        if (!pinnedIds.includes(itemId)) {
            pinnedIds.push(itemId);
            userPreferences.value.pinned_items = [...pinnedIds];
            userPreferences.value.pinnedItems = [...pinnedIds];
            savePreferences();
        }
    }

    function unpinItem(itemId: string): void {
        const pinnedIds = userPreferences.value.pinned_items || userPreferences.value.pinnedItems || [];
        const index = pinnedIds.indexOf(itemId);
        if (index > -1) {
            pinnedIds.splice(index, 1);
            userPreferences.value.pinned_items = [...pinnedIds];
            userPreferences.value.pinnedItems = [...pinnedIds];
            savePreferences();
        }
    }

    function updateBadge(key: string, count: number): void {
        badges.value[key] = count > 0 ? count : undefined;
    }

    function setBadges(badgeData: NavigationBadges): void {
        badges.value = { ...badgeData };
    }

    // Fetch navigation from server
    async function fetchNavigation(): Promise<void> {
        isLoading.value = true;
        try {
            const response = await api.get<NavigationResponse>('/api/navigation');
            console.log('[Navigation] Response received:', response.data);
            if (response.data.sidebar) {
                navItems.value = response.data.sidebar;
                console.log('[Navigation] navItems updated, count:', navItems.value.length);
                console.log('[Navigation] teamsItem:', navItems.value.find(item => item.id === 'teams'));
            }
            if (response.data.badges) {
                setBadges(response.data.badges);
            }
            if (response.data.preferences) {
                userPreferences.value = response.data.preferences;
            }
        } catch (error) {
            console.error('Failed to fetch navigation:', error);
        } finally {
            isLoading.value = false;
        }
    }

    // Save user preferences to server
    async function savePreferences(): Promise<void> {
        try {
            const pinnedIds = userPreferences.value.pinned_items || userPreferences.value.pinnedItems || [];
            await api.post('/api/navigation/preferences', {
                pinned_items: pinnedIds,
            });
        } catch (error) {
            console.error('Failed to save preferences:', error);
        }
    }

    return {
        // State
        isSidebarCollapsed,
        isMobileSidebarOpen,
        isSearchOpen,
        expandedItems,
        isLoading,
        navItems,
        badges,
        userPreferences,
        // Computed
        teamsItem,
        pinnedItems,
        unpinnedItems,
        bottomItems,
        sidebarWidth,
        // Methods
        getBadge,
        hasChildren,
        isExpanded,
        toggleExpanded,
        expandItem,
        collapseItem,
        collapseAll,
        // Actions
        toggleSidebar,
        setSidebarCollapsed,
        toggleMobileSidebar,
        closeMobileSidebar,
        openSearch,
        closeSearch,
        togglePin,
        pinItem,
        unpinItem,
        updateBadge,
        setBadges,
        fetchNavigation,
        savePreferences,
    };
}, {
    persist: {
        key: 'coresync-navigation',
        paths: ['isSidebarCollapsed', 'userPreferences'],
    },
});
