<script setup>
import { NodeViewWrapper, nodeViewProps, NodeViewContent } from "@tiptap/vue-3";
import { NodeSelection } from "@tiptap/pm/state";
import { Trash2, GripVertical, Copy, Scissors } from "lucide-vue-next";
import { ref } from "vue";

const props = defineProps(nodeViewProps);

const isHovered = ref(false);

const deleteTable = () => {
    props.deleteNode();
};

const selectTable = () => {
    // Select the entire table node
    const { state, view } = props.editor;
    const pos = props.getPos();
    
    // Create a node selection for the entire table
    const selection = NodeSelection.create(state.doc, pos);
    view.dispatch(state.tr.setSelection(selection));
};

const copyTable = () => {
    // Select and copy
    selectTable();
    document.execCommand('copy');
};

const cutTable = () => {
    // Select, copy, then delete
    selectTable();
    document.execCommand('copy');
    deleteTable();
};
</script>

<template>
    <NodeViewWrapper
        class="table-node-wrapper relative inline-block my-4 group"
        @mouseenter="isHovered = true"
        @mouseleave="isHovered = false"
    >
        <!-- Controls Overlay - appears on hover -->
        <div
            v-if="isHovered || selected"
            class="absolute -top-8 left-0 z-20 flex items-center gap-1 px-2 py-1 rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] shadow-lg"
        >
            <!-- Drag Handle - this is the actual draggable element -->
            <div
                class="cursor-grab active:cursor-grabbing p-1 rounded hover:bg-[var(--surface-tertiary)]"
                title="Drag to move"
                data-drag-handle
                draggable="true"
            >
                <GripVertical class="w-4 h-4 text-[var(--text-secondary)]" />
            </div>
            
            <div class="w-px h-4 bg-[var(--border-default)]" />
            
            <!-- Copy -->
            <button
                type="button"
                class="p-1 rounded hover:bg-[var(--surface-tertiary)]"
                title="Copy table"
                @click="copyTable"
            >
                <Copy class="w-4 h-4 text-[var(--text-secondary)]" />
            </button>
            
            <!-- Cut -->
            <button
                type="button"
                class="p-1 rounded hover:bg-[var(--surface-tertiary)]"
                title="Cut table"
                @click="cutTable"
            >
                <Scissors class="w-4 h-4 text-[var(--text-secondary)]" />
            </button>
            
            <div class="w-px h-4 bg-[var(--border-default)]" />
            
            <!-- Delete -->
            <button
                type="button"
                class="p-1 rounded hover:bg-red-50 dark:hover:bg-red-900/20"
                title="Delete table"
                @click="deleteTable"
            >
                <Trash2 class="w-4 h-4 text-red-500" />
            </button>
        </div>

        <!-- Selection Ring - wraps tight around table -->
        <div
            :class="[
                'inline-block rounded transition-all duration-200',
                (isHovered || selected) && 'ring-2 ring-[var(--interactive-primary)] ring-offset-2'
            ]"
        >
            <!-- Table Content -->
            <NodeViewContent as="div" class="table-content" />
        </div>
    </NodeViewWrapper>
</template>

<style scoped>
.table-node-wrapper {
    display: block;
}

.table-content :deep(table) {
    margin: 0 !important;
    display: table;
}

/* Make the ring fit the table */
.table-content {
    display: inline-block;
}
</style>
