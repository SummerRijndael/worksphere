<template>
    <div 
        class="annotation-overlay"
        :class="{ 'annotation-overlay--active': isAnnotating }"
    >
        <canvas ref="canvasEl"></canvas>
        
        <!-- Presenter Toolbar -->
        <AnnotationToolbar
            v-if="isLocal && isAnnotating"
            v-model:active-tool="meetingStore.activeAnnotationTool"
            v-model:active-color="meetingStore.activeAnnotationColor"
            @clear="clearCanvas"
            @undo="undoLast"
        />
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, nextTick } from 'vue';
import * as fabric from 'fabric';
import { useMeetingStore } from '@/stores/meeting';
import AnnotationToolbar from './AnnotationToolbar.vue';

const props = defineProps<{
    participantId: string;
    isLocal: boolean;
}>();

const canvasEl = ref<HTMLCanvasElement | null>(null);
let canvas: fabric.Canvas | null = null;
const meetingStore = useMeetingStore();

const isAnnotating = ref(false);

// Initialize Fabric
let resizeObserver: ResizeObserver | null = null;

onMounted(async () => {
    if (!canvasEl.value) return;

    await nextTick();

    // Ensure we have a valid size before initializing
    const initialParent = canvasEl.value.parentElement;
    if (initialParent && (initialParent.clientWidth === 0 || initialParent.clientHeight === 0)) {
        // Wait bit longer or wait for first resize
        console.log('[AnnotationOverlay] Parent size is 0, waiting for resize observer...');
    }

    canvas = new fabric.Canvas(canvasEl.value, {
        isDrawingMode: false,
        width: 0,
        height: 0,
        selection: false,
        skipOffscreen: true,
        renderOnAddRemove: true,
        backgroundColor: 'transparent'
    });

    // Reduce path points for performance and signaling payload size
    if (canvas.freeDrawingBrush) {
        canvas.freeDrawingBrush.decimate = 2;
    }

    setupCanvasListeners();

    // Use ResizeObserver for more reliable sizing
    // We observe the "grandparent" because Fabric.js wraps canvasEl in a .canvas-container div
    // Observing that container leads to a 300x150 deadlock.
    const resizeTarget = canvasEl.value.parentElement?.parentElement;
    if (resizeTarget) {
        resizeObserver = new ResizeObserver(() => {
            handleResize();
        });
        resizeObserver.observe(resizeTarget);
    }
    
    console.log(`[AnnotationOverlay] Initialized. Observing: ${resizeTarget?.className || 'unknown'}`);
    
    handleResize();
});

function cleanJson(obj: any): any {
    if (typeof obj !== 'object' || obj === null) return obj;
    
    // Round numeric values to reduce payload size
    for (const key in obj) {
        if (typeof obj[key] === 'number') {
            obj[key] = Math.round(obj[key] * 100) / 100;
        } else if (Array.isArray(obj[key])) {
            obj[key] = obj[key].map((item: any) => cleanJson(item));
        } else if (typeof obj[key] === 'object') {
            obj[key] = cleanJson(obj[key]);
        }
    }
    
    // Remote redundant Fabric properties to save space in whisper signals
    const redundant = ['shadow', 'clipPath', 'paintFirst', 'globalCompositeOperation', 'skewX', 'skewY'];
    redundant.forEach(prop => delete obj[prop]);
    
    return obj;
}

onUnmounted(() => {
    if (resizeObserver) {
        resizeObserver.disconnect();
    }
    if (canvas) {
        canvas.dispose();
        canvas = null;
    }
});

let isDown = false;
let origX = 0;
let origY = 0;
let activeShape: fabric.Object | null = null;

function setupCanvasListeners() {
    if (!canvas) return;

    canvas.on('mouse:down', (o) => {
        if (!props.isLocal || canvas?.isDrawingMode) return;
        isDown = true;
        // Fabric 7.0 change: getPointer is removed, use getScenePoint
        const scenePointer = (canvas as any)?.getScenePoint(o.e);
        if (!scenePointer) return;
        origX = scenePointer.x;
        origY = scenePointer.y;

        const tool = meetingStore.activeAnnotationTool;
        const color = meetingStore.activeAnnotationColor;

        if (tool === 'rect') {
            activeShape = new fabric.Rect({
                left: origX,
                top: origY,
                originX: 'left',
                originY: 'top',
                width: 0,
                height: 0,
                fill: 'transparent',
                stroke: color,
                strokeWidth: 3,
                selectable: false,
            });
            canvas?.add(activeShape);
        } else if (tool === 'circle') {
            activeShape = new fabric.Circle({
                left: origX,
                top: origY,
                originX: 'left',
                originY: 'top',
                radius: 0,
                fill: 'transparent',
                stroke: color,
                strokeWidth: 3,
                selectable: false,
            });
            canvas?.add(activeShape);
        }
    });

    canvas.on('mouse:move', (o) => {
        if (!isDown || !activeShape) return;
        const pointer = (canvas as any)?.getScenePoint(o.e);
        if (!pointer) return;

        if (meetingStore.activeAnnotationTool === 'rect') {
            if (origX > pointer.x) {
                activeShape.set({ left: Math.abs(pointer.x) });
            }
            if (origY > pointer.y) {
                activeShape.set({ top: Math.abs(pointer.y) });
            }
            activeShape.set({ width: Math.abs(origX - pointer.x) });
            activeShape.set({ height: Math.abs(origY - pointer.y) });
        } else if (meetingStore.activeAnnotationTool === 'circle') {
            const radius = Math.max(Math.abs(origX - pointer.x), Math.abs(origY - pointer.y)) / 2;
            if (origX > pointer.x) {
                activeShape.set({ left: Math.abs(pointer.x) });
            }
            if (origY > pointer.y) {
                activeShape.set({ top: Math.abs(pointer.y) });
            }
            (activeShape as fabric.Circle).set({ radius });
        }
        canvas?.renderAll();
    });

    canvas.on('mouse:up', () => {
        if (!activeShape || !props.isLocal || !canvas) {
            isDown = false;
            return;
        }

        // Normalize coordinates to 0..1 range for cross-client precision
        const normalizedObj = activeShape.toJSON();
        normalizedObj.left = (normalizedObj.left || 0) / canvas.width;
        normalizedObj.top = (normalizedObj.top || 0) / canvas.height;
        normalizedObj.scaleX = (normalizedObj.scaleX || 1) / canvas.width;
        normalizedObj.scaleY = (normalizedObj.scaleY || 1) / canvas.height;

        meetingStore.sendAnnotationUpdate({
            type: 'object-added',
            object: cleanJson(normalizedObj),
            isNormalized: true
        });

        isDown = false;
        activeShape = null;
    });

    canvas.on('path:created', (opt: any) => {
        if (!props.isLocal || !canvas) return;
        
        // Normalize path points to 0..1 range
        const pathObj = opt.path.toJSON();
        if (pathObj.path) {
            pathObj.path = pathObj.path.map((segment: any) => {
                return segment.map((val: any, idx: number) => {
                    if (typeof val === 'number') {
                        // idx 1 is X, idx 2 is Y in Fabric path commands
                        return idx === 1 ? val / canvas.width : (idx === 2 ? val / canvas.height : val);
                    }
                    return val;
                });
            });
        }
        pathObj.left = (pathObj.left || 0) / canvas.width;
        pathObj.top = (pathObj.top || 0) / canvas.height;

        meetingStore.sendAnnotationUpdate({
            type: 'path-added',
            path: cleanJson(pathObj),
            isNormalized: true
        });
    });

    // We removed the redundant object:added listener to avoid double-sending shapes
}

function handleResize() {
    if (!canvas || !canvasEl.value) return;
    
    // We get size from the grandparent (the video content box slot)
    // because Fabric's immediate parent (.canvas-container) follows the canvas's size (300x150 default)
    const container = canvasEl.value.parentElement?.parentElement;
    if (container) {
        const { clientWidth, clientHeight } = container;
        
        // Don't resize if size is 0 (hidden)
        if (clientWidth === 0 || clientHeight === 0) {
            console.log('[AnnotationOverlay] handleResize skipped: dimension is 0');
            return;
        }

        console.log(`[AnnotationOverlay] handleResize: prev=${canvas.width}x${canvas.height} => next=${clientWidth}x${clientHeight}. Container: ${container.className}`);
        
        canvas.setDimensions({
            width: clientWidth,
            height: clientHeight
        });
        canvas.renderAll();
    }
}

// Watch for store changes to toggle annotation mode
watch(() => meetingStore.isAnnotating, (active) => {
    isAnnotating.value = active;
    if (canvas) {
        const isDrawingTool = ['pen', 'highlighter', 'eraser'].includes(meetingStore.activeAnnotationTool);
        canvas.isDrawingMode = active && props.isLocal && isDrawingTool;
        updateBrush();
    }
}, { immediate: true });

// Watch for tool/color changes
watch([() => meetingStore.activeAnnotationTool, () => meetingStore.activeAnnotationColor], () => {
    if (!canvas) return; // Ensure canvas exists before trying to set properties
    const isDrawingTool = ['pen', 'highlighter', 'eraser'].includes(meetingStore.activeAnnotationTool);
    canvas.isDrawingMode = props.isLocal && meetingStore.isAnnotating && isDrawingTool;
    updateBrush();
});

function updateBrush() {
    if (!canvas || !canvas.isDrawingMode) return;

    const tool = meetingStore.activeAnnotationTool;
    const color = meetingStore.activeAnnotationColor;

    if (tool === 'eraser') {
        canvas.freeDrawingBrush = new fabric.PencilBrush(canvas);
        canvas.freeDrawingBrush.color = '#3c4043'; // Background match (approx)
        canvas.freeDrawingBrush.width = 20;
    } else {
        canvas.freeDrawingBrush = new fabric.PencilBrush(canvas);
        canvas.freeDrawingBrush.color = color;
        canvas.freeDrawingBrush.width = tool === 'highlighter' ? 15 : 3;

        if (tool === 'highlighter') {
            canvas.freeDrawingBrush.color = hexToRgba(color, 0.4);
        }
    }
}

function hexToRgba(hex: string, alpha: number) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function clearCanvas() {
    if (!canvas) return;
    canvas.clear();
    if (props.isLocal) {
        meetingStore.sendAnnotationUpdate({ type: 'clear' });
    }
}

function undoLast() {
    if (!canvas) return;
    const objects = canvas.getObjects();
    if (objects.length > 0) {
        canvas.remove(objects[objects.length - 1]);
        if (props.isLocal) {
            meetingStore.sendAnnotationUpdate({ type: 'undo' });
        }
    }
}

// Handle remote updates
// We'll use the handleAnnotationUpdate we added to the store (via proxy or event)
// But for now, let's just watch a ref in the store or expose a method
// Handle remote updates
defineExpose({
    async handleRemoteUpdate(data: any) {
        if (!canvas || props.isLocal) return;

        console.log(`[AnnotationOverlay] Remote update received: ${data.type} from ${data.participant_id}`);

        if (data.type === 'path-added' || data.type === 'object-added') {
            const jsonData = data.type === 'path-added' ? data.path : data.object;
            
            // Denormalize if needed
            if (data.isNormalized) {
                if (jsonData.path) {
                    jsonData.path = jsonData.path.map((segment: any) => {
                        return segment.map((val: any, idx: number) => {
                            if (typeof val === 'number') {
                                return idx === 1 ? val * canvas.width : (idx === 2 ? val * canvas.height : val);
                            }
                            return val;
                        });
                    });
                }
                jsonData.left = (jsonData.left || 0) * canvas.width;
                jsonData.top = (jsonData.top || 0) * canvas.height;
                if (data.type === 'object-added') {
                    jsonData.scaleX = (jsonData.scaleX || 1) * canvas.width;
                    jsonData.scaleY = (jsonData.scaleY || 1) * canvas.height;
                }
            } else {
                // Backward compatibility scale (pixel based)
                const remoteWidth = data.canvasWidth || 1;
                const remoteHeight = data.canvasHeight || 1;
                const scaleX = canvas.width / remoteWidth;
                const scaleY = canvas.height / remoteHeight;
                jsonData.left = (jsonData.left || 0) * scaleX;
                jsonData.top = (jsonData.top || 0) * scaleY;
                jsonData.scaleX = (jsonData.scaleX || 1) * scaleX;
                jsonData.scaleY = (jsonData.scaleY || 1) * scaleY;
            }

            // Ensure remote objects have consistent origin for scaling
            jsonData.originX = 'left';
            jsonData.originY = 'top';

            try {
                // Fabric 7 enlivenObjects returns a Promise
                const objects = await fabric.util.enlivenObjects([jsonData]);
                objects.forEach(obj => {
                    obj.setCoords();
                    canvas?.add(obj);
                });
                canvas?.renderAll();
            } catch (err) {
                console.error('[AnnotationOverlay] Failed to enliven objects:', err);
            }
        } else if (data.type === 'clear') {
            canvas.clear();
        } else if (data.type === 'undo') {
            const objects = canvas.getObjects();
            if (objects.length > 0) {
                canvas.remove(objects[objects.length - 1]);
            }
        }
    }
});
</script>

<style scoped>
.annotation-overlay {
    position: absolute;
    inset: 0;
    z-index: 100;
    pointer-events: none;
}

.annotation-overlay--active {
    pointer-events: all;
}

canvas {
    display: block;
    border: 2px dashed red; /* Temporary debug border */
}
</style>
