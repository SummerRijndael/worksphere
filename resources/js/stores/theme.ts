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
        }
    }

    // Watch for changes
    watch(isDark, () => {
        applyTheme();
    });

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
}, {
    persist: {
        key: 'coresync-theme',
        paths: ['mode', 'chatTheme', 'themeColor'],
    },
});
