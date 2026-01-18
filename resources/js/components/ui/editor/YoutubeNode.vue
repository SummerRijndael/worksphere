<script setup>
import { NodeViewWrapper, nodeViewProps } from "@tiptap/vue-3";
import { Trash2, Move } from "lucide-vue-next";
import { ref, computed } from "vue";

const props = defineProps(nodeViewProps);

const resizing = ref(false);
const startX = ref(0);
const startWidth = ref(0);
const aspectRatio = 16 / 9;

// Drag Handler logic for Resize
const onResizeStart = (event) => {
    event.preventDefault();
    resizing.value = true;
    startX.value = event.clientX;
    startWidth.value = props.node.attrs.width;

    document.addEventListener("mousemove", onResizeMove);
    document.addEventListener("mouseup", onResizeEnd);
};

const onResizeMove = (event) => {
    if (!resizing.value) return;
    const currentX = event.clientX;
    const diffX = currentX - startX.value;
    const newWidth = Math.max(300, startWidth.value + diffX); // Min width 300px
    const newHeight = Math.round(newWidth / aspectRatio);

    props.updateAttributes({
        width: newWidth,
        height: newHeight,
    });
};

const onResizeEnd = () => {
    resizing.value = false;
    document.removeEventListener("mousemove", onResizeMove);
    document.removeEventListener("mouseup", onResizeEnd);
};

const containerStyle = computed(() => ({
    width: props.node.attrs.width + "px",
    height: props.node.attrs.height + "px",
}));
</script>

<template>
    <NodeViewWrapper
        class="relative inline-block group max-w-full"
        :style="containerStyle"
    >
        <div
            class="relative w-full h-full rounded-lg overflow-hidden bg-black shadow-sm ring-2 ring-transparent group-hover:ring-blue-500/50 transition-all"
        >
            <!-- Iframe -->
            <!-- Pointer events none while resizing to prevent jitter -->
            <iframe
                :src="node.attrs.src"
                :width="node.attrs.width"
                :height="node.attrs.height"
                allowfullscreen
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                class="w-full h-full object-cover"
                :class="{ 'pointer-events-none': resizing }"
            ></iframe>

            <!-- Drag Overlay (Invisible but captures events for drag on hover) -->
            <!-- We only show this 'handle' area on hover to allow clicking play otherwise. 
                 Actually, clicking play is inside iframe. Tiptap usually requires a handle. 
                 Let's add a visible drag handle bar at top on hover. -->
            <div
                class="absolute inset-x-0 top-0 h-8 bg-gradient-to-b from-black/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity cursor-move z-10 flex items-center justify-center"
                data-drag-handle
            >
                <Move class="w-4 h-4 text-white opacity-70" />
            </div>

            <!-- Delete Button -->
            <button
                type="button"
                @click="deleteNode"
                class="absolute top-2 right-2 p-1.5 bg-red-600/90 text-white rounded-md shadow-lg opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-700 focus:outline-none z-20"
                title="Delete Video"
            >
                <Trash2 class="w-4 h-4" />
            </button>

            <!-- Resize Handle -->
            <div
                class="absolute bottom-2 right-2 w-4 h-4 bg-blue-500 rounded-full cursor-nwse-resize shadow border-2 border-white z-20 opacity-0 group-hover:opacity-100"
                @mousedown="onResizeStart"
                title="Resize"
            ></div>
        </div>
    </NodeViewWrapper>
</template>
