import { defineStore } from 'pinia';
import { ref, computed, watch } from 'vue';
import { debounce } from 'lodash';
import type { Ref, ComputedRef } from 'vue';

type ThemeMode = 'light' | 'dark' | 'system';
type ThemeIcon = 'monitor' | 'moon' | 'sun';
export type ThemeColor = 'default' | 'ocean' | 'forest' | 'wine' | 'sunset' | 'midnight';

export const useThemeStore = defineStore('theme', () => {
    // State
    const mode: Ref<ThemeMode> = ref('system');
    const systemPrefersDark = ref(false);
    const themeColor: Ref<ThemeColor> = ref('default');
    const chatTheme = ref<'modern' | 'ocean' | 'nature'>('modern');

    // Computed
    const isDark: ComputedRef<boolean> = computed(() => {
        if (mode.value === 'system') {
            return systemPrefersDark.value;
        }
        return mode.value === 'dark';
    });

    const currentMode: ComputedRef<ThemeMode> = computed(() => mode.value);
    
    const themeIcon: ComputedRef<ThemeIcon> = computed(() => {
        if (mode.value === 'system') return 'monitor';
        if (mode.value === 'dark') return 'moon';
        return 'sun';
    });

    // Alias for chart components that need 'light' or 'dark' theme value
    const currentTheme: ComputedRef<'light' | 'dark'> = computed(() => isDark.value ? 'dark' : 'light');

    // Actions
    function setMode(newMode: ThemeMode): void {
        mode.value = newMode;
        applyTheme();
        syncToApi();
    }

    function setThemeColor(color: ThemeColor): void {
        themeColor.value = color;
        applyTheme();
        syncToApi();
    }
    
    function setChatTheme(theme: 'modern' | 'ocean' | 'nature'): void {
        chatTheme.value = theme;
    }

    function toggleTheme(): void {
        const modes: ThemeMode[] = ['light', 'dark', 'system'];
        const currentIndex = modes.indexOf(mode.value);
        const nextIndex = (currentIndex + 1) % modes.length;
        setMode(modes[nextIndex]);
    }

    const syncToApi = debounce(async () => {
        // We import auth store dynamically to avoid circular dependencies if possible, 
        // or just rely on the fact that if we have a token, we can make the request.
        try {
            // Only sync if we have a token (simple check)
            const token = localStorage.getItem('token') || document.cookie.includes('XSRF-TOKEN');
            if (token) {
                 // Dynamic import to avoid circular dependency
                const { default: api } = await import('@/lib/api');
                await api.put('/api/user/preferences', {
                    appearance: {
                        mode: mode.value,
                        color: themeColor.value
                    }
                });
            }
        } catch (e) {
            // Silent fail
        }
    }, 1000);

    function applyTheme(): void {
        const root = document.documentElement;

        if (isDark.value) {
            root.classList.add('dark');
        } else {
            root.classList.remove('dark');
        }

        // Apply Theme Color
        // Remove existing theme classes
        const themeClasses = ['theme-default', 'theme-ocean', 'theme-forest', 'theme-wine', 'theme-sunset', 'theme-midnight'];
        root.classList.remove(...themeClasses);

        // Add new theme class (if not default, though default also has a class, keeping it clean might be better but consistency is safer)
        if (themeColor.value !== 'default') {
            root.classList.add(`theme-${themeColor.value}`);
        } else {
            root.classList.add('theme-default');
        }

        // Update meta theme-color
        const metaTheme = document.querySelector('meta[name="theme-color"]');
        if (metaTheme) {
            metaTheme.setAttribute('content', isDark.value ? '#0f172a' : '#ffffff');
        }
    }

    // Persistence Helpers
    const getStorageKey = () => {
        // We need to access auth store to get public ID, but avoiding circular dep issues
        // We can check localStorage 'worksphere-auth' directly or use auth store if available
        try {
            const authData = localStorage.getItem('worksphere-auth');
            if (authData) {
                const parsed = JSON.parse(authData);
                // Auth store persists 'user' object
                if (parsed.user && parsed.user.public_id) {
                    return `worksphere_theme_${parsed.user.public_id}`;
                }
            }
        } catch (e) {
            console.warn('Failed to parse auth storage for theme scoping');
        }
        return 'worksphere_theme_guest';
    };

    const loadFromStorage = () => {
        const key = getStorageKey();
        try {
            const stored = localStorage.getItem(key);
            if (stored) {
                const parsed = JSON.parse(stored);
                if (parsed.mode) mode.value = parsed.mode;
                if (parsed.themeColor) themeColor.value = parsed.themeColor;
                if (parsed.chatTheme) chatTheme.value = parsed.chatTheme;
                applyTheme();
            }
        } catch (e) {
            console.warn('Failed to load theme from storage', e);
        }
    };

    const saveToStorage = () => {
        const key = getStorageKey();
        const data = {
            mode: mode.value,
            themeColor: themeColor.value,
            chatTheme: chatTheme.value
        };
        localStorage.setItem(key, JSON.stringify(data));
    };

    function initializeTheme(): void {
        // Check system preference
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        systemPrefersDark.value = mediaQuery.matches;

        // Listen for system theme changes
        mediaQuery.addEventListener('change', (e) => {
            systemPrefersDark.value = e.matches;
            if (mode.value === 'system') {
                applyTheme();
            }
        });

        // Load persisted state
        loadFromStorage();
        
        // Apply initial theme
        applyTheme();
    }
    
    function syncFromUser(userPreferences: any) {
        if (userPreferences?.appearance) {
            if (userPreferences.appearance.mode) {
                mode.value = userPreferences.appearance.mode;
            }
            if (userPreferences.appearance.color) {
                themeColor.value = userPreferences.appearance.color;
            }
            applyTheme();
            saveToStorage(); // Save synced values to local storage
        }
    }

    // Watch for changes and persist
    watch([mode, themeColor, chatTheme], () => {
        applyTheme();
        saveToStorage();
    });

    // Watch for auth changes (re-load if user changes)
    // This is tricky inside a store as we can't easily watch another store without setup
    // But since `initializeTheme` is called on app mount, and `switchedUser` might reload app...
    // ideally we watch authStore.user.public_id. 
    // For now, let's rely on `syncFromUser` being called by authStore on login.

    return {
        // State
        mode,
        systemPrefersDark,
        chatTheme,
        // Computed
        isDark,
        currentMode,
        currentTheme,
        themeIcon,
        themeColor,
        // Actions
        setMode,
        setThemeColor,
        setChatTheme,
        toggleTheme,
        initializeTheme,
        applyTheme,
        syncFromUser
    };
});
