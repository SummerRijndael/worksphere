<script setup lang="ts">
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import { watch, onBeforeUnmount } from 'vue';
import { Bold, Italic, Underline as UnderlineIcon, Strikethrough, List, ListOrdered, Quote } from 'lucide-vue-next';

const props = defineProps<{
    modelValue: string;
    editable?: boolean;
    placeholder?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void;
    (e: 'save'): void;
}>();

const editor = useEditor({
    content: props.modelValue,
    editable: props.editable ?? true,
    extensions: [
        StarterKit,
        Underline,
        Link,
        Placeholder.configure({
            placeholder: props.placeholder || 'Write a note...',
        }),
    ],
    editorProps: {
        attributes: {
            class: 'prose prose-sm dark:prose-invert focus:outline-none max-w-none min-h-[200px] p-4',
        },
    },
    onUpdate: ({ editor }) => {
        emit('update:modelValue', editor.getHTML());
    },
});

watch(() => props.modelValue, (newValue) => {
    if (editor.value && newValue !== editor.value.getHTML()) {
        editor.value.commands.setContent(newValue, false);
    }
});

watch(() => props.editable, (val) => {
    editor.value?.setEditable(val ?? true);
});

onBeforeUnmount(() => {
    editor.value?.destroy();
});
</script>

<template>
    <div class="note-editor border border-[var(--border-default)] rounded-xl overflow-hidden bg-[var(--surface-primary)] flex flex-col h-full shadow-sm group focus-within:ring-1 focus-within:ring-[var(--border-active)] transition-shadow duration-200">
        <div v-if="editor && editable" class="rounded-t-xl border-b border-[var(--border-default)] px-3 py-2 flex gap-1 items-center bg-[var(--surface-secondary)]/50 backdrop-blur-sm sticky top-0 z-10">
             <!-- Toolbar -->
            <button @click="editor.chain().focus().toggleBold().run()" :class="['p-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)] transition-all active:scale-95', editor.isActive('bold') ? 'bg-[var(--surface-active)] text-[var(--text-primary)] font-medium bg-white dark:bg-zinc-700 shadow-sm border border-[var(--border-default)]' : '']" title="Bold">
                <Bold class="w-4 h-4" />
            </button>
            <button @click="editor.chain().focus().toggleItalic().run()" :class="['p-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)] transition-all active:scale-95', editor.isActive('italic') ? 'bg-[var(--surface-active)] text-[var(--text-primary)] font-medium bg-white dark:bg-zinc-700 shadow-sm border border-[var(--border-default)]' : '']" title="Italic">
                <Italic class="w-4 h-4" />
            </button>
            <button @click="editor.chain().focus().toggleUnderline().run()" :class="['p-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)] transition-all active:scale-95', editor.isActive('underline') ? 'bg-[var(--surface-active)] text-[var(--text-primary)] font-medium bg-white dark:bg-zinc-700 shadow-sm border border-[var(--border-default)]' : '']" title="Underline">
                <UnderlineIcon class="w-4 h-4" />
            </button>
            <button @click="editor.chain().focus().toggleStrike().run()" :class="['p-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)] transition-all active:scale-95', editor.isActive('strike') ? 'bg-[var(--surface-active)] text-[var(--text-primary)] font-medium bg-white dark:bg-zinc-700 shadow-sm border border-[var(--border-default)]' : '']" title="Strikethrough">
                <Strikethrough class="w-4 h-4" />
            </button>
            
            <div class="w-px h-5 bg-[var(--border-default)] mx-2"></div>
            
            <button @click="editor.chain().focus().toggleBulletList().run()" :class="['p-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)] transition-all active:scale-95', editor.isActive('bulletList') ? 'bg-[var(--surface-active)] text-[var(--text-primary)] font-medium bg-white dark:bg-zinc-700 shadow-sm border border-[var(--border-default)]' : '']" title="Bullet List">
                <List class="w-4 h-4" />
            </button>
            <button @click="editor.chain().focus().toggleOrderedList().run()" :class="['p-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)] transition-all active:scale-95', editor.isActive('orderedList') ? 'bg-[var(--surface-active)] text-[var(--text-primary)] font-medium bg-white dark:bg-zinc-700 shadow-sm border border-[var(--border-default)]' : '']" title="Ordered List">
                <ListOrdered class="w-4 h-4" />
            </button>
            <button @click="editor.chain().focus().toggleBlockquote().run()" :class="['p-2 rounded-lg text-[var(--text-secondary)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-primary)] transition-all active:scale-95', editor.isActive('blockquote') ? 'bg-[var(--surface-active)] text-[var(--text-primary)] font-medium bg-white dark:bg-zinc-700 shadow-sm border border-[var(--border-default)]' : '']" title="Quote">
                <Quote class="w-4 h-4" />
            </button>
        </div>
        <editor-content :editor="editor" class="flex-1 overflow-y-auto" />
    </div>
</template>

