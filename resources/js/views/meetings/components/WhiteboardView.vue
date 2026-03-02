<template>
    <div
        ref="container"
        class="whiteboard-view"
        :class="{ 
            'is-drawing': isDrawing, 
            'is-selecting': currentTool === 'select',
            'is-filling': currentTool === 'fill' 
        }"
        :style="{ background: whiteboardStore.backgroundColor }"
        @mousedown="handleMouseDown"
        @mousemove="handleMouseMove"
        @mouseup="handleMouseUp"
        @mouseleave="handleMouseUp"
        @touchstart.passive="handleTouchStart"
        @touchmove.passive="handleTouchMove"
        @touchend.passive="handleMouseUp"
    >
        <!-- Floating Toolbar -->
        <div class="whiteboard-toolbar shadow-2xl">
            <div class="toolbar-drag-handle">
                <GripVertical size="16" />
            </div>

            <!-- Tool Selection -->
            <div class="tool-group">
                <button
                    v-for="tool in mainTools"
                    :key="tool.id"
                    class="tool-btn"
                    :class="{ active: currentTool === tool.id }"
                    @click="setTool(tool.id)"
                    :title="tool.name"
                >
                    <Icon :name="tool.icon" size="18" />
                </button>

                <!-- Shapes Bundle -->
                <div class="relative">
                    <button
                        class="tool-btn"
                        :class="{ active: ['rect', 'circle', 'arrow', 'x-mark', 'check'].includes(currentTool) }"
                        @click="showShapesBundle = !showShapesBundle"
                        title="Shapes"
                    >
                        <Icon :name="currentShapeIcon" size="18" />
                    </button>
                    <div v-if="showShapesBundle" class="shapes-bundle glass-panel shadow-2xl" v-click-outside="() => showShapesBundle = false">
                        <button
                            v-for="shape in shapeTools"
                            :key="shape.id"
                            class="tool-btn"
                            :class="{ active: currentTool === shape.id }"
                            @click="selectShape(shape.id)"
                            :title="shape.name"
                        >
                            <Icon :name="shape.icon" size="18" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="tool-divider"></div>

            <!-- Stickers Picker -->
            <div class="relative">
                <button
                    class="tool-btn"
                    :class="{ active: showStickers }"
                    @click="showStickers = !showStickers"
                    title="Stickers"
                >
                    <Icon name="sticky-note" size="18" />
                </button>
                <div v-if="showStickers" class="sticker-picker glass-panel shadow-2xl" v-click-outside="() => showStickers = false">
                    <button
                        v-for="sticker in stickerList"
                        :key="sticker"
                        class="sticker-item"
                        @click="addSticker(sticker)"
                    >
                        {{ sticker }}
                    </button>
                </div>
            </div>

            <div class="tool-divider"></div>

            <!-- Color Palette & Board Color -->
            <div class="flex items-center gap-3">
                <div class="color-palette">
                    <button
                        v-for="color in colors"
                        :key="color"
                        class="color-btn"
                        :style="{ backgroundColor: color }"
                        :class="{ active: currentColor === color }"
                        @click="currentColor = color"
                    />
                    <!-- Custom Color Picker -->
                    <div class="relative group">
                        <button 
                            class="color-btn custom-color-btn" 
                            :class="{ active: !colors.includes(currentColor) }"
                            :style="{ backgroundColor: !colors.includes(currentColor) ? currentColor : 'transparent' }"
                        >
                            <Icon name="plus" size="12" />
                            <input 
                                type="color" 
                                v-model="currentColor" 
                                class="absolute inset-0 opacity-0 cursor-pointer"
                                title="Custom Color"
                            />
                        </button>
                    </div>
                </div>
                
                <div class="tool-divider"></div>
                
                <div class="relative">
                    <button 
                        class="board-color-preview" 
                        :style="{ backgroundColor: whiteboardStore.backgroundColor }" 
                        @click="showBoardColors = !showBoardColors"
                        title="Board Color" 
                    />
                    <div v-if="showBoardColors" class="board-color-picker glass-panel active" v-click-outside="() => showBoardColors = false">
                        <button
                            v-for="color in boardColors"
                            :key="color"
                            class="color-btn"
                            :style="{ backgroundColor: color }"
                            :class="{ active: whiteboardStore.backgroundColor === color }"
                            @click="selectBoardColor(color)"
                        />
                    </div>
                </div>
            </div>

            <div class="tool-divider"></div>

            <!-- Actions -->
            <div class="tool-group">
                <button
                    class="tool-btn"
                    :disabled="whiteboardStore.elements.length === 0"
                    @click="whiteboardStore.undo"
                    title="Undo"
                >
                    <Icon name="undo-2" size="18" />
                </button>
                <button
                    class="tool-btn"
                    @click="whiteboardStore.clear"
                    title="Clear All"
                >
                    <Icon name="trash-2" size="18" />
                </button>
                <div class="relative">
                    <button
                        class="tool-btn"
                        :class="{ active: showExportOptions }"
                        @click="showExportOptions = !showExportOptions"
                        title="Export Options"
                    >
                        <Icon name="download" size="18" />
                    </button>
                    <div v-if="showExportOptions" class="export-options glass-panel shadow-2xl" v-click-outside="() => showExportOptions = false">
                        <button class="menu-item" @click="exportBoard('flat')">
                            <Icon name="file-image" size="14" />
                            <span>Transparent (Flat)</span>
                        </button>
                        <button class="menu-item" @click="exportBoard('bg')">
                            <Icon name="palette" size="14" />
                            <span>With Background</span>
                        </button>
                        <button class="menu-item" @click="exportBoard('grid')">
                            <Icon name="grid-3x3" size="14" />
                            <span>Interlaced (Grid)</span>
                        </button>
                    </div>
                </div>
                <div class="relative">
                    <button
                        class="tool-btn"
                        @click="$refs.imageInput.click()"
                        title="Import Image"
                    >
                        <Icon name="image" size="18" />
                    </button>
                    <input 
                        type="file" 
                        ref="imageInput" 
                        class="hidden" 
                        accept="image/*" 
                        @change="handleImageUpload"
                    />
                </div>
            </div>

            <div class="tool-divider"></div>

            <div class="tool-group" v-if="meetingStore.isHost">
                <button
                    class="tool-btn"
                    :class="{ active: whiteboardStore.scope === 'host' }"
                    @click="whiteboardStore.setScope(whiteboardStore.scope === 'global' ? 'host' : 'global')"
                    :title="whiteboardStore.scope === 'global' ? 'Unlock Whiteboard (Global)' : 'Lock Whiteboard (Host Only)'"
                >
                    <Icon :name="whiteboardStore.scope === 'global' ? 'unlock' : 'lock'" size="18" />
                </button>
            </div>

            <div class="tool-divider" v-if="meetingStore.isHost"></div>

            <button class="tool-btn close-btn" @click="whiteboardStore.isVisible = false">
                <Icon name="x" size="18" />
            </button>
        </div>

        <!-- Text Input Overlay -->
        <div
            v-if="isEditingText"
            class="text-input-overlay"
            :style="{ left: textPos.x + 'px', top: textPos.y + 'px' }"
        >
            <textarea
                ref="textRef"
                v-model="textValue"
                class="whiteboard-textarea"
                :style="{ color: currentColor, fontSize: '24px' }"
                @blur="finishText"
                @keydown.enter.prevent="finishText"
                @keydown.esc="cancelText"
            ></textarea>
        </div>

        <!-- Context Menu -->
        <div 
            v-if="showContextMenu" 
            class="whiteboard-context-menu shadow-2xl glass-panel" 
            :style="{ left: menuPos.x + 'px', top: menuPos.y + 'px' }"
            v-click-outside="() => showContextMenu = false"
        >
            <div class="menu-header px-3 py-1.5 text-xs text-gray-400 border-b border-white/10 uppercase tracking-wider">
                Object Layers
            </div>
            <button class="menu-item" @click="handleReorder('front')">
                <ChevronsUp size="16" />
                <span>Bring to Front</span>
            </button>
            <button class="menu-item" @click="handleReorder('forward')">
                <ChevronUp size="16" />
                <span>Bring Forward</span>
            </button>
            <button class="menu-item" @click="handleReorder('backward')">
                <ChevronDown size="16" />
                <span>Send Backward</span>
            </button>
            <button class="menu-item" @click="handleReorder('back')">
                <ChevronsDown size="16" />
                <span>Send to Back</span>
            </button>
            <div class="menu-divider"></div>
            <button class="menu-item text-red-400 hover:bg-red-500/20" @click="handleDeleteSelected">
                <Trash2 size="16" />
                <span>Delete</span>
            </button>
        </div>

        <!-- Konva Canvas -->
        <v-stage 
            ref="stage" 
            :config="stageConfig" 
            @mousedown="handleStageMouseDown" 
            @touchstart="handleStageMouseDown"
            @contextmenu="handleContextMenu"
        >
            <v-layer ref="layer">
                <template v-for="el in displayElements" :key="el.id">
                    <!-- Lines -->
                    <v-line
                        v-if="el.type === 'line'"
                        :config="{ ...el, name: 'element' }"
                        @dragend="handleDragEnd"
                        @transformend="handleTransformEnd"
                        @click="handleElementClick"
                        @tap="handleElementClick"
                    />
                    <!-- Rects -->
                    <v-rect
                        v-else-if="el.type === 'rect'"
                        :config="{ ...el, name: 'element' }"
                        @dragend="handleDragEnd"
                        @transformend="handleTransformEnd"
                        @click="handleElementClick"
                        @tap="handleElementClick"
                    />
                    <!-- Images -->
                    <v-image
                        v-else-if="el.type === 'image'"
                        :config="getImageConfig(el)"
                        @dragend="handleDragEnd"
                        @transformend="handleTransformEnd"
                        @click="handleElementClick"
                        @tap="handleElementClick"
                    />
                    <!-- Circles -->
                    <v-circle
                        v-else-if="el.type === 'circle'"
                        :config="{ ...el, name: 'element' }"
                        @dragend="handleDragEnd"
                        @transformend="handleTransformEnd"
                        @click="handleElementClick"
                        @tap="handleElementClick"
                    />
                    <!-- Arrows -->
                    <v-arrow
                        v-else-if="el.type === 'arrow'"
                        :config="{ ...el, name: 'element' }"
                        @dragend="handleDragEnd"
                        @transformend="handleTransformEnd"
                        @click="handleElementClick"
                        @tap="handleElementClick"
                    />
                    <!-- Cross (X) -->
                    <v-line
                        v-else-if="el.type === 'x-mark'"
                        :config="{ ...el, name: 'element' }"
                        @dragend="handleDragEnd"
                        @transformend="handleTransformEnd"
                        @click="handleElementClick"
                        @tap="handleElementClick"
                    />
                    <!-- Check -->
                    <v-line
                        v-else-if="el.type === 'check'"
                        :config="{ ...el, name: 'element' }"
                        @dragend="handleDragEnd"
                        @transformend="handleTransformEnd"
                        @click="handleElementClick"
                        @tap="handleElementClick"
                    />
                    <!-- Text/Stickers -->
                    <v-text
                        v-else-if="el.type === 'text' || el.type === 'sticker'"
                        :config="{ ...el, name: 'element', draggable: currentTool === 'select' }"
                        @dragend="handleDragEnd"
                        @transformend="handleTransformEnd"
                        @click="handleElementClick"
                        @tap="handleElementClick"
                    />
                </template>

                <!-- Presence Indicators (Remote Cursors) -->
                <v-group 
                    v-for="[id, coll] in Array.from(whiteboardStore.collaborators)" 
                    :key="'cursor-' + id"
                    :config="{ x: coll.x * stageConfig.width, y: coll.y * stageConfig.height }"
                >
                    <v-line 
                        :config="{ 
                            points: [0, 0, 0, 15, 12, 12, 0, 0], 
                            fill: coll.color, 
                            stroke: '#ffffff', 
                            strokeWidth: 1,
                            closed: true
                        }" 
                    />
                    <v-text 
                        :config="{ 
                            text: coll.name, 
                            fontSize: 12, 
                            fill: '#ffffff', 
                            x: 10, 
                            y: 15,
                            fontStyle: 'bold'
                        }" 
                    />
                    <v-rect 
                        :config="{ 
                            x: 8, 
                            y: 13, 
                            width: (coll.name.length * 7) + 8, 
                            height: 16, 
                            fill: coll.color, 
                            cornerRadius: 4,
                            listening: false 
                        }" 
                    />
                    <!-- Re-render text on top of label rect -->
                    <v-text 
                        :config="{ 
                            text: coll.name, 
                            fontSize: 11, 
                            fill: '#000000', 
                            x: 12, 
                            y: 16,
                            fontStyle: 'bold',
                            listening: false
                        }" 
                    />
                </v-group>

                <!-- Current Drawing Preview -->
                <v-line v-if="currentDrawingPreview && currentDrawingPreview.type === 'line'" :config="currentDrawingPreview" />
                <v-rect v-if="currentDrawingPreview && currentDrawingPreview.type === 'rect'" :config="currentDrawingPreview" />
                <v-circle v-if="currentDrawingPreview && currentDrawingPreview.type === 'circle'" :config="currentDrawingPreview" />
                <v-arrow v-if="currentDrawingPreview && currentDrawingPreview.type === 'arrow'" :config="currentDrawingPreview" />
                <v-line v-if="currentDrawingPreview && (currentDrawingPreview.type === 'x-mark' || currentDrawingPreview.type === 'check')" :config="currentDrawingPreview" />

                <!-- Selection Transformer -->
                <v-transformer
                    ref="transformerConfig"
                    :config="{
                        rotateEnabled: true,
                        enabledAnchors: ['top-left', 'top-right', 'bottom-left', 'bottom-right'],
                        boundBoxFunc: (oldBox, newBox) => {
                            if (newBox.width < 5 || newBox.height < 5) return oldBox;
                            return newBox;
                        }
                    }"
                />
            </v-layer>
        </v-stage>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import Konva from 'konva';
import { useWhiteboardStore, type WhiteboardElement } from '@/stores/whiteboard';
import { Icon } from '@/components/ui';
import { 
    GripVertical, 
    ChevronUp, 
    ChevronDown, 
    ChevronsUp, 
    ChevronsDown, 
    Trash2, 
    Image as ImageIcon,
    Lock,
    Unlock
} from 'lucide-vue-next';
import { useMeetingStore } from '@/stores/meeting'; // Assuming this store exists for meeting context

const whiteboardStore = useWhiteboardStore();
const meetingStore = useMeetingStore(); // Assuming this store exists for meeting context
const container = ref<HTMLElement | null>(null);
const stage = ref<any>(null);
const layer = ref<any>(null);
const transformerConfig = ref<any>(null);
const imageInput = ref<HTMLInputElement | null>(null);

const showContextMenu = ref(false);
const menuPos = ref({ x: 0, y: 0 });

const isDrawing = ref(false);
const showStickers = ref(false);
const showBoardColors = ref(false);
const showShapesBundle = ref(false);
const showExportOptions = ref(false);

// Tool State
const currentTool = ref<'select' | 'brush' | 'rect' | 'circle' | 'text' | 'eraser' | 'fill'>('brush');
const currentColor = ref('#8ab4f8');
const currentWidth = ref(4);

// Text Tool State
const isEditingText = ref(false);
const textValue = ref('');
const textPos = ref({ x: 0, y: 0 });
const textRef = ref<HTMLTextAreaElement | null>(null);

const currentDrawingElement = ref<WhiteboardElement | null>(null);

const mainTools = [
    { id: 'select', name: 'Select', icon: 'mouse-pointer-2' },
    { id: 'brush', name: 'Pencil', icon: 'pencil' },
    { id: 'fill', name: 'Paint Bucket', icon: 'paint-bucket' },
    { id: 'text', name: 'Text', icon: 'type' },
    { id: 'eraser', name: 'Eraser', icon: 'eraser' },
] as const;

const shapeTools = [
    { id: 'rect', name: 'Rectangle', icon: 'square' },
    { id: 'circle', name: 'Circle', icon: 'circle' },
    { id: 'arrow', name: 'Arrow', icon: 'move-up-right' },
    { id: 'x-mark', name: 'Cross', icon: 'x' },
    { id: 'check', name: 'Check', icon: 'check' },
] as const;

const currentShapeIcon = computed(() => {
    const activeShape = shapeTools.find(s => s.id === currentTool.value);
    return activeShape ? activeShape.icon : 'shapes';
});

const colors = [
    '#ffffff',
    '#8ab4f8', // Blue
    '#81c995', // Green
    '#fdd663', // Yellow
    '#f28b82', // Red
    '#c58af9', // Purple
    '#ff8bcb', // Pink
];

const boardColors = [
    '#202124', // Default Dark
    '#ffffff', // White
    '#f8f9fa', // Slate 50
    '#e8eaed', // Slate 200
    '#1a1b1e', // Black
    '#fefcbf', // Yellow note
    '#c6f6d5', // Green note
];

const stickerList = ['🚀', '⭐️', '🔥', '💡', '✅', '❌', '💯', '👎', '⚠️', '🎉', '📌', '❤️', '👍'];

const stageConfig = reactive({
    width: 0,
    height: 0,
});

// Custom v-click-outside directive
const vClickOutside = {
    mounted(el: any, binding: any) {
        el.clickOutsideEvent = (event: any) => {
            if (!(el === event.target || el.contains(event.target))) {
                binding.value(event);
            }
        };
        document.addEventListener("mousedown", el.clickOutsideEvent);
    },
    unmounted(el: any) {
        document.removeEventListener("mousedown", el.clickOutsideEvent);
    },
};

const setTool = (tool: typeof currentTool.value) => {
    currentTool.value = tool;
    if (tool !== 'select') {
        whiteboardStore.selectedId = null;
        updateTransformer();
    }
};

const selectShape = (tool: typeof currentTool.value) => {
    setTool(tool);
    showShapesBundle.value = false;
};

const selectBoardColor = (color: string) => {
    whiteboardStore.setBackgroundColor(color);
    showBoardColors.value = false;
};

const updateSize = () => {
    if (container.value) {
        stageConfig.width = container.value.offsetWidth;
        stageConfig.height = container.value.offsetHeight;
    }
};

let resizeObserver: ResizeObserver | null = null;

onMounted(() => {
    updateSize();
    resizeObserver = new ResizeObserver(updateSize);
    if (container.value) resizeObserver.observe(container.value);
    
    // Safety check for large meetings
    if (meetingStore.isHost && meetingStore.allParticipants.length > 50) {
        whiteboardStore.setScope('host');
    }

    whiteboardStore.requestSync();
});

onUnmounted(() => {
    resizeObserver?.disconnect();
});

// Helper to map a single element for display
const mapElementForDisplay = (el: WhiteboardElement, w: number, h: number) => {
    const config: any = {
        ...el,
        id: el.id,
        x: (el.nx || 0) * w,
        y: (el.ny || 0) * h,
        draggable: currentTool.value === 'select' && !isDrawing.value,
        scaleX: el.scaleX || 1,
        scaleY: el.scaleY || 1,
        rotation: el.rotation || 0,
    };

    if (el.type === 'line') {
        config.points = el.normalizedPoints?.map((p, i) => (i % 2 === 0 ? p * w : p * h));
    } else if (el.type === 'rect') {
        config.width = (el.nw || 0) * w;
        config.height = (el.nh || 0) * h;
    } else if (el.type === 'circle') {
        config.radius = (el.nr || 0) * Math.min(w, h);
    } else if (el.type === 'image') {
        config.width = (el.nw || 0.3) * w;
        config.height = (el.nh || 0.3) * h;
    } else if (el.type === 'arrow' || el.type === 'x-mark' || el.type === 'check') {
        const points = el.normalizedPoints?.map((p, i) => (i % 2 === 0 ? p * w : p * h)) || [];
        config.points = points;
        if (el.type === 'arrow') {
            config.pointerLength = 10;
            config.pointerWidth = 10;
            // For arrows, treat fill as the pointer color
            config.fill = el.fill || el.stroke;
        }
    } else if (el.type === 'text' || el.type === 'sticker') {
        config.fontSize = (el.nSize || 0.05) * h;
    }
    return config;
};

const displayElements = computed(() => {
    const { width: w, height: h } = stageConfig;
    if (w === 0 || h === 0) return [];
    return whiteboardStore.elements.map(el => mapElementForDisplay(el, w, h));
});

const currentDrawingPreview = computed(() => {
    if (!currentDrawingElement.value) return null;
    return mapElementForDisplay(currentDrawingElement.value, stageConfig.width, stageConfig.height);
});

// Image Cache for v-image
const imageObjects = new Map<string, HTMLImageElement>();
const getImageConfig = (el: WhiteboardElement) => {
    const displayConfig = mapElementForDisplay(el, stageConfig.width, stageConfig.height);
    
    if (!imageObjects.has(el.id)) {
        const img = new window.Image();
        img.src = el.src;
        img.onload = () => {
            // Trigger re-render once image is loaded
            layer.value?.getNode().batchDraw();
        };
        imageObjects.set(el.id, img);
    }
    
    return {
        ...displayConfig,
        image: imageObjects.get(el.id)
    };
};

// Selection & Transformer Logic
const updateTransformer = () => {
    const transformerNode = transformerConfig.value?.getNode();
    if (!transformerNode) return;

    if (!whiteboardStore.selectedId) {
        transformerNode.nodes([]);
    } else {
        const stageInstance = stage.value.getStage();
        const selectedNode = stageInstance.findOne('#' + whiteboardStore.selectedId);
        if (selectedNode) {
            transformerNode.nodes([selectedNode]);
        } else {
            transformerNode.nodes([]);
        }
    }
    transformerNode.getLayer()?.batchDraw();
};

watch(() => whiteboardStore.selectedId, () => {
    setTimeout(updateTransformer, 0);
});

const handleStageMouseDown = (e: any) => {
    if (!whiteboardStore.canDraw) {
        // Optional: Show toast or minor feedback
        return;
    }
    // Handle context menu click away
    if (showContextMenu.value) {
        showContextMenu.value = false;
        return;
    }
    if (currentTool.value !== 'select' && currentTool.value !== 'fill') return;

    // Deselect if clicking on empty space in select mode
    const stageInstance = e.target.getStage();
    if (e.target === stageInstance) {
        whiteboardStore.selectedId = null;
        updateTransformer();
        return;
    }
};

const handleContextMenu = (e: any) => {
    e.evt.preventDefault();
    
    const stageInstance = e.target.getStage();
    const pointerPos = stageInstance.getPointerPosition();
    
    // Check if we clicked on an element
    const clickedNode = e.target;
    if (clickedNode === stageInstance) {
        showContextMenu.value = false;
        return;
    }

    // Select the element if not already selected
    const id = clickedNode.id();
    if (id) {
        whiteboardStore.selectedId = id;
        updateTransformer();
        
        menuPos.value = { 
            x: pointerPos.x, 
            y: pointerPos.y 
        };
        showContextMenu.value = true;
    }
};

const handleReorder = (action: 'front' | 'back' | 'forward' | 'backward') => {
    if (whiteboardStore.selectedId) {
        whiteboardStore.reorderElement(whiteboardStore.selectedId, action);
        showContextMenu.value = false;
        // Re-update transformer after reorder because node might have moved in DOM/Layer
        setTimeout(updateTransformer, 50);
    }
};

const handleDeleteSelected = () => {
    if (whiteboardStore.selectedId) {
        const id = whiteboardStore.selectedId;
        const newElements = whiteboardStore.elements.filter(el => el.id !== id);
        whiteboardStore.updateElements(newElements);
        whiteboardStore.selectedId = null;
        showContextMenu.value = false;
        updateTransformer();
    }
};

const handleElementClick = (e: any) => {
    // Stop propagation to prevent stage mousedown from deselecting immediately
    e.cancelBubble = true;
    
    const id = e.target.id();
    
    // Paint Bucket logic
    if (currentTool.value === 'fill') {
        if (!whiteboardStore.canDraw) return;
        const className = e.target.getClassName();
        let fillProperty = 'fill';
        if (className === 'Line' || className === 'Arrow') {
            fillProperty = 'stroke';
        }
        
        whiteboardStore.updateElement(id, { [fillProperty]: currentColor.value });
        // Also update fill for Arrow if it's the target
        if (className === 'Arrow') {
            whiteboardStore.updateElement(id, { fill: currentColor.value }, true);
        }
        
        whiteboardStore.saveToHistory();
        return;
    }

    // Select Tool logic
    if (currentTool.value === 'select') {
        whiteboardStore.selectedId = id;
        updateTransformer();
    }
};

// Drawing Interaction
const getPointerPosition = (e: any) => {
    const stageInstance = stage.value.getStage();
    return stageInstance.getPointerPosition();
};

const handleMouseDown = (e: any) => {
    if (!whiteboardStore.canDraw) return;
    if (e.target instanceof HTMLButtonElement || e.target instanceof HTMLTextAreaElement) return;
    if (currentTool.value === 'select' || currentTool.value === 'fill') return;
    if (isEditingText.value) return;

    const pos = getPointerPosition(e);
    
    if (currentTool.value === 'text') {
        isEditingText.value = true;
        textPos.value = pos;
        textValue.value = '';
        setTimeout(() => textRef.value?.focus(), 50);
        return;
    }

    isDrawing.value = true;
    const common = {
        id: 'el_' + Math.random().toString(36).substr(2, 9),
        stroke: currentTool.value === 'eraser' ? whiteboardStore.backgroundColor : currentColor.value,
        strokeWidth: currentWidth.value,
        globalCompositeOperation: currentTool.value === 'eraser' ? 'destination-out' : 'source-over',
    };

    if (currentTool.value === 'brush' || currentTool.value === 'eraser') {
        // For lines, start at (pos.x, pos.y) but points are relative to it
        currentDrawingElement.value = {
            ...common,
            type: 'line',
            nx: pos.x / stageConfig.width,
            ny: pos.y / stageConfig.height,
            points: [0, 0],
            normalizedPoints: [0, 0],
            lineCap: 'round',
            lineJoin: 'round',
            tension: 0, // Set to 0 to prevent the "lasso" / bending effect
        };
    } else if (currentTool.value === 'rect') {
        currentDrawingElement.value = {
            ...common,
            type: 'rect',
            nx: pos.x / stageConfig.width,
            ny: pos.y / stageConfig.height,
            nw: 0,
            nh: 0,
            fill: 'transparent',
        };
    } else if (currentTool.value === 'circle') {
        currentDrawingElement.value = {
            ...common,
            type: 'circle',
            nx: pos.x / stageConfig.width,
            ny: pos.y / stageConfig.height,
            nr: 0,
            fill: 'transparent',
        };
    } else if (currentTool.value === 'arrow') {
        currentDrawingElement.value = {
            ...common,
            type: 'arrow',
            nx: pos.x / stageConfig.width,
            ny: pos.y / stageConfig.height,
            points: [0, 0, 0, 0],
            normalizedPoints: [0, 0, 0, 0],
        };
    } else if (currentTool.value === 'x-mark') {
        currentDrawingElement.value = {
            ...common,
            type: 'x-mark',
            nx: pos.x / stageConfig.width,
            ny: pos.y / stageConfig.height,
            points: [0, 0, 0, 0, 0, 0, 0, 0],
            normalizedPoints: [0, 0, 0, 0, 0, 0, 0, 0],
        };
    } else if (currentTool.value === 'check') {
        currentDrawingElement.value = {
            ...common,
            type: 'check',
            nx: pos.x / stageConfig.width,
            ny: pos.y / stageConfig.height,
            points: [0, 0, 0, 0, 0, 0],
            normalizedPoints: [0, 0, 0, 0, 0, 0],
        };
    }
};

let lastCursorUpdate = 0;
const CURSOR_THROTTLE = 100; // ms

const handleMouseMove = (e: any) => {
    if (!whiteboardStore.canDraw) return;
    // Send cursor position updates
    const now = Date.now();
    if (now - lastCursorUpdate > CURSOR_THROTTLE) {
        const stageInstance = stage.value?.getStage();
        if (stageInstance) {
            const pos = stageInstance.getPointerPosition();
            if (pos) {
                whiteboardStore.sendCursorMove(
                    pos.x / stageConfig.width, 
                    pos.y / stageConfig.height,
                    currentColor.value
                );
                lastCursorUpdate = now;
            }
        }
    }

    if (!isDrawing.value || !currentDrawingElement.value) return;
    const pos = getPointerPosition(e);
    const last = currentDrawingElement.value;

    if (last.type === 'line') {
        const dx = pos.x / stageConfig.width - (last.nx || 0);
        const dy = pos.y / stageConfig.height - (last.ny || 0);
        last.normalizedPoints = (last.normalizedPoints || []).concat([dx, dy]);
    } else if (last.type === 'rect') {
        last.nw = pos.x / stageConfig.width - (last.nx || 0);
        last.nh = pos.y / stageConfig.height - (last.ny || 0);
    } else if (last.type === 'circle') {
        const dx = pos.x / stageConfig.width - (last.nx || 0);
        const dy = pos.y / stageConfig.height - (last.ny || 0);
        last.nr = Math.sqrt(dx * dx + dy * dy);
    } else if (last.type === 'arrow') {
        const dx = pos.x / stageConfig.width - (last.nx || 0);
        const dy = pos.y / stageConfig.height - (last.ny || 0);
        last.normalizedPoints = [0, 0, dx, dy];
    } else if (last.type === 'x-mark') {
        const dx = pos.x / stageConfig.width - (last.nx || 0);
        const dy = pos.y / stageConfig.height - (last.ny || 0);
        // Draw two crossing lines relative to center
        last.normalizedPoints = [-dx/2, -dy/2, dx/2, dy/2, -dx/2, dy/2, dx/2, -dy/2];
    } else if (last.type === 'check') {
        const dx = pos.x / stageConfig.width - (last.nx || 0);
        const dy = pos.y / stageConfig.height - (last.ny || 0);
        // Standard checkmark tick relative to start
        last.normalizedPoints = [0, 0, dx * 0.4, dy * 0.4, dx, -dy * 0.2];
    }
};

const handleMouseUp = () => {
    if (!whiteboardStore.canDraw) return;
    if (!isDrawing.value || !currentDrawingElement.value) return;
    isDrawing.value = false;
    whiteboardStore.addElement({ ...currentDrawingElement.value });
    currentDrawingElement.value = null;
};

// Text Tool Logic
const finishText = () => {
    if (!whiteboardStore.canDraw) {
        cancelText();
        return;
    }
    if (!isEditingText.value || !textValue.value.trim()) {
        cancelText();
        return;
    }
    const newText: WhiteboardElement = {
        type: 'text',
        id: 'text_' + Math.random().toString(36).substr(2, 9),
        text: textValue.value,
        nx: textPos.value.x / stageConfig.width,
        ny: textPos.value.y / stageConfig.height,
        fill: currentColor.value,
        nSize: 24 / stageConfig.height,
        fontStyle: 'bold',
    };
    whiteboardStore.addElement(newText);
    cancelText();
};

const cancelText = () => {
    isEditingText.value = false;
    textValue.value = "";
};

// Sticker Logic
const addSticker = (sticker: string) => {
    if (!whiteboardStore.canDraw) return;
    const center = { x: stageConfig.width / 2, y: stageConfig.height / 2 };
    const newSticker: WhiteboardElement = {
        type: 'sticker',
        id: 'stick_' + Math.random().toString(36).substr(2, 9),
        text: sticker,
        nx: center.x / stageConfig.width,
        ny: center.y / stageConfig.height,
        nSize: 0.1, // 10% of height
    };
    whiteboardStore.addElement(newSticker);
    showStickers.value = false;
    setTool('select');
    whiteboardStore.selectedId = newSticker.id;
};

// Image Import Logic
const handleImageUpload = (e: Event) => {
    if (!whiteboardStore.canDraw) return;
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;

    // Security: Only images, and size limit (e.g., 2MB for Data URL sync stability)
    if (!file.type.startsWith('image/')) return;
    if (file.size > 2 * 1024 * 1024) {
        alert("Image too large (Max 2MB for whiteboard)");
        return;
    }

    const reader = new FileReader();
    reader.onload = (event) => {
        const dataUrl = event.target?.result as string;
        if (!dataUrl) return;

        const img = new window.Image();
        img.onload = () => {
            const stageW = stageConfig.width;
            const stageH = stageConfig.height;
            
            // Max size is 30% of stage
            const maxW = 0.3;
            const maxH = 0.3;
            
            const imgAspect = img.width / img.height;
            const stageAspect = stageW / stageH;
            
            let nw, nh;
            if (imgAspect > stageAspect) {
                // Image is wider than stage relatively
                nw = maxW;
                nh = (nw * (stageW / stageH)) / imgAspect;
            } else {
                // Image is taller
                nh = maxH;
                nw = (nh * (stageH / stageW)) * imgAspect;
            }

            const newImage: WhiteboardElement = {
                type: 'image',
                id: 'img_' + Math.random().toString(36).substr(2, 9),
                src: dataUrl,
                nx: 0.1,
                ny: 0.1,
                nw,
                nh,
            };

            whiteboardStore.addElement(newImage);
            whiteboardStore.selectedId = newImage.id;
            setTool('select');
        };
        img.src = dataUrl;
    };
    reader.readAsDataURL(file);
    
    // Clear input
    if (imageInput.value) imageInput.value.value = '';
};

// Drag/Transform Synchronization
const handleDragEnd = (e: any) => {
    if (!whiteboardStore.canDraw) return;
    const node = e.target;
    whiteboardStore.updateElement(node.id(), {
        nx: node.x() / stageConfig.width,
        ny: node.y() / stageConfig.height,
    });
    whiteboardStore.saveToHistory();
};

const handleTransformEnd = (e: any) => {
    if (!whiteboardStore.canDraw) return;
    const node = e.target;
    whiteboardStore.updateElement(node.id(), {
        nx: node.x() / stageConfig.width,
        ny: node.y() / stageConfig.height,
        scaleX: node.scaleX(),
        scaleY: node.scaleY(),
        rotation: node.rotation(),
    });
    whiteboardStore.saveToHistory();
};

// Export Strategy
const exportBoard = (mode: 'flat' | 'bg' | 'grid' = 'bg') => {
    showExportOptions.value = false;
    const stageInstance = stage.value.getStage();
    const layerInstance = layer.value.getNode();
    
    // Temporarily disable transformer for export
    const oldSelected = whiteboardStore.selectedId;
    whiteboardStore.selectedId = null;
    updateTransformer();

    // Create a temporary background layer
    const tempBG = new Konva.Layer();
    const w = stageConfig.width;
    const h = stageConfig.height;

    if (mode === 'bg' || mode === 'grid') {
        const bgRect = new Konva.Rect({
            x: 0,
            y: 0,
            width: w,
            height: h,
            fill: whiteboardStore.backgroundColor,
        });
        tempBG.add(bgRect);
    }

    if (mode === 'grid') {
        // Draw grid
        const gridSize = 40;
        const color = whiteboardStore.backgroundColor === '#ffffff' ? '#eee' : '#333';
        for (let i = 0; i <= w / gridSize; i++) {
            tempBG.add(new Konva.Line({
                points: [i * gridSize, 0, i * gridSize, h],
                stroke: color,
                strokeWidth: 1,
                opacity: 0.5
            }));
        }
        for (let j = 0; j <= h / gridSize; j++) {
            tempBG.add(new Konva.Line({
                points: [0, j * gridSize, w, j * gridSize],
                stroke: color,
                strokeWidth: 1,
                opacity: 0.5
            }));
        }
    }

    stageInstance.add(tempBG);
    tempBG.moveToBottom();

    setTimeout(() => {
        const dataURL = stageInstance.toDataURL({ 
            pixelRatio: 2,
            callback: (data: string) => {
                const link = document.createElement('a');
                link.download = `whiteboard-${mode}-${Date.now()}.png`;
                link.href = data;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
                // Cleanup
                tempBG.destroy();
                // Restore selection
                whiteboardStore.selectedId = oldSelected;
                updateTransformer();
            }
        });
    }, 50);
};

// Touch support hooks
const handleTouchStart = (e: TouchEvent) => {
    if (!whiteboardStore.canDraw) return;
    if (e.target instanceof HTMLButtonElement || e.target instanceof HTMLTextAreaElement) return;
    handleMouseDown(e);
};

const handleTouchMove = (e: TouchEvent) => {
    if (!whiteboardStore.canDraw) return;
    handleMouseMove(e);
};
</script>

<style scoped>
.whiteboard-view {
    position: absolute;
    inset: 12px;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
    z-index: 50;
    overflow: hidden;
    cursor: crosshair;
    transition: background 0.3s ease;
}

.whiteboard-view.is-selecting {
    cursor: default;
}

.whiteboard-view.is-filling {
    cursor: cell;
}

.is-drawing {
    cursor: crosshair !important;
}

.whiteboard-toolbar {
    position: absolute;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(45, 48, 51, 0.95);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    padding: 8px 12px;
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 100;
}

.toolbar-drag-handle {
    color: #9aa0a6;
    cursor: grab;
    padding-right: 4px;
}

.tool-group {
    display: flex;
    gap: 4px;
}

.tool-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: none;
    background: transparent;
    color: #e8eaed;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.tool-btn:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.08);
}

.tool-btn.active {
    background: #8ab4f8;
    color: #202124;
}

.tool-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.tool-divider {
    width: 1px;
    height: 24px;
    background: rgba(255, 255, 255, 0.1);
}

.color-palette {
    display: flex;
    gap: 6px;
}

.color-btn {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid transparent;
    transition: all 0.2s ease;
    cursor: pointer;
}

.custom-color-btn {
    background: rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
    border: 1px dashed rgba(255, 255, 255, 0.3);
}

.custom-color-btn:hover {
    background: rgba(255, 255, 255, 0.2);
    border-color: rgba(255, 255, 255, 0.5);
}

.color-btn.active {
    border-color: #fff;
    transform: scale(1.2);
}

.board-color-preview {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    border: 2px solid rgba(255, 255, 255, 0.2);
    cursor: pointer;
}

.board-color-picker {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%) translateY(-12px);
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    padding: 10px;
    border-radius: 12px;
    transition: all 0.2s ease;
    opacity: 0;
    pointer-events: none;
}

.board-color-picker.active {
    opacity: 1;
    pointer-events: auto;
    transform: translateX(-50%) translateY(-8px);
}

.close-btn {
    background: rgba(234, 67, 53, 0.1);
    color: #f28b82;
}

.close-btn:hover {
    background: #ea4335 !important;
    color: #fff !important;
}

/* Sticker Picker */
.sticker-picker {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%) translateY(-12px);
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
    padding: 12px;
    background: rgba(45, 48, 51, 0.98);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.sticker-item {
    font-size: 24px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: background 0.2s;
    cursor: pointer;
}

.sticker-item:hover {
    background: rgba(255, 255, 255, 0.1);
}

/* Text Tool Overlay */
.text-input-overlay {
    position: absolute;
    z-index: 200;
}

.whiteboard-textarea {
    background: transparent;
    border: none;
    border-bottom: 2px solid #8ab4f8;
    outline: none;
    padding: 4px;
    font-weight: bold;
    min-width: 150px;
    resize: both;
    line-height: 1.2;
}

.glass-panel {
    background: rgba(45, 48, 51, 0.95);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

/* Context Menu */
.whiteboard-context-menu {
    position: absolute;
    z-index: 1000;
    min-width: 180px;
    border-radius: 12px;
    padding: 6px;
}

.menu-item {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 8px;
    color: #e8eaed;
    font-size: 13px;
    transition: all 0.2s;
    background: transparent;
    border: none;
}

.menu-item:hover {
    background: rgba(255, 255, 255, 0.08);
}

.menu-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.1);
    margin: 4px 0;
}

/* Shapes Bundle */
.shapes-bundle {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%) translateY(-12px);
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 6px;
    border-radius: 12px;
    min-width: 48px;
}

.shapes-bundle .tool-btn {
    width: 40px;
    height: 40px;
}

/* Export Options */
.export-options {
    position: absolute;
    bottom: 100%;
    right: 0;
    transform: translateY(-12px);
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px;
    border-radius: 12px;
    min-width: 180px;
}
</style>
