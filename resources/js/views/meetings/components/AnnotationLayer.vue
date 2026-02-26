<template>
    <div
        ref="container"
        class="annotation-layer"
        @mousedown="handleMouseDown"
        @mousemove="handleMouseMove"
        @mouseup="handleMouseUp"
        @touchstart="handleTouchStart"
        @touchmove="handleTouchMove"
        @touchend="handleMouseUp"
    >
        <!-- Toolbox UI (Teleported to body to prevent clipping) -->
        <Teleport to="body">
            <div
                v-if="isLocal"
                class="toolbox-overlay shadow-2xl border border-(--border-default) bg-(--surface-elevated)/95 backdrop-blur-xl rounded-2xl p-2 flex flex-col gap-2 transition-all duration-300 ring-1 ring-black/5"
                :class="{
                    'toolbox-overlay--collapsed': isCollapsed,
                    'toolbox-overlay--dragging': isDraggingToolbox,
                }"
                :style="{
                    left: toolboxPosition.x + 'px',
                    top: toolboxPosition.y + 'px',
                    transform: 'none',
                }"
            >
                <!-- Top Row: Drag, Tools, History, Actions, Collapse -->
                <div class="flex items-center gap-2">
                    <!-- Drag Handle -->
                    <div
                        class="p-1 cursor-grab active:cursor-grabbing text-(--text-secondary) hover:text-(--brand-primary) transition-colors"
                        @mousedown="startDragToolbox"
                        @touchstart="startDragToolbox"
                    >
                        <GripVertical class="w-4 h-4" />
                    </div>

                    <template v-if="!isCollapsed">
                        <!-- Tool Selection -->
                        <div
                            class="flex items-center bg-(--surface-subtle) rounded-xl p-0.5 gap-0.5"
                        >
                            <button
                                @click="currentTool = 'brush'"
                                class="p-1.5 rounded-lg transition-all"
                                :class="
                                    currentTool === 'brush'
                                        ? 'bg-(--brand-primary) text-white shadow-sm'
                                        : 'text-(--text-secondary) hover:bg-(--surface-hover)'
                                "
                                title="Pencil"
                            >
                                <Pencil class="w-3.5 h-3.5" />
                            </button>
                            <button
                                @click="currentTool = 'rect'"
                                class="p-1.5 rounded-lg transition-all"
                                :class="
                                    currentTool === 'rect'
                                        ? 'bg-(--brand-primary) text-white shadow-sm'
                                        : 'text-(--text-secondary) hover:bg-(--surface-hover)'
                                "
                                title="Rectangle"
                            >
                                <Square class="w-3.5 h-3.5" />
                            </button>
                            <button
                                @click="currentTool = 'circle'"
                                class="p-1.5 rounded-lg transition-all"
                                :class="
                                    currentTool === 'circle'
                                        ? 'bg-(--brand-primary) text-white shadow-sm'
                                        : 'text-(--text-secondary) hover:bg-(--surface-hover)'
                                "
                                title="Circle"
                            >
                                <Circle class="w-3.5 h-3.5" />
                            </button>
                            <button
                                @click="currentTool = 'text'"
                                class="p-1.5 rounded-lg transition-all"
                                :class="
                                    currentTool === 'text'
                                        ? 'bg-(--brand-primary) text-white shadow-sm'
                                        : 'text-(--text-secondary) hover:bg-(--surface-hover)'
                                "
                                title="Text"
                            >
                                <Type class="w-3.5 h-3.5" />
                            </button>
                            <button
                                @click="currentTool = 'eraser'"
                                class="p-1.5 rounded-lg transition-all"
                                :class="
                                    currentTool === 'eraser'
                                        ? 'bg-(--brand-primary) text-white shadow-sm'
                                        : 'text-(--text-secondary) hover:bg-(--surface-hover)'
                                "
                                title="Eraser"
                            >
                                <Eraser class="w-3.5 h-3.5" />
                            </button>
                        </div>

                        <div
                            class="h-5 w-px bg-(--border-default) opacity-50 mc-1"
                        />

                        <!-- History Actions -->
                        <div
                            class="flex items-center bg-(--surface-subtle) rounded-xl p-0.5 gap-0.5"
                        >
                            <button
                                @click="undo"
                                class="p-1.5 rounded-lg text-(--text-secondary) hover:bg-(--surface-hover) disabled:opacity-30"
                                :disabled="history.length === 0"
                                title="Undo"
                            >
                                <Undo2 class="w-3.5 h-3.5" />
                            </button>
                            <button
                                @click="redo"
                                class="p-1.5 rounded-lg text-(--text-secondary) hover:bg-(--surface-hover) disabled:opacity-30"
                                :disabled="redoStack.length === 0"
                                title="Redo"
                            >
                                <Redo2 class="w-3.5 h-3.5" />
                            </button>
                        </div>

                        <div
                            class="h-5 w-px bg-(--border-default) opacity-50 mc-1"
                        />

                        <!-- Clear Button -->
                        <button
                            @click="clearAll"
                            class="p-1.5 rounded-lg text-red-500 hover:bg-red-500/10 transition-colors"
                            title="Clear All"
                        >
                            <Trash2 class="w-3.5 h-3.5" />
                        </button>
                    </template>

                    <div
                        v-if="!isCollapsed"
                        class="h-5 w-px bg-(--border-default) opacity-50 mc-1"
                    />

                    <!-- Collapse/Expand Toggle -->
                    <button
                        @click="isCollapsed = !isCollapsed"
                        class="p-1.5 rounded-lg text-(--text-secondary) hover:bg-(--surface-hover) transition-all"
                        :title="
                            isCollapsed ? 'Expand Toolbox' : 'Collapse Toolbox'
                        "
                    >
                        <ChevronDown v-if="!isCollapsed" class="w-4 h-4" />
                        <PenTool
                            v-else
                            class="w-4 h-4 text-(--brand-primary)"
                        />
                    </button>
                </div>

                <!-- Bottom Row: Colors and Sizes -->
                <div
                    v-if="!isCollapsed"
                    class="flex items-center gap-2 pt-1.5 border-t border-(--border-default)/30 ml-7"
                >
                    <!-- Color Selection -->
                    <div
                        class="flex items-center gap-1.5 bg-(--surface-subtle) rounded-xl p-1"
                    >
                        <button
                            v-for="color in colors"
                            :key="color"
                            @click="currentColor = color"
                            class="w-4 h-4 rounded-full border transition-transform hover:scale-110 active:scale-95"
                            :style="{ backgroundColor: color }"
                            :class="
                                currentColor === color
                                    ? 'border-(--brand-primary) ring-1 ring-(--brand-primary)/30'
                                    : 'border-white/10'
                            "
                        />
                    </div>

                    <div
                        class="h-5 w-px bg-(--border-default) opacity-50 mc-1"
                    />

                    <!-- Stroke Width -->
                    <div
                        class="flex items-center bg-(--surface-subtle) rounded-xl p-0.5 gap-0.5"
                    >
                        <button
                            v-for="size in [2, 4, 8, 12]"
                            :key="size"
                            @click="currentWidth = size"
                            class="p-1.5 rounded-lg transition-all text-(--text-secondary) hover:bg-(--surface-hover)"
                            :class="{
                                'text-(--brand-primary) bg-(--brand-primary)/10 font-bold':
                                    currentWidth === size,
                            }"
                        >
                            <div
                                :style="{
                                    width: Math.min(size, 8) + 'px',
                                    height: Math.min(size, 8) + 'px',
                                }"
                                class="bg-current rounded-full"
                            />
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <v-stage ref="stage" :config="stageConfig">
            <v-layer>
                <template v-for="(el, index) in displayElements" :key="index">
                    <v-line v-if="el.type === 'line'" :config="el" />
                    <v-rect v-else-if="el.type === 'rect'" :config="el" />
                    <v-circle v-else-if="el.type === 'circle'" :config="el" />
                    <v-text v-else-if="el.type === 'text'" :config="el" />
                </template>
            </v-layer>
        </v-stage>

        <!-- Text Input Overlay -->
        <div
            v-if="isEditingText"
            class="absolute z-50 pointer-events-auto"
            :style="{ left: textPosition.x + 'px', top: textPosition.y + 'px' }"
        >
            <input
                ref="textRef"
                v-model="textInput"
                class="bg-transparent border-b-2 border-(--brand-primary) outline-none text-white font-bold p-1 shadow-2xl"
                :style="{
                    color: currentColor,
                    fontSize: currentWidth * 4 + 'px',
                }"
                @blur="finishText"
                @keyup.enter="finishText"
                @keyup.esc="cancelText"
            />
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, onUnmounted, watch, computed } from "vue";
import {
    Pencil,
    Eraser,
    Trash2,
    Square,
    Circle,
    Type,
    Undo2,
    Redo2,
    GripVertical,
    ChevronDown,
    PenTool,
} from "lucide-vue-next";

const props = defineProps<{
    isLocal: boolean;
    participantId: string;
    initialLines?: any[];
}>();

const emit = defineEmits(["update"]);

const container = ref<HTMLElement | null>(null);
const stage = ref<any>(null);
const isDrawing = ref(false);
const elements = ref<any[]>(props.initialLines || []);

// Toolbox State
const currentTool = ref<"brush" | "eraser" | "rect" | "circle" | "text">(
    "brush",
);
const currentColor = ref("#ff0000");
const currentWidth = ref(4);
const colors = [
    "#ffffff",
    "#000000",
    "#ff0000",
    "#00ff00",
    "#0000ff",
    "#f59e0b",
    "#8b5cf6",
];

// History State
const history = ref<string[]>([]); // Storing JSON stringified states for simple undo/redo
const redoStack = ref<string[]>([]);

// Text Tool State
const isEditingText = ref(false);
const textInput = ref("");
const textPosition = ref({ x: 0, y: 0 });
const textRef = ref<HTMLInputElement | null>(null);

const stageConfig = reactive({
    width: 0,
    height: 0,
});

// Toolbox UX State
const isCollapsed = ref(false);
const isDraggingToolbox = ref(false);
const toolboxPosition = ref({ x: 80, y: 240 });

let dragStartX = 0;
let dragStartY = 0;

const startDragToolbox = (e: MouseEvent | TouchEvent) => {
    isDraggingToolbox.value = true;
    const clientX = "touches" in e ? e.touches[0].clientX : e.clientX;
    const clientY = "touches" in e ? e.touches[0].clientY : e.clientY;

    dragStartX = clientX - toolboxPosition.value.x;
    dragStartY = clientY - toolboxPosition.value.y;

    window.addEventListener("mousemove", onDragToolbox);
    window.addEventListener("touchmove", onDragToolbox);
    window.addEventListener("mouseup", stopDragToolbox);
    window.addEventListener("touchend", stopDragToolbox);
};

const onDragToolbox = (e: MouseEvent | TouchEvent) => {
    if (!isDraggingToolbox.value) return;
    const clientX = "touches" in e ? e.touches[0].clientX : e.clientX;
    const clientY = "touches" in e ? e.touches[0].clientY : e.clientY;

    let nextX = clientX - dragStartX;
    let nextY = clientY - dragStartY;

    // Clamping
    nextX = Math.max(10, Math.min(window.innerWidth - 60, nextX));
    nextY = Math.max(10, Math.min(window.innerHeight - 60, nextY));

    toolboxPosition.value = { x: nextX, y: nextY };
};

const stopDragToolbox = () => {
    isDraggingToolbox.value = false;
    window.removeEventListener("mousemove", onDragToolbox);
    window.removeEventListener("touchmove", onDragToolbox);
    window.removeEventListener("mouseup", stopDragToolbox);
    window.removeEventListener("touchend", stopDragToolbox);
};

onUnmounted(stopDragToolbox);

const displayElements = computed(() => {
    const w = stageConfig.width;
    const h = stageConfig.height;

    return elements.value.map((el) => {
        if (el.type === "line") {
            return {
                ...el,
                points: el.normalizedPoints.map((p: number, i: number) =>
                    i % 2 === 0 ? p * w : p * h,
                ),
            };
        } else if (el.type === "rect") {
            return {
                ...el,
                x: el.nx * w,
                y: el.ny * h,
                width: el.nw * w,
                height: el.nh * h,
            };
        } else if (el.type === "circle") {
            const r = el.nr * Math.min(w, h); // Use min for radius scaling
            return {
                ...el,
                x: el.nx * w,
                y: el.ny * h,
                radius: r,
            };
        } else if (el.type === "text") {
            return {
                ...el,
                x: el.nx * w,
                y: el.ny * h,
                fontSize: el.nSize * h,
            };
        }
        return el;
    });
});

const updateSize = () => {
    if (container.value) {
        stageConfig.width = container.value.offsetWidth;
        stageConfig.height = container.value.offsetHeight;
    }
};

let resizeObserver: ResizeObserver | null = null;

onMounted(() => {
    updateSize();
    if (container.value) {
        resizeObserver = new ResizeObserver(() => {
            updateSize();
        });
        resizeObserver.observe(container.value);
    }
});

onUnmounted(() => {
    resizeObserver?.disconnect();
});

watch(
    () => props.initialLines,
    (newElements) => {
        if (!props.isLocal && newElements) {
            elements.value = [...newElements];
        }
    },
    { deep: true, immediate: true },
);

const saveState = () => {
    history.value.push(JSON.stringify(elements.value));
    if (history.value.length > 50) history.value.shift();
    redoStack.value = [];
};

const undo = () => {
    if (history.value.length === 0) return;
    const currentState = JSON.stringify(elements.value);
    redoStack.value.push(currentState);
    const prevState = JSON.parse(history.value.pop()!);
    elements.value = prevState;
    emit("update", { type: "stroke-update", lines: elements.value });
};

const redo = () => {
    if (redoStack.value.length === 0) return;
    history.value.push(JSON.stringify(elements.value));
    const nextState = JSON.parse(redoStack.value.pop()!);
    elements.value = nextState;
    emit("update", { type: "stroke-update", lines: elements.value });
};

const handleMouseDown = (e: any) => {
    if (!props.isLocal || isEditingText.value) return;

    const pos = getPointerPosition(e);
    saveState();
    isDrawing.value = true;

    if (currentTool.value === "text") {
        isDrawing.value = false;
        textPosition.value = pos;
        isEditingText.value = true;
        textInput.value = "";
        setTimeout(() => textRef.value?.focus(), 50);
        return;
    }

    const common = {
        stroke: currentTool.value === "eraser" ? "#ffffff" : currentColor.value,
        strokeWidth: currentWidth.value,
        globalCompositeOperation:
            currentTool.value === "eraser" ? "destination-out" : "source-over",
    };

    if (currentTool.value === "brush" || currentTool.value === "eraser") {
        elements.value.push({
            ...common,
            type: "line",
            points: [pos.x, pos.y],
            normalizedPoints: [
                pos.x / stageConfig.width,
                pos.y / stageConfig.height,
            ],
            lineCap: "round",
            lineJoin: "round",
        });
    } else if (currentTool.value === "rect") {
        elements.value.push({
            ...common,
            type: "rect",
            nx: pos.x / stageConfig.width,
            ny: pos.y / stageConfig.height,
            nw: 0,
            nh: 0,
        });
    } else if (currentTool.value === "circle") {
        elements.value.push({
            ...common,
            type: "circle",
            nx: pos.x / stageConfig.width,
            ny: pos.y / stageConfig.height,
            nr: 0,
        });
    }
};

const handleMouseMove = (e: any) => {
    if (!isDrawing.value || !props.isLocal) return;

    const pos = getPointerPosition(e);
    const last = elements.value[elements.value.length - 1];

    if (last.type === "line") {
        last.points = last.points.concat([pos.x, pos.y]);
        last.normalizedPoints = last.normalizedPoints.concat([
            pos.x / stageConfig.width,
            pos.y / stageConfig.height,
        ]);
    } else if (last.type === "rect") {
        last.nw = pos.x / stageConfig.width - last.nx;
        last.nh = pos.y / stageConfig.height - last.ny;
    } else if (last.type === "circle") {
        const dx = pos.x / stageConfig.width - last.nx;
        const dy = pos.y / stageConfig.height - last.ny;
        last.nr = Math.sqrt(dx * dx + dy * dy);
    }

    elements.value.splice(elements.value.length - 1, 1, { ...last });
};

const handleMouseUp = () => {
    if (!isDrawing.value || !props.isLocal) return;
    isDrawing.value = false;

    const last = { ...elements.value[elements.value.length - 1] };
    if (last.type === "line") {
        delete (last as any).points;
    }

    emit("update", {
        type: "new-stroke",
        stroke: last,
    });
};

const finishText = () => {
    if (!isEditingText.value) return;
    if (textInput.value.trim()) {
        const newText = {
            type: "text",
            text: textInput.value,
            nx: textPosition.value.x / stageConfig.width,
            ny: textPosition.value.y / stageConfig.height,
            fill: currentColor.value,
            nSize: (currentWidth.value * 4) / stageConfig.height,
            fontStyle: "bold",
        };
        elements.value.push(newText);
        emit("update", { type: "new-stroke", stroke: newText });
    }
    isEditingText.value = false;
    textInput.value = "";
};

const cancelText = () => {
    isEditingText.value = false;
    textInput.value = "";
};

const handleTouchStart = (e: TouchEvent) => {
    if (e.target instanceof HTMLButtonElement) return; // Don't draw when clicking buttons
    e.preventDefault();
    handleMouseDown(e);
};

const handleTouchMove = (e: TouchEvent) => {
    e.preventDefault();
    handleMouseMove(e);
};

const getPointerPosition = (e: any) => {
    if (e.touches) {
        const rect = container.value!.getBoundingClientRect();
        return {
            x: e.touches[0].clientX - rect.left,
            y: e.touches[0].clientY - rect.top,
        };
    }
    // Use stage pointer if mouse
    const stageInstance = stage.value.getStage();
    return stageInstance.getPointerPosition();
};

const clearAll = () => {
    saveState();
    elements.value = [];
    emit("update", { type: "clear" });
};
</script>

<style scoped>
.annotation-layer {
    width: 100%;
    height: 100%;
    position: relative;
    overflow: hidden;
}

.toolbox-overlay {
    position: fixed;
    z-index: 10000;
    pointer-events: auto;
    user-select: none;
}

.toolbox-overlay--dragging {
    transition: none !important;
    cursor: grabbing;
}

.toolbox-overlay--collapsed {
    width: auto;
}

button {
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>
