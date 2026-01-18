import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import axios from 'axios';

export interface Note {
    id: string; // public_id
    public_id: string;
    title: string | null;
    content: string | null;
    color: string;
    is_pinned: boolean;
    position: number;
    created_at: string;
    updated_at: string;
}

export const useNoteStore = defineStore('note', () => {
    const notes = ref<Note[]>([]);
    const meta = ref<any>({});
    const links = ref<any>({});
    const isLoading = ref(false);
    
    // Sort notes: Pinned first, then by position (asc), then by updated_at (desc)
    const sortedNotes = computed(() => {
        return [...notes.value].sort((a, b) => {
            if (a.is_pinned !== b.is_pinned) return a.is_pinned ? -1 : 1;
            if (a.position !== b.position) return a.position - b.position;
            return new Date(b.updated_at).getTime() - new Date(a.updated_at).getTime();
        });
    });

    async function fetchNotes(page = 1, perPage = 20) {
        isLoading.value = true;
        try {
            const response = await axios.get('/api/notes', {
                params: { page, per_page: perPage }
            });
            notes.value = response.data.data;
            meta.value = response.data.meta;
            links.value = response.data.links;
        } catch (error) {
            console.error('Failed to fetch notes:', error);
        } finally {
            isLoading.value = false;
        }
    }

    async function createNote(data: Partial<Note>) {
        const response = await axios.post('/api/notes', data);
        const newNote = response.data.data;
        notes.value.unshift(newNote); // Optimistic add, though realtime should also catch it
        return newNote;
    }

    async function updateNote(publicId: string, data: Partial<Note>) {
        // Optimistic update
        const index = notes.value.findIndex(n => n.public_id === publicId);
        if (index !== -1) {
            notes.value[index] = { ...notes.value[index], ...data };
        }

        const response = await axios.put(`/api/notes/${publicId}`, data);
        const updatedNote = response.data.data;
        
        if (index !== -1) {
            notes.value[index] = updatedNote;
        }
        return updatedNote;
    }

    async function deleteNote(publicId: string) {
        // Optimistic delete
        notes.value = notes.value.filter(n => n.public_id !== publicId);

        await axios.delete(`/api/notes/${publicId}`);
    }

    async function reorderNotes(order: string[]) {
        // order is array of public_ids
        // Optimistic update of positions? 
        // We'd strictly need to map indices to positions.
        // For now, let's just send the order.
        await axios.post('/api/notes/reorder', { order });
    }

    async function bulkDeleteNotes(ids: string[]) {
        // Optimistic delete
        notes.value = notes.value.filter(n => !ids.includes(n.public_id));
        await axios.post('/api/notes/bulk-delete', { ids });
    }

    async function bulkUpdateNotes(ids: string[], data: { is_pinned: boolean }) {
         // Optimistic update
        notes.value = notes.value.map(n => {
            if (ids.includes(n.public_id)) {
                return { ...n, ...data };
            }
            return n;
        });
        await axios.post('/api/notes/bulk-update', { ids, ...data });
    }

    // Realtime Handlers
    function handleNoteCreated(e: { note: Note }) {
        // Check if exists
        if (!notes.value.find(n => n.public_id === e.note.public_id)) {
            notes.value.unshift(e.note);
        }
    }

    function handleNoteUpdated(e: { note: Note }) {
        const index = notes.value.findIndex(n => n.public_id === e.note.public_id);
        if (index !== -1) {
            notes.value[index] = e.note;
        } else {
            notes.value.unshift(e.note);
        }
    }

    function handleNoteDeleted(e: { notePublicId: string; userPublicId: string }) {
        notes.value = notes.value.filter(n => n.public_id !== e.notePublicId);
    }

    return {
        notes,
        sortedNotes,
        isLoading,
        fetchNotes,
        createNote,
        updateNote,
        deleteNote,
        reorderNotes,
        bulkDeleteNotes,
        bulkUpdateNotes,
        handleNoteCreated,
        handleNoteUpdated,
        handleNoteDeleted,
        meta,
        links
    };
});
