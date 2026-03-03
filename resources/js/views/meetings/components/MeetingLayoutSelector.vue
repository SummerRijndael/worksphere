<template>
    <div class="layout-selector">
        <button
            class="ctrl-btn"
            :class="{ 'ctrl-btn--active': showMenu }"
            @click="showMenu = !showMenu"
            title="Change layout"
        >
            <Icon :name="currentLayoutIcon" size="22" />
        </button>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 scale-95 translate-y-2"
            enter-to-class="opacity-100 scale-100 translate-y-0"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100 scale-100 translate-y-0"
            leave-to-class="opacity-0 scale-95 translate-y-2"
        >
            <div v-if="showMenu" ref="menuRef" class="layout-menu shadow-xl">
                <div class="layout-menu-header">
                    <span>Change layout</span>
                </div>
                <div class="layout-options">
                    <button
                        v-for="layout in layouts"
                        :key="layout.id"
                        class="layout-option"
                        :class="{
                            'layout-option--active':
                                meetingStore.preferredLayout === layout.id,
                        }"
                        @click="selectLayout(layout.id)"
                    >
                        <div class="layout-option-icon">
                            <Icon :name="layout.icon" size="20" />
                        </div>
                        <div class="layout-option-info">
                            <div class="layout-option-label">
                                {{ layout.label }}
                            </div>
                            <div class="layout-option-desc">
                                {{ layout.desc }}
                            </div>
                        </div>
                        <div
                            v-if="meetingStore.preferredLayout === layout.id"
                            class="layout-option-check"
                        >
                            <Icon name="check" size="16" />
                        </div>
                    </button>
                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup lang="ts">
import { ref, computed } from "vue";
import { onClickOutside } from "@vueuse/core";
import { useMeetingStore } from "@/stores/meeting";
import { Icon } from "@/components/ui";

const meetingStore = useMeetingStore();
const showMenu = ref(false);
const menuRef = ref(null);

onClickOutside(menuRef, () => {
    if (showMenu.value) showMenu.value = false;
});

const layouts = [
    {
        id: "auto",
        label: "Auto",
        icon: "sparkles",
        desc: "Choose the best layout for you",
    },
    {
        id: "tiled",
        label: "Tiled",
        icon: "layout-grid",
        desc: "See everyone at once",
    },
    {
        id: "spotlight",
        label: "Spotlight",
        icon: "maximize",
        desc: "Focus on the main speaker",
    },
    {
        id: "sidebar",
        label: "Sidebar",
        icon: "layout",
        desc: "Main speaker with others in sidebar",
    },
];

const currentLayoutIcon = computed(() => {
    return (
        layouts.find((l) => l.id === meetingStore.preferredLayout)?.icon ||
        "sparkles"
    );
});

function selectLayout(id: any) {
    meetingStore.setLayout(id);
    showMenu.value = false;
}

defineExpose({ showMenu });
</script>

<style scoped>
.layout-selector {
    position: relative;
    display: flex;
    align-items: center;
}

.layout-selector :deep(.ctrl-btn) {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: var(--surface-tertiary);
    color: var(--text-primary);
    border: 1px solid var(--border-subtle);
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    padding: 0;
}

.layout-selector :deep(.ctrl-btn:hover) {
    background: var(--surface-secondary);
    border-color: var(--border-default);
}

.layout-selector :deep(.ctrl-btn--active) {
    background: #8ab4f8 !important;
    color: #202124 !important;
    border-color: #8ab4f8 !important;
}

.layout-menu {
    position: absolute;
    bottom: calc(100% + 12px);
    right: 0;
    width: 280px;
    background: var(--surface-elevated);
    backdrop-filter: blur(20px);
    border: 1px solid var(--border-default);
    border-radius: 16px;
    overflow: hidden;
    z-index: 1000;
}

.layout-menu-header {
    padding: 16px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    border-bottom: 1px solid var(--border-subtle);
}

.layout-options {
    padding: 8px;
}

.layout-option {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border: none;
    background: transparent;
    border-radius: 12px;
    cursor: pointer;
    text-align: left;
    transition: all 0.2s;
    color: var(--text-secondary);
}

.layout-option:hover {
    background: var(--surface-tertiary);
    color: var(--text-primary);
}

.layout-option--active {
    background: rgba(138, 180, 248, 0.1);
    color: #8ab4f8;
}

.layout-option-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--surface-secondary);
    border-radius: 10px;
    flex-shrink: 0;
}

.layout-option--active .layout-option-icon {
    background: rgba(138, 180, 248, 0.2);
}

.layout-option-info {
    flex-grow: 1;
}

.layout-option-label {
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 2px;
}

.layout-option-desc {
    font-size: 11px;
    opacity: 0.6;
}

.layout-option-check {
    color: #8ab4f8;
}
</style>
