<script setup lang="ts">
import { computed, watch, onBeforeUnmount, ref } from "vue";
import {
    useEditor,
    EditorContent,
    VueNodeViewRenderer,
    BubbleMenu,
    FloatingMenu,
} from "@tiptap/vue-3";
import { NodeSelection, TextSelection } from "@tiptap/pm/state";
import StarterKit from "@tiptap/starter-kit";
import Placeholder from "@tiptap/extension-placeholder";
import Youtube from "@tiptap/extension-youtube";
import Link from "@tiptap/extension-link";
import Underline from "@tiptap/extension-underline";
import Image from "@tiptap/extension-image";
import TextAlign from "@tiptap/extension-text-align";
import { Color } from "@tiptap/extension-color";
import TextStyle from "@tiptap/extension-text-style";
import FontFamily from "@tiptap/extension-font-family";
import Table from "@tiptap/extension-table";
import TableRow from "@tiptap/extension-table-row";
import TableCell from "@tiptap/extension-table-cell";
import TableHeader from "@tiptap/extension-table-header";
import { Sketch } from "@ckpack/vue-color";
import Modal from "./Modal.vue";
import Input from "./Input.vue";
import Button from "./Button.vue";
import Dropdown from "./Dropdown.vue";
import ImageNode from "./editor/ImageNode.vue";
import YoutubeNode from "./editor/YoutubeNode.vue";
import { cn } from "@/lib/utils";
import {
    Bold,
    Italic,
    Underline as UnderlineIcon,
    Strikethrough,
    List,
    ListOrdered,
    Quote,
    Undo,
    Redo,
    Link as LinkIcon,
    Unlink,
    Code,
    Image as ImageIcon,
    MonitorPlay,
    Palette,
    Heading1,
    Heading2,
    Type,
    AlignLeft,
    AlignCenter,
    AlignRight,
    AlignJustify,
    Table as TableIcon,
    Merge,
    Split,
    Trash2,
    Plus,
    ArrowUp,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    Paintbrush,
    BoxSelect,
    Scissors,
    Copy,
} from "lucide-vue-next";

defineSlots<{ 'toolbar-after'?: any; default?: any; }>();

const props = defineProps({
    modelValue: {
        type: String,
        default: "",
    },
    placeholder: {
        type: String,
        default: "Write something...",
    },
    label: String,
    hint: String,
    error: String,
    disabled: Boolean,
    minHeight: {
        type: String,
        default: "120px",
    },
});

const emit = defineEmits(["update:modelValue"]);

const editor = useEditor({
    content: props.modelValue,
    editable: !props.disabled,
    extensions: [
        StarterKit.configure({
            heading: {
                levels: [1, 2, 3],
            },
        }),
        Placeholder.configure({
            placeholder: props.placeholder,
        }),
        Link.configure({
            openOnClick: false,
            HTMLAttributes: {
                class: "text-[var(--interactive-primary)] underline",
            },
        }),
        Underline,
        TextStyle,
        Color,
        FontFamily,
        TextAlign.configure({
            types: ["heading", "paragraph"],
        }),
        Image.extend({
            addAttributes() {
                return {
                    ...this.parent?.(),
                    width: {
                        default: null,
                    },
                    layout: {
                        default: null,
                    },
                };
            },
            addNodeView() {
                return VueNodeViewRenderer(ImageNode);
            },
        }).configure({
            inline: true,
            HTMLAttributes: {
                class: "rounded-lg max-w-full h-auto",
            },
        }),
        Youtube.extend({
            addNodeView() {
                return VueNodeViewRenderer(YoutubeNode);
            },
        }).configure({
            controls: false,
            nocookie: true,
            width: 640,
            height: 480,
        }),
        Table.extend({
            addAttributes() {
                return {
                    ...this.parent?.(),
                    borderColor: {
                        default: null,
                        parseHTML: (element) =>
                            element.getAttribute("data-border-color"),
                        renderHTML: (attributes) => {
                            if (!attributes.borderColor) return {};
                            return {
                                "data-border-color": attributes.borderColor,
                            };
                        },
                    },
                };
            },
        }).configure({
            resizable: true,
        }),
        TableRow,
        TableCell.extend({
            addAttributes() {
                return {
                    ...this.parent?.(),
                    borderColor: {
                        default: null,
                        parseHTML: (element) =>
                            element.style.borderColor || null,
                        renderHTML: (attributes) => {
                            if (!attributes.borderColor) return {};
                            return {
                                style: `border-color: ${attributes.borderColor}`,
                            };
                        },
                    },
                };
            },
        }),
        TableHeader.extend({
            addAttributes() {
                return {
                    ...this.parent?.(),
                    borderColor: {
                        default: null,
                        parseHTML: (element) =>
                            element.style.borderColor || null,
                        renderHTML: (attributes) => {
                            if (!attributes.borderColor) return {};
                            return {
                                style: `border-color: ${attributes.borderColor}`,
                            };
                        },
                    },
                };
            },
        }),
    ],
    onUpdate: ({ editor }) => {
        emit("update:modelValue", editor.getHTML());
    },
});

const fontOptions = [
    { label: "Inter", value: "Inter, sans-serif" },
    { label: "Roboto", value: "Roboto, sans-serif" },
    { label: "Serif", value: "serif" },
    { label: "Monospace", value: "monospace" },
    { label: "Comic Sans", value: '"Comic Sans MS", "Comic Sans", cursive' },
];

function setFont(font) {
    editor.value.chain().focus().setFontFamily(font).run();
}

function unsetFont() {
    editor.value.chain().focus().unsetFontFamily().run();
}

defineExpose({ editor });

watch(
    () => props.modelValue,
    (value) => {
        if (editor.value && value !== editor.value.getHTML()) {
            editor.value.commands.setContent(value, false);
        }
    },
);

watch(
    () => props.disabled,
    (disabled) => {
        if (editor.value) {
            editor.value.setEditable(!disabled);
        }
    },
);

onBeforeUnmount(() => {
    editor.value?.destroy();
});

/**
 * Validate URL to prevent javascript: and other dangerous URI schemes
 */
function isValidUrl(url) {
    if (!url || typeof url !== "string") return false;

    const trimmedUrl = url.trim();

    // Allow relative URLs (starting with / or #)
    if (trimmedUrl.startsWith("/") || trimmedUrl.startsWith("#")) {
        return true;
    }

    // Validate absolute URLs
    try {
        const parsed = new URL(trimmedUrl);
        const allowedProtocols = ["http:", "https:", "mailto:", "tel:"];
        return allowedProtocols.includes(parsed.protocol);
    } catch {
        // If URL parsing fails, try prepending https://
        try {
            const parsed = new URL("https://" + trimmedUrl);
            return parsed.protocol === "https:";
        } catch {
            return false;
        }
    }
}

function setLink() {
    const previousUrl = editor.value.getAttributes("link").href;
    const url = window.prompt("Enter URL", previousUrl);

    if (url === null) return;

    if (url === "") {
        editor.value.chain().focus().extendMarkRange("link").unsetLink().run();
        return;
    }

    // Validate URL to prevent XSS via javascript: URIs
    if (!isValidUrl(url)) {
        alert(
            "Invalid URL. Only http://, https://, mailto:, tel:, and relative URLs are allowed.",
        );
        return;
    }

    editor.value
        .chain()
        .focus()
        .extendMarkRange("link")
        .setLink({ href: url })
        .run();
}

// Modal State
const showVideoModal = ref(false);
const videoUrl = ref("");
const showColorModal = ref(false);
const colorValue = ref<any>("#958DF1");

function addVideo() {
    videoUrl.value = "";
    showVideoModal.value = true;
}

function confirmAddVideo() {
    if (videoUrl.value) {
        editor.value.commands.setYoutubeVideo({
            src: videoUrl.value,
            width: 640,
            height: 480,
        });
        showVideoModal.value = false;
    }
}

function setColor() {
    // Try to get current color
    const current = editor.value.getAttributes("textStyle").color || "#958DF1";
    colorValue.value = current;
    showColorModal.value = true;
}

function confirmColor() {
    if (colorValue.value) {
        // Picker returns object with hex, or string if unchanged/default
        const hex =
            typeof colorValue.value === "object"
                ? colorValue.value.hex
                : colorValue.value;
        editor.value.chain().focus().setColor(hex).run();
        showColorModal.value = false;
    }
}

const showSource = ref(false);
const showTableModal = ref(false);
const tableRows = ref(3);
const tableCols = ref(3);

const toggleSource = () => {
    showSource.value = !showSource.value;
    if (!showSource.value && editor.value) {
        // When switching back to visual, ensure content is updated from modelValue if changed externally
        // But here we rely on v-model updates to props.modelValue, which might need a moment or explicit set
        editor.value.commands.setContent(props.modelValue);
    }
};

function openTableModal() {
    tableRows.value = 3;
    tableCols.value = 3;
    showTableModal.value = true;
}

function confirmInsertTable() {
    if (editor.value) {
        editor.value
            .chain()
            .focus()
            .insertTable({
                rows: tableRows.value,
                cols: tableCols.value,
                withHeaderRow: true,
            })
            .run();
        showTableModal.value = false;
    }
}

// Border color state
const showBorderColorModal = ref(false);
const borderColorValue = ref<any>("#000000");
const borderColorTarget = ref("cell"); // 'cell' or 'table'

function openBorderColorModal(target) {
    borderColorTarget.value = target;
    borderColorValue.value = "#000000";
    showBorderColorModal.value = true;
}

function confirmBorderColor() {
    if (!editor.value) return;

    const color =
        typeof borderColorValue.value === "object"
            ? borderColorValue.value.hex
            : borderColorValue.value;

    if (borderColorTarget.value === "cell") {
        // Apply to current cell only
        editor.value
            .chain()
            .focus()
            .setCellAttribute("borderColor", color)
            .run();
    } else {
        // Apply to entire table - find table boundaries and update all cells within
        const { state } = editor.value;
        const { selection } = state;
        const { $from } = selection;

        // Find the table node by walking up the node tree
        let tableStart = null;
        let tableEnd = null;

        for (let depth = $from.depth; depth > 0; depth--) {
            const node = $from.node(depth);
            if (node.type.name === "table") {
                tableStart = $from.before(depth);
                tableEnd = $from.after(depth);
                break;
            }
        }

        if (tableStart !== null && tableEnd !== null) {
            // Use a transaction to update all cells within this table's range
            const { tr } = state;
            let modified = false;

            state.doc.nodesBetween(tableStart, tableEnd, (node, pos) => {
                if (
                    node.type.name === "tableCell" ||
                    node.type.name === "tableHeader"
                ) {
                    // Only apply if cell doesn't already have a custom border color
                    if (!node.attrs.borderColor) {
                        tr.setNodeMarkup(pos, null, {
                            ...node.attrs,
                            borderColor: color,
                        });
                        modified = true;
                    }
                }
            });

            if (modified) {
                editor.value.view.dispatch(tr);
            }
        }
    }

    showBorderColorModal.value = false;
}

interface ToolbarButton {
    icon?: any;
    action?: () => void;
    isActive?: () => boolean;
    disabled?: () => boolean;
    title?: string;
    type?: string;
    items?: Array<{ label: string; action: () => void; isActive: () => boolean }>;
    divider?: boolean;
    className?: string;
}

const toolbarGroups = computed<ToolbarButton[][]>(() => [
    [
        {
            icon: Code,
            action: toggleSource,
            isActive: () => showSource.value,
            title: "View Source",
        },
    ],
    [
        {
            icon: Undo,
            action: () => editor.value.chain().focus().undo().run(),
            isActive: () => false,
            disabled: () => !editor.value?.can().undo() || showSource.value,
            title: "Undo",
        },
        {
            icon: Redo,
            action: () => editor.value.chain().focus().redo().run(),
            isActive: () => false,
            disabled: () => !editor.value?.can().redo() || showSource.value,
            title: "Redo",
        },
    ],
    [
        {
            icon: Heading1,
            action: () =>
                editor.value.chain().focus().toggleHeading({ level: 1 }).run(),
            isActive: () => editor.value?.isActive("heading", { level: 1 }),
            title: "Heading 1",
            disabled: () => showSource.value,
        },
        {
            icon: Heading2,
            action: () =>
                editor.value.chain().focus().toggleHeading({ level: 2 }).run(),
            isActive: () => editor.value?.isActive("heading", { level: 2 }),
            title: "Heading 2",
            disabled: () => showSource.value,
        },
    ],
    [
        {
            icon: AlignLeft,
            action: () =>
                editor.value.chain().focus().setTextAlign("left").run(),
            isActive: () => editor.value?.isActive({ textAlign: "left" }),
            title: "Align Left",
            disabled: () => showSource.value,
        },
        {
            icon: AlignCenter,
            action: () =>
                editor.value.chain().focus().setTextAlign("center").run(),
            isActive: () => editor.value?.isActive({ textAlign: "center" }),
            title: "Align Center",
            disabled: () => showSource.value,
        },
        {
            icon: AlignRight,
            action: () =>
                editor.value.chain().focus().setTextAlign("right").run(),
            isActive: () => editor.value?.isActive({ textAlign: "right" }),
            title: "Align Right",
            disabled: () => showSource.value,
        },
        {
            icon: AlignJustify,
            action: () =>
                editor.value.chain().focus().setTextAlign("justify").run(),
            isActive: () => editor.value?.isActive({ textAlign: "justify" }),
            title: "Align Justify",
            disabled: () => showSource.value,
        },
    ],
    [
        {
            icon: Bold,
            action: () => editor.value.chain().focus().toggleBold().run(),
            isActive: () => editor.value?.isActive("bold"),
            title: "Bold",
            disabled: () => showSource.value,
        },
        {
            icon: Italic,
            action: () => editor.value.chain().focus().toggleItalic().run(),
            isActive: () => editor.value?.isActive("italic"),
            title: "Italic",
            disabled: () => showSource.value,
        },
        {
            icon: UnderlineIcon,
            action: () => editor.value.chain().focus().toggleUnderline().run(),
            isActive: () => editor.value?.isActive("underline"),
            title: "Underline",
            disabled: () => showSource.value,
        },
        {
            icon: Strikethrough,
            action: () => editor.value.chain().focus().toggleStrike().run(),
            isActive: () => editor.value?.isActive("strike"),
            title: "Strikethrough",
            disabled: () => showSource.value,
        },
        {
            icon: Palette,
            action: setColor,
            isActive: () => editor.value?.isActive("textStyle"),
            title: "Text Color",
            disabled: () => showSource.value,
        },
        {
            type: "dropdown",
            icon: Type,
            title: "Font Family",
            items: fontOptions.map((font) => ({
                label: font.label,
                action: () => setFont(font.value),
                isActive: () =>
                    editor.value?.isActive("textStyle", {
                        fontFamily: font.value,
                    }),
            })),
            disabled: () => showSource.value,
        },
    ],
    [
        {
            icon: List,
            action: () => editor.value.chain().focus().toggleBulletList().run(),
            isActive: () => editor.value?.isActive("bulletList"),
            title: "Bullet List",
            disabled: () => showSource.value,
        },
        {
            icon: ListOrdered,
            action: () =>
                editor.value.chain().focus().toggleOrderedList().run(),
            isActive: () => editor.value?.isActive("orderedList"),
            title: "Numbered List",
            disabled: () => showSource.value,
        },
    ],
    [
        {
            icon: Quote,
            action: () => editor.value.chain().focus().toggleBlockquote().run(),
            isActive: () => editor.value?.isActive("blockquote"),
            title: "Quote",
            disabled: () => showSource.value,
        },
        {
            icon: Code,
            action: () => editor.value.chain().focus().toggleCodeBlock().run(),
            isActive: () => editor.value?.isActive("codeBlock"),
            title: "Code Block",
            disabled: () => showSource.value,
        },
    ],
    [
        {
            icon: LinkIcon,
            action: setLink,
            isActive: () => editor.value?.isActive("link"),
            title: "Add Link",
            disabled: () => showSource.value,
        },
        {
            icon: Unlink,
            action: () => editor.value.chain().focus().unsetLink().run(),
            isActive: () => false,
            disabled: () => !editor.value?.isActive("link") || showSource.value,
            title: "Remove Link",
        },
        {
            icon: MonitorPlay,
            action: addVideo,
            isActive: () => editor.value?.isActive("youtube"),
            title: "Add YouTube Video",
            disabled: () => showSource.value,
        },
        {
            icon: TableIcon,
            action: openTableModal,
            isActive: () => editor.value?.isActive("table"),
            title: "Insert Table",
            disabled: () => showSource.value,
        },
    ],
]);

const bubbleMenuButtons = computed<ToolbarButton[]>(() => {
    // Flatten toolbarGroups for specific titles
    const textButtons = toolbarGroups.value
        .flat()
        .filter(
            (btn) =>
                [
                    "Bold",
                    "Italic",
                    "Underline",
                    "Strikethrough",
                    "Text Color",
                    "Add Link",
                    "Font Family",
                    "Heading 1",
                    "Heading 2",
                ].includes(btn.title) ||
                [
                    "Align Left",
                    "Align Center",
                    "Align Right",
                    "Align Justify",
                ].includes(btn.title),
        );

    // If inside a table, append table operations with a divider
    if (editor.value?.isActive("table")) {
        return [...textButtons, { divider: true }, ...tableOperations.value];
    }

    return textButtons;
});

const floatingMenuButtons = computed<ToolbarButton[]>(() =>
    toolbarGroups.value
        .flat()
        .filter((btn) =>
            [
                "Bullet List",
                "Numbered List",
                "Quote",
                "Code Block",
                "Add YouTube Video",
                "Image",
                "Insert Table",
            ].includes(btn.title),
        ),
);

// Table-specific operations for when inside a table
const tableOperations = computed(() => [
    {
        icon: Merge,
        action: () => editor.value.chain().focus().mergeCells().run(),
        title: "Merge Cells",
        disabled: () => !editor.value?.can().mergeCells(),
    },
    {
        icon: Split,
        action: () => editor.value.chain().focus().splitCell().run(),
        title: "Split Cell",
        disabled: () => !editor.value?.can().splitCell(),
    },
    { divider: true },
    {
        icon: ArrowUp,
        action: () => editor.value.chain().focus().addRowBefore().run(),
        title: "Add Row Above",
    },
    {
        icon: ArrowDown,
        action: () => editor.value.chain().focus().addRowAfter().run(),
        title: "Add Row Below",
    },
    {
        icon: ArrowLeft,
        action: () => editor.value.chain().focus().addColumnBefore().run(),
        title: "Add Column Left",
    },
    {
        icon: ArrowRight,
        action: () => editor.value.chain().focus().addColumnAfter().run(),
        title: "Add Column Right",
    },
    { divider: true },
    {
        icon: Paintbrush,
        action: () => openBorderColorModal("cell"),
        title: "Cell Border Color",
    },
    {
        icon: Paintbrush,
        action: () => openBorderColorModal("table"),
        title: "Table Border Color",
        className: "text-blue-500",
    },
    { divider: true },
    {
        icon: Trash2,
        action: () => editor.value.chain().focus().deleteRow().run(),
        title: "Delete Row",
        className: "text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20",
    },
    {
        icon: Trash2,
        action: () => editor.value.chain().focus().deleteColumn().run(),
        title: "Delete Column",
        className: "text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20",
    },
    {
        icon: Trash2,
        action: () => editor.value.chain().focus().deleteTable().run(),
        title: "Delete Table",
        className: "text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20",
    },
    { divider: true },
    {
        icon: Copy,
        action: () => {
            // Select table first
            if (!editor.value) return;
            const { state, view } = editor.value;
            const { selection } = state;
            const { $from } = selection;
            for (let depth = $from.depth; depth > 0; depth--) {
                const node = $from.node(depth);
                if (node.type.name === "table") {
                    const pos = $from.before(depth);
                    const tableSelection = NodeSelection.create(state.doc, pos);
                    view.dispatch(state.tr.setSelection(tableSelection));
                    // Try to copy
                    document.execCommand("copy");
                    break;
                }
            }
        },
        title: "Copy Table",
    },
    {
        icon: Scissors,
        action: () => {
            // Select table first
            if (!editor.value) return;
            const { state, view } = editor.value;
            const { selection } = state;
            const { $from } = selection;
            // Find table node by walking up
            for (let depth = $from.depth; depth > 0; depth--) {
                const node = $from.node(depth);
                if (node.type.name === "table") {
                    const pos = $from.before(depth);
                    const nodeSize = node.nodeSize;

                    // 1. Select the table for copying
                    const tableSelection = NodeSelection.create(state.doc, pos);
                    const selectionTr = state.tr.setSelection(tableSelection);
                    view.dispatch(selectionTr);

                    // 2. Focus and Copy
                    view.focus();
                    document.execCommand("copy");

                    // 3. Explicitly delete the specific table range
                    // We fetch the latest state to ensure we're working with current doc
                    const currentTr = view.state.tr.delete(pos, pos + nodeSize);
                    view.dispatch(currentTr);

                    break;
                }
            }
        },
        title: "Cut Table",
    },
    {
        icon: BoxSelect,
        action: () => {
            if (!editor.value) return;
            const { state, view } = editor.value;
            const { selection } = state;
            const { $from } = selection;

            // Find table node by walking up
            for (let depth = $from.depth; depth > 0; depth--) {
                const node = $from.node(depth);
                if (node.type.name === "table") {
                    // Create NodeSelection for the table
                    const pos = $from.before(depth);
                    const tableSelection = NodeSelection.create(state.doc, pos);
                    view.dispatch(state.tr.setSelection(tableSelection));
                    break;
                }
            }
        },
        title: "Select Table",
    },
]);

const containerClasses = computed(() =>
    cn(
        "rounded-none border transition-all flex flex-col min-h-0",
        "bg-(--surface-elevated)/80 backdrop-blur-md",
        props.disabled && "opacity-50 bg-(--surface-secondary)",
        props.error
            ? "border-error"
            : "border-(--border-default) focus-within:border-(--interactive-primary)",
    ),
);
</script>

<template>
    <div class="space-y-1.5 flex flex-col h-full min-h-0">
        <label
            v-if="label"
            class="block text-sm font-medium text-(--text-primary)"
        >
            {{ label }}
        </label>

        <div
            :class="containerClasses"
            class="flex-1 overflow-hidden shadow-sm transition-shadow"
        >
            <!-- Toolbar (Static) -->
            <div
                class="flex flex-wrap items-center gap-1 px-3 py-1.5 border-b border-(--border-muted) bg-(--surface-secondary) backdrop-blur-md rounded-t-none"
            >
                <template
                    v-for="(group, gIndex) in toolbarGroups"
                    :key="gIndex"
                >
                    <div class="flex items-center gap-0.5">
                        <template v-for="(button, index) in group" :key="index">
                            <Dropdown
                                v-if="button.type === 'dropdown'"
                                :items="button.items"
                                :disabled="button.disabled?.()"
                            >
                                <template #trigger>
                                    <button
                                        type="button"
                                        :title="button.title"
                                        :class="
                                            cn(
                                                'p-1.5 rounded-lg transition-all hover:bg-(--surface-secondary) active:scale-95',
                                                'flex items-center gap-1',
                                                button.disabled?.() &&
                                                    'opacity-40 cursor-not-allowed hover:bg-transparent',
                                            )
                                        "
                                        :disabled="button.disabled?.()"
                                    >
                                        <component
                                            :is="button.icon"
                                            class="h-4 w-4"
                                        />
                                    </button>
                                </template>
                            </Dropdown>
                            <button
                                v-else
                                type="button"
                                :title="button.title"
                                :disabled="disabled || button.disabled?.()"
                                :class="
                                    cn(
                                        'p-1.5 rounded-lg transition-all',
                                        'hover:bg-(--surface-secondary) active:scale-95 text-(--text-secondary)',
                                        'disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:bg-transparent',
                                        button.isActive?.() &&
                                            'bg-(--brand-primary)/10 text-(--brand-primary)',
                                    )
                                "
                                @click="button.action"
                            >
                                <component :is="button.icon" class="h-4 w-4" />
                            </button>
                        </template>
                    </div>
                    <!-- Divider -->
                    <div
                        v-if="gIndex < toolbarGroups.length - 1"
                        class="w-px h-4 bg-(--border-default)/60 mx-1"
                    />
                </template>

                <!-- Custom Actions Slot -->
                <div class="ml-auto flex items-center gap-1.5">
                    <slot name="toolbar-after"></slot>
                </div>
            </div>

            <!-- Editor -->
            <!-- Editor (Visual) -->
            <div
                v-if="!showSource"
                class="relative flex-1 flex flex-col overflow-hidden min-h-0"
            >
                <BubbleMenu
                    v-if="editor"
                    class="flex flex-wrap items-center gap-0.5 px-2 py-1 rounded-lg border border-(--border-default) bg-(--surface-elevated)/95 backdrop-blur-md shadow-xl"
                    :tippy-options="{ duration: 100, appendTo: 'parent' }"
                    :editor="editor"
                    :should-show="
                        ({ editor: ed }) => {
                            if (
                                ed.isActive('image') ||
                                ed.isActive('youtube')
                            ) {
                                return false;
                            }
                            return !ed.state.selection.empty;
                        }
                    "
                >
                    <template
                        v-for="(button, index) in bubbleMenuButtons"
                        :key="index"
                    >
                        <div
                            v-if="button.divider"
                            class="w-px h-3 mx-1 bg-(--border-default)/50"
                        />
                        <Dropdown
                            v-else-if="button.type === 'dropdown'"
                            :items="button.items"
                            :disabled="button.disabled?.()"
                        >
                            <template #trigger>
                                <button
                                    type="button"
                                    :title="button.title"
                                    class="p-1 px-1.5 rounded-md hover:bg-(--surface-tertiary) flex items-center gap-1 transition-all"
                                >
                                    <component
                                        :is="button.icon"
                                        class="h-3.5 w-3.5 text-(--text-secondary)"
                                    />
                                </button>
                            </template>
                        </Dropdown>
                        <button
                            v-else
                            type="button"
                            :title="button.title"
                            :disabled="disabled || button.disabled?.()"
                            :class="
                                cn(
                                    'p-1 px-1.5 rounded-md hover:bg-(--surface-tertiary) transition-all',
                                    button.isActive?.() &&
                                        'text-(--brand-primary) bg-(--brand-primary)/10',
                                    !button.isActive?.() &&
                                        'text-(--text-secondary)',
                                )
                            "
                            @click="button.action"
                        >
                            <component :is="button.icon" class="h-3.5 w-3.5" />
                        </button>
                    </template>
                </BubbleMenu>

                <FloatingMenu
                    v-if="editor"
                    class="flex items-center gap-0.5 px-2 py-1 rounded-lg border border-(--border-default) bg-(--surface-elevated)/95 backdrop-blur-md shadow-xl"
                    :tippy-options="{ duration: 100, appendTo: 'parent' }"
                    :editor="editor"
                >
                    <template
                        v-for="(button, index) in floatingMenuButtons"
                        :key="index"
                    >
                        <button
                            type="button"
                            :title="button.title"
                            :class="
                                cn(
                                    'p-1 px-1.5 rounded-md hover:bg-(--surface-tertiary) text-(--text-secondary) transition-all',
                                    button.isActive?.() &&
                                        'text-(--brand-primary) bg-(--brand-primary)/10',
                                )
                            "
                            @click="button.action"
                        >
                            <component :is="button.icon" class="h-3.5 w-3.5" />
                        </button>
                    </template>
                </FloatingMenu>

                <div class="flex-1 overflow-y-auto w-full custom-scrollbar p-6">
                    <EditorContent
                        :editor="editor"
                        :class="
                            cn(
                                'prose prose-sm max-w-none w-full min-h-full',
                                'prose-p:my-3 prose-p:text-(--text-primary) prose-p:leading-relaxed',
                                'prose-strong:text-(--text-primary)',
                                'prose-ul:my-3 prose-ol:my-3 prose-li:my-1',
                                'prose-blockquote:border-l-2 prose-blockquote:border-(--brand-primary)/40 prose-blockquote:text-(--text-secondary) prose-blockquote:italic prose-blockquote:bg-(--surface-secondary)/20 prose-blockquote:py-1 prose-blockquote:px-4 prose-blockquote:rounded-r-lg',
                                'prose-code:text-(--text-primary) prose-code:bg-(--surface-secondary) prose-code:px-1.5 prose-code:py-0.5 prose-code:rounded prose-code:font-medium',
                                'prose-pre:bg-(--surface-secondary) prose-pre:text-(--text-primary) prose-pre:p-4 prose-pre:rounded-xl prose-pre:border prose-pre:border-(--border-default)',
                            )
                        "
                    />
                </div>
            </div>

            <!-- Source (Code) -->
            <div v-else class="flex-1 w-full min-h-0">
                <textarea
                    :value="modelValue"
                    @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
                    class="w-full h-full p-4 font-mono text-sm bg-(--surface-primary) text-(--text-primary) resize-none focus:outline-none custom-scrollbar"
                    spellcheck="false"
                ></textarea>
            </div>
        </div>

        <p v-if="hint && !error" class="text-xs text-(--text-muted)">
            {{ hint }}
        </p>
        <p v-if="error" class="text-xs text-error">
            {{ error }}
        </p>

        <!-- YouTube Modal -->
        <Modal
            v-model:open="showVideoModal"
            title="Add YouTube Video"
            description="Enter the URL of the YouTube video you want to embed."
            size="sm"
        >
            <div class="space-y-4 pt-2">
                <Input
                    v-model="videoUrl"
                    placeholder="https://youtube.com/watch?v=..."
                    @keyup.enter="confirmAddVideo"
                    autofocus
                />
                <div class="flex justify-end gap-3">
                    <Button variant="ghost" @click="showVideoModal = false"
                        >Cancel</Button
                    >
                    <Button @click="confirmAddVideo">Insert Video</Button>
                </div>
            </div>
        </Modal>

        <!-- Color Modal -->
        <Modal v-model:open="showColorModal" title="Text Color" size="md">
            <div class="flex justify-center pb-2">
                <Sketch
                    v-model="colorValue"
                    class="w-full! max-w-none! shadow-none! bg-transparent! box-border!"
                    :preset-colors="[
                        '#000000',
                        '#ffffff',
                        '#F44336',
                        '#E91E63',
                        '#9C27B0',
                        '#673AB7',
                        '#3F51B5',
                        '#2196F3',
                        '#03A9F4',
                        '#00BCD4',
                        '#009688',
                        '#4CAF50',
                        '#8BC34A',
                        '#CDDC39',
                        '#FFEB3B',
                        '#FFC107',
                        '#FF9800',
                        '#FF5722',
                        '#795548',
                        '#9E9E9E',
                        '#607D8B',
                    ]"
                />
            </div>
            <div
                class="flex justify-end gap-3 pt-2 border-t border-(--border-default)"
            >
                <Button variant="ghost" @click="showColorModal = false"
                    >Cancel</Button
                >
                <Button @click="confirmColor">Apply Color</Button>
            </div>
        </Modal>

        <Modal
            v-model:open="showTableModal"
            title="Insert Table"
            description="Choose the number of rows and columns for your table."
            size="sm"
            class="border-(--border-default)"
        >
            <div class="space-y-4 pt-2">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label
                            class="block text-sm font-medium text-(--text-secondary) mb-1"
                            >Rows</label
                        >
                        <Input
                            v-model.number="tableRows"
                            type="number"
                            min="1"
                            max="20"
                        />
                    </div>
                    <div>
                        <label
                            class="block text-sm font-medium text-(--text-secondary) mb-1"
                            >Columns</label
                        >
                        <Input
                            v-model.number="tableCols"
                            type="number"
                            min="1"
                            max="10"
                        />
                    </div>
                </div>
                <div class="flex justify-end gap-3">
                    <Button variant="ghost" @click="showTableModal = false"
                        >Cancel</Button
                    >
                    <Button @click="confirmInsertTable">Insert Table</Button>
                </div>
            </div>
        </Modal>

        <!-- Border Color Modal -->
        <Modal
            v-model:open="showBorderColorModal"
            :title="
                borderColorTarget === 'cell'
                    ? 'Cell Border Color'
                    : 'Table Border Color'
            "
            :description="
                borderColorTarget === 'cell'
                    ? 'Choose a border color for the current cell.'
                    : 'Choose a border color for the entire table.'
            "
            size="md"
        >
            <div class="flex justify-center pb-2">
                <Sketch
                    v-model="borderColorValue"
                    class="w-full! max-w-none! shadow-none! bg-transparent! box-border!"
                    :preset-colors="[
                        '#000000',
                        '#ffffff',
                        '#F44336',
                        '#E91E63',
                        '#9C27B0',
                        '#673AB7',
                        '#3F51B5',
                        '#2196F3',
                        '#03A9F4',
                        '#00BCD4',
                        '#009688',
                        '#4CAF50',
                        '#8BC34A',
                        '#CDDC39',
                        '#FFEB3B',
                        '#FFC107',
                        '#FF9800',
                        '#FF5722',
                        '#795548',
                        '#9E9E9E',
                        '#607D8B',
                    ]"
                />
            </div>
            <div
                class="flex justify-end gap-3 pt-2 border-t border-(--border-default)"
            >
                <Button variant="ghost" @click="showBorderColorModal = false"
                    >Cancel</Button
                >
                <Button @click="confirmBorderColor">Apply Border Color</Button>
            </div>
        </Modal>
    </div>
</template>

<style>
.tiptap {
    padding: 0.75rem 1rem;
    outline: none;
    min-height: v-bind(minHeight);
}

/* Table Styling */
.tiptap table {
    border-collapse: collapse;
    margin: 1rem 0;
    width: 100%;
    table-layout: fixed;
}

.tiptap th,
.tiptap td {
    border: 1px solid var(--border-default);
    padding: 0.5rem 0.75rem;
    position: relative;
    min-width: 50px;
}

.tiptap th {
    background-color: var(--surface-secondary);
    font-weight: 600;
    text-align: left;
}

.tiptap td {
    background-color: var(--surface-elevated);
}

/* Table-level border color inheritance */
.tiptap table[data-border-color] th:not([style*="border-color"]),
.tiptap table[data-border-color] td:not([style*="border-color"]) {
    border-color: attr(data-border-color);
}

/* Use CSS custom property workaround for attr() limitation */
.tiptap table[data-border-color] {
    --table-border-color: var(--border-default);
}

.tiptap table[data-border-color=""] th,
.tiptap table[data-border-color=""] td {
    border-color: var(--border-default);
}

/* Selected cell highlight */
.tiptap .selectedCell {
    background-color: var(--color-primary-100);
}

.dark .tiptap .selectedCell {
    background-color: var(--color-primary-900);
}

/* Resize handle */
.tiptap .column-resize-handle {
    position: absolute;
    right: -2px;
    top: 0;
    bottom: 0;
    width: 4px;
    background-color: var(--interactive-primary);
    cursor: col-resize;
}
.tiptap {
    padding: 0.75rem 1rem;
    outline: none;
    min-height: v-bind(minHeight);
}

.tiptap p.is-editor-empty:first-child::before {
    content: attr(data-placeholder);
    float: left;
    color: var(--text-muted);
    pointer-events: none;
    height: 0;
}

.tiptap ul,
.tiptap ol {
    padding-left: 1.5rem;
}

.tiptap ul {
    list-style-type: disc;
}

.tiptap ol {
    list-style-type: decimal;
}

.tiptap blockquote {
    border-left: 3px solid var(--interactive-primary);
    padding-left: 1rem;
    margin-left: 0;
}

.tiptap pre {
    background: var(--surface-secondary);
    color: var(--text-primary);
    padding: 0.75rem 1rem;
    border-radius: 0.5rem;
    overflow-x: auto;
    font-family: var(--font-mono);
}

.tiptap code {
    background: var(--surface-secondary);
    color: var(--text-primary);
    padding: 0.125rem 0.25rem;
    border-radius: 0.25rem;
    font-size: 0.875em;
    font-family: var(--font-mono);
}

.tiptap pre code {
    background: none;
    padding: 0;
    color: inherit;
    font-size: inherit;
}

/* Color Picker Dark Mode Overrides */
.dark .vc-sketch {
    background-color: transparent !important;
    box-shadow: none !important;
}

.dark .vc-sketch .vc-input__input {
    background-color: var(--surface-secondary) !important;
    color: var(--text-primary) !important;
    box-shadow: none !important;
    border: 1px solid var(--border-default) !important;
}

.dark .vc-sketch .vc-input__label {
    color: var(--text-secondary) !important;
    text-transform: uppercase;
}

/* Fix preset swatches container if needed */
.dark .vc-sketch-presets {
    border-top-color: var(--border-default) !important;
}

/* Float Clearing for Images */
.tiptap {
    display: flow-root; /* Creates a new BFC to contain floats */
}

/* Clear floats on block-level elements that follow floated content */
.tiptap h1,
.tiptap h2,
.tiptap h3,
.tiptap h4,
.tiptap h5,
.tiptap h6,
.tiptap ul,
.tiptap ol,
.tiptap blockquote,
.tiptap pre,
.tiptap hr {
    clear: both;
}

/* Also clear paragraphs that don't contain floated images */
.tiptap p:not(:has([style*="float"])) {
    clear: both;
}

/* Target NodeViewWrapper with float styles - these are the image containers */
.tiptap [style*="float: left"] + p,
.tiptap [style*="float: right"] + p,
.tiptap [style*="float:left"] + p,
.tiptap [style*="float:right"] + p {
    /* These paragraphs wrap around the float, so don't clear them */
    clear: none;
}
</style>
