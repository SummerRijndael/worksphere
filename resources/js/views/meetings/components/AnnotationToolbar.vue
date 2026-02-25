<template>
    <div 
        ref="el"
        class="annotation-toolbar" 
        :class="{ 
            'annotation-toolbar--active': isAnnotating,
            'annotation-toolbar--collapsed': isCollapsed,
            'annotation-toolbar--dragging': isDragging 
        }"
        :style="toolbarStyle"
        @click.stop
    >
        <!-- Drag Grip -->
        <div 
            class="drag-grip" 
            @mousedown="startDrag"
            @touchstart="startDrag"
        >
            <Icon name="grip-vertical" size="18" />
        </div>

        <template v-if="!isCollapsed">
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
            
            <div class="tool-divider"></div>
        </template>

        <!-- Toggle Collapse -->
        <button class="tool-btn collapse-btn" @click="isCollapsed = !isCollapsed" :title="isCollapsed ? 'Expand' : 'Collapse'">
            <Icon :name="isCollapsed ? 'chevron-right' : 'chevron-left'" size="18" />
        </button>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Icon } from '@/components/ui';

const props = defineProps<{
    activeTool: string;
    activeColor: string;
    isAnnotating: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:activeTool', tool: string): void;
    (e: 'update:activeColor', color: string): void;
    (e: 'clear'): void;
    (e: 'undo'): void;
}>();

const isCollapsed = ref(false);

// Draggability Logic
const position = ref<{ x: number; y: number } | null>(null); // null means default center-bottom
const isDragging = ref(false);
let startPointerX = 0;
let startPointerY = 0;
let initialElemX = 0;
let initialElemY = 0;

const toolbarStyle = computed(() => {
    if (!position.value) {
        return {
            bottom: '24px',
            left: '50%',
            transform: 'translateX(-50%)'
        };
    }
    return {
        left: `${position.value.x}px`,
        top: `${position.value.y}px`,
        transform: 'none',
        bottom: 'auto'
    };
});

function startDrag(e: MouseEvent | TouchEvent) {
    const el = (e.currentTarget as HTMLElement).closest('.annotation-toolbar');
    if (!el) return;

    isDragging.value = true;
    const clientX = 'touches' in e ? e.touches[0].clientX : e.clientX;
    const clientY = 'touches' in e ? e.touches[0].clientY : e.clientY;
    
    const rect = el.getBoundingClientRect();
    const parentRect = el.parentElement?.getBoundingClientRect() || { left: 0, top: 0 };

    // Record where the pointer is relative to the viewport
    startPointerX = clientX;
    startPointerY = clientY;

    // Record where the element is relative to its offset parent
    initialElemX = rect.left - parentRect.left;
    initialElemY = rect.top - parentRect.top;

    window.addEventListener('mousemove', onDrag);
    window.addEventListener('touchmove', onDrag, { passive: false });
    window.addEventListener('mouseup', stopDrag);
    window.addEventListener('touchend', stopDrag);
}

const el = ref<HTMLElement | null>(null);

function onDrag(e: MouseEvent | TouchEvent) {
    if (!isDragging.value || !el.value) return;
    if ('touches' in e) e.preventDefault(); // Prevent scrolling while dragging

    const clientX = 'touches' in e ? e.touches[0].clientX : e.clientX;
    const clientY = 'touches' in e ? e.touches[0].clientY : e.clientY;
    
    const deltaX = clientX - startPointerX;
    const deltaY = clientY - startPointerY;

    const rect = el.value.getBoundingClientRect();
    const parentRect = el.value.parentElement?.getBoundingClientRect() || { width: window.innerWidth, height: window.innerHeight };

    let newX = initialElemX + deltaX;
    let newY = initialElemY + deltaY;

    // Clamp within parent bounds
    newX = Math.max(0, Math.min(newX, parentRect.width - rect.width));
    newY = Math.max(0, Math.min(newY, parentRect.height - rect.height));

    position.value = {
        x: newX,
        y: newY
    };
}

function stopDrag() {
    isDragging.value = false;
    window.removeEventListener('mousemove', onDrag);
    window.removeEventListener('touchmove', onDrag);
    window.removeEventListener('mouseup', stopDrag);
    window.removeEventListener('touchend', stopDrag);
}

onUnmounted(stopDrag);

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
    background: rgba(32, 33, 36, 0.9);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    z-index: 1000;
    box-shadow: 0 12px 32px rgba(0, 0, 0, 0.5);
    transition: opacity 0.3s ease, transform 0.2s ease;
    user-select: none;
}

.annotation-toolbar--dragging {
    transition: none !important;
}

.annotation-toolbar--collapsed {
    padding: 8px;
    gap: 4px;
}

.drag-grip {
    padding: 4px;
    cursor: grab;
    color: #5f6368;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.2s;
}

.drag-grip:hover {
    color: #bdc1c6;
}

.drag-grip:active {
    cursor: grabbing;
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

.collapse-btn {
    opacity: 0.6;
}

.collapse-btn:hover {
    opacity: 1;
}

@media (max-width: 600px) {
    .annotation-toolbar {
        transform: scale(0.85);
        transform-origin: center bottom;
    }
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
