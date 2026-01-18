<script setup>
import { useThemeStore } from '@/stores/theme';
import { Sun, Moon, Monitor } from 'lucide-vue-next';
import { Button, Dropdown, DropdownItem } from '@/components/ui';

const themeStore = useThemeStore();

const themes = [
    { id: 'light', label: 'Light', icon: Sun },
    { id: 'dark', label: 'Dark', icon: Moon },
    { id: 'system', label: 'System', icon: Monitor },
];

function getCurrentIcon() {
    const current = themes.find(t => t.id === themeStore.currentMode);
    return current?.icon || Monitor;
}
</script>

<template>
    <Dropdown align="end">
        <template #trigger>
            <Button variant="ghost" size="icon" class="h-9 w-9">
                <component :is="getCurrentIcon()" class="h-4 w-4" />
            </Button>
        </template>

        <DropdownItem
            v-for="theme in themes"
            :key="theme.id"
            @select="themeStore.setMode(theme.id)"
        >
            <component :is="theme.icon" class="h-4 w-4" />
            <span>{{ theme.label }}</span>
            <span
                v-if="theme.id === themeStore.currentMode"
                class="ml-auto h-1.5 w-1.5 rounded-full bg-[var(--interactive-primary)]"
            />
        </DropdownItem>
    </Dropdown>
</template>
