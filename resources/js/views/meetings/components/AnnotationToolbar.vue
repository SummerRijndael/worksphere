<template>
    <div class="annotation-toolbar" @click.stop>
        <!-- Tool Groups -->
        <div class="tool-group">
            <button 
                v-for="tool in tools" 
                :key="tool.id"
                class="tool-btn"
                :class="{ 'tool-btn--active': activeTool === tool.id }"
                @click="setTool(tool.id)"
                :title="tool.label"
            >
                <Icon :name="tool.icon" size="18" />
            </button>
        </div>

        <div class="tool-divider"></div>

        <!-- Color Picker -->
        <div class="tool-group">
            <button 
                v-for="color in colors" 
                :key="color"
                class="color-btn"
                :class="{ 'color-btn--active': activeColor === color }"
                :style="{ backgroundColor: color }"
                @click="setColor(color)"
            ></button>
        </div>

        <div class="tool-divider"></div>

        <!-- Actions -->
        <div class="tool-group">
            <button class="tool-btn" @click="emit('undo')" title="Undo">
                <Icon name="undo" size="18" />
            </button>
            <button class="tool-btn tool-btn--danger" @click="emit('clear')" title="Clear All">
                <Icon name="trash-2" size="18" />
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { Icon } from '@/components/ui';

const props = defineProps<{
    activeTool: string;
    activeColor: string;
}>();

const emit = defineEmits<{
    (e: 'update:activeTool', tool: string): void;
    (e: 'update:activeColor', color: string): void;
    (e: 'clear'): void;
    (e: 'undo'): void;
}>();

const tools = [
    { id: 'pen', label: 'Pen', icon: 'pen-tool' },
    { id: 'highlighter', label: 'Highlighter', icon: 'highlighter' },
    { id: 'rect', label: 'Square', icon: 'square' },
    { id: 'circle', label: 'Circle', icon: 'circle' },
    { id: 'eraser', label: 'Eraser', icon: 'eraser' },
];

const colors = [
    '#ea4335', // Red
    '#34a853', // Green
    '#4285f4', // Blue
    '#fbbc04', // Yellow
    '#ffffff', // White
];

function setTool(id: string) {
    emit('update:activeTool', id);
}

function setColor(color: string) {
    emit('update:activeColor', color);
}
</script>

<style scoped>
.annotation-toolbar {
    position: absolute;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(32, 33, 36, 0.9);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    padding: 8px 16px;
    display: flex;
    align-items: center;
    gap: 16px;
    z-index: 1000;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.5);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.tool-group {
    display: flex;
    align-items: center;
    gap: 6px;
}

.tool-divider {
    width: 1px;
    height: 28px;
    background: rgba(255, 255, 255, 0.15);
}

.tool-btn {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    border: none;
    background: transparent;
    color: #bdc1c6;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.tool-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #ffffff;
    transform: translateY(-2px);
}

.tool-btn:active {
    transform: translateY(0);
}

.tool-btn--active {
    background: #8ab4f8 !important;
    color: #202124 !important;
    box-shadow: 0 4px 12px rgba(138, 180, 248, 0.3);
}

.tool-btn--danger:hover {
    background: rgba(234, 67, 53, 0.15);
    color: #f28b82;
}

.color-btn {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid transparent;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: inset 0 0 0 1px rgba(0, 0, 0, 0.1);
}

.color-btn:hover {
    transform: scale(1.25);
    box-shadow: 0 0 12px rgba(255, 255, 255, 0.2);
}

.color-btn--active {
    border-color: #ffffff;
    transform: scale(1.15);
    box-shadow: 0 0 16px rgba(255, 255, 255, 0.3);
}
</style>
