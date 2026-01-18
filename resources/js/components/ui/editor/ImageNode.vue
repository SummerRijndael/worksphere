<script setup>
import { NodeViewWrapper, nodeViewProps } from "@tiptap/vue-3";
import {
    Trash2,
    Move,
    AlignLeft,
    AlignCenter,
    AlignRight,
} from "lucide-vue-next";
import { ref, computed } from "vue";

const props = defineProps(nodeViewProps);

const resizing = ref(false);
const startX = ref(0);
const startWidth = ref(0);

// Drag Handler logic for Resize
const onResizeStart = (event) => {
    event.preventDefault();
    resizing.value = true;
    startX.value = event.clientX;

    // Get current width or approximate from element
    const imgElement = event.target.parentElement.querySelector("img");
    const computedStyle = window.getComputedStyle(imgElement);
    startWidth.value = parseFloat(computedStyle.width); // Use current rendered width as start

    document.addEventListener("mousemove", onResizeMove);
    document.addEventListener("mouseup", onResizeEnd);
};

const onResizeMove = (event) => {
    if (!resizing.value) return;
    const currentX = event.clientX;
    const diffX = currentX - startX.value;
    const newWidth = Math.max(100, startWidth.value + diffX); // Min width 100px

    props.updateAttributes({
        width: newWidth,
    });
};

const onResizeEnd = () => {
    resizing.value = false;
    document.removeEventListener("mousemove", onResizeMove);
    document.removeEventListener("mouseup", onResizeEnd);
};

const setLayout = (layout) => {
    props.updateAttributes({
        layout: layout,
    });
};

const containerStyle = computed(() => {
    const layout = props.node.attrs.layout;
    if (!layout) return {};

    if (layout === "left") {
        return {
            float: "left",
            marginRight: "1rem",
            marginBottom: "0.5rem",
            maxWidth: "50%",
        };
    }
    if (layout === "right") {
        return {
            float: "right",
            marginLeft: "1rem",
            marginBottom: "0.5rem",
            maxWidth: "50%",
        };
    }
    if (layout === "center") {
        return {
            display: "block",
            marginLeft: "auto",
            marginRight: "auto",
            marginBottom: "1rem",
        };
    }
    return {};
});
</script>

<template>
    <NodeViewWrapper
        class="relative inline-block group max-w-full transition-all duration-300 ease-in-out"
        :style="containerStyle"
    >
        <!-- Drag Handle / Move Cursor Indication (Cover on hover or specific handle) -->
        <!-- We use the image itself as the drag target usually, standard Tiptap behavior. 
             We add cursor-move to indicate it. -->
        <div
            class="relative inline-block"
            :class="{ 'ring-2 ring-blue-500': selected || resizing }"
        >
            <img
                :src="node.attrs.src"
                :alt="node.attrs.alt"
                :title="node.attrs.title"
                :width="node.attrs.width"
                class="rounded-lg h-auto block transition-opacity hover:opacity-95 cursor-move"
                :style="{
                    width: node.attrs.width
                        ? node.attrs.width + 'px'
                        : undefined,
                    maxWidth: '100%',
                }"
                draggable="true"
                data-drag-handle
            />

            <!-- Controls Overlay -->
            <div
                class="absolute top-2 right-2 flex gap-1 z-20 opacity-0 group-hover:opacity-100 transition-opacity"
            >
                <!-- Layout Controls -->
                <div
                    class="flex bg-white/90 rounded-md shadow-lg overflow-hidden mr-2"
                >
                    <button
                        type="button"
                        @click="setLayout('left')"
                        class="p-1.5 hover:bg-gray-100 transition-colors"
                        :class="{
                            'bg-blue-100 text-blue-600':
                                node.attrs.layout === 'left',
                        }"
                        title="Float Left"
                    >
                        <AlignLeft class="w-4 h-4" />
                    </button>
                    <button
                        type="button"
                        @click="setLayout('center')"
                        class="p-1.5 hover:bg-gray-100 transition-colors"
                        :class="{
                            'bg-blue-100 text-blue-600':
                                node.attrs.layout === 'center',
                        }"
                        title="Center"
                    >
                        <AlignCenter class="w-4 h-4" />
                    </button>
                    <button
                        type="button"
                        @click="setLayout('right')"
                        class="p-1.5 hover:bg-gray-100 transition-colors"
                        :class="{
                            'bg-blue-100 text-blue-600':
                                node.attrs.layout === 'right',
                        }"
                        title="Float Right"
                    >
                        <AlignRight class="w-4 h-4" />
                    </button>
                    <button
                        type="button"
                        @click="setLayout(null)"
                        class="p-1.5 hover:bg-gray-100 transition-colors border-l"
                        :class="{
                            'bg-blue-100 text-blue-600': !node.attrs.layout,
                        }"
                        title="Reset Layout"
                    >
                        <Move class="w-4 h-4" />
                    </button>
                </div>

                <!-- Delete -->
                <button
                    type="button"
                    @click="deleteNode"
                    class="p-1.5 bg-red-600 text-white rounded-md shadow-lg hover:bg-red-700 focus:outline-none"
                    title="Delete Image"
                >
                    <Trash2 class="w-4 h-4" />
                </button>
            </div>

            <!-- Resize Handle -->
            <div
                class="absolute bottom-2 right-2 w-4 h-4 bg-blue-500 rounded-full cursor-nwse-resize shadow border-2 border-white z-20 opacity-0 group-hover:opacity-100"
                @mousedown="onResizeStart"
                title="Resize"
            ></div>
        </div>
    </NodeViewWrapper>
</template>
