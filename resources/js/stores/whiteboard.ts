import { defineStore } from 'pinia';
import { ref, reactive, watch, computed } from 'vue';
import { useMeetingStore } from './meeting';

export interface WhiteboardElement {
    type: 'line' | 'rect' | 'circle' | 'text' | 'sticker';
    id: string;
    stroke?: string;
    strokeWidth?: number;
    points?: number[];
    normalizedPoints?: number[];
    nx?: number;
    ny?: number;
    nw?: number;
    nh?: number;
    nr?: number;
    text?: string;
    fill?: string;
    nSize?: number;
    fontStyle?: string;
    stickerType?: string;
    scaleX?: number;
    scaleY?: number;
    rotation?: number;
    globalCompositeOperation?: string;
    [key: string]: any;
}

export const useWhiteboardStore = defineStore('whiteboard', () => {
    const meetingStore = useMeetingStore();
    
    // Core State
    const elements = ref<WhiteboardElement[]>([]);
    const history = ref<string[]>([]);
    const redoStack = ref<string[]>([]);
    const isVisible = ref(false);
    const backgroundColor = ref('#ffffff');
    const selectedId = ref<string | null>(null);
    const scope = ref<'global' | 'host'>('global');
    const collaborators = reactive(new Map<string, { x: number, y: number, name: string, color: string, lastSeen: number }>());

    // --- Sync Logic ---

    function handleWhiteboardSignal(data: any) {
        switch (data.type) {
            case 'new-stroke':
                if (data.stroke) {
                    // Check if element already exists (prevent duplicates from Echo)
                    if (!elements.value.find(el => el.id === data.stroke.id)) {
                        elements.value.push(data.stroke);
                        saveToHistory();
                    }
                }
                break;
            case 'full-sync':
                if (data.target_participant_id && data.target_participant_id !== meetingStore.localParticipant?.public_id) {
                    return;
                }
                elements.value = data.lines || [];
                saveToHistory();
                break;
            case 'clear':
                elements.value = [];
                saveToHistory();
                break;
            case 'element-update':
                const idx = elements.value.findIndex(el => el.id === data.elementId);
                if (idx !== -1 && data.updates) {
                    elements.value[idx] = { ...elements.value[idx], ...data.updates };
                    // Don't save to history for every move, only on dragEnd (handled locally)
                }
                break;
            case 'stroke-update':
                elements.value = data.lines || [];
                if (data.backgroundColor) backgroundColor.value = data.backgroundColor;
                saveToHistory();
                break;
            case 'background-update':
                backgroundColor.value = data.color;
                saveToHistory();
                break;
            case 'request-sync':
                if (meetingStore.isHost) {
                    meetingStore.sendAnnotationUpdate({
                        type: 'full-sync',
                        lines: elements.value,
                        target_participant_id: data.participant_id
                    });
                }
                break;
            case 'cursor-move':
                if (data.participant_id !== meetingStore.localParticipant?.public_id) {
                    const p = meetingStore.allParticipants.find((p: any) => p.public_id === data.participant_id);
                    collaborators.set(data.participant_id, {
                        x: data.nx,
                        y: data.ny,
                        name: p ? (p.display_name || p.name) : 'Collaborator',
                        color: data.color || '#8ab4f8',
                        lastSeen: Date.now()
                    });
                }
                break;
            case 'scope-update':
                scope.value = data.scope;
                break;
        }
    }

    function setBackgroundColor(color: string) {
        backgroundColor.value = color;
        saveToHistory();
        
        meetingStore.sendAnnotationUpdate({
            type: 'background-update',
            color: color,
            participant_id: meetingStore.localParticipant?.public_id
        });
    }

    function setScope(newScope: 'global' | 'host') {
        if (!meetingStore.isHost) return;
        scope.value = newScope;
        meetingStore.sendAnnotationUpdate({
            type: 'scope-update',
            scope: newScope,
            participant_id: meetingStore.localParticipant?.public_id
        });
    }

    function addElement(el: WhiteboardElement) {
        elements.value.push(el);
        saveToHistory();
        
        meetingStore.sendAnnotationUpdate({
            type: 'new-stroke',
            stroke: el,
            participant_id: meetingStore.localParticipant?.public_id
        });
    }

    function updateElement(id: string, updates: Partial<WhiteboardElement>, skipNotify = false) {
        const idx = elements.value.findIndex(el => el.id === id);
        if (idx !== -1) {
            elements.value[idx] = { ...elements.value[idx], ...updates };
            
            if (!skipNotify) {
                meetingStore.sendAnnotationUpdate({
                    type: 'element-update',
                    elementId: id,
                    updates,
                    participant_id: meetingStore.localParticipant?.public_id
                });
            }
        }
    }

    function updateElements(newElements: WhiteboardElement[]) {
        elements.value = newElements;
        saveToHistory();
        
        meetingStore.sendAnnotationUpdate({
            type: 'stroke-update',
            lines: elements.value,
            participant_id: meetingStore.localParticipant?.public_id
        });
    }

    function clear() {
        elements.value = [];
        selectedId.value = null;
        saveToHistory();
        
        meetingStore.sendAnnotationUpdate({
            type: 'clear',
            participant_id: meetingStore.localParticipant?.public_id
        });
    }

    function reorderElement(id: string, action: 'front' | 'back' | 'forward' | 'backward') {
        const index = elements.value.findIndex(el => el.id === id);
        if (index === -1) return;

        const element = elements.value[index];
        const newElements = [...elements.value];
        newElements.splice(index, 1);

        if (action === 'front') {
            newElements.push(element);
        } else if (action === 'back') {
            newElements.unshift(element);
        } else if (action === 'forward') {
            const nextIndex = Math.min(index + 1, elements.value.length - 1);
            newElements.splice(nextIndex, 0, element);
        } else if (action === 'backward') {
            const prevIndex = Math.max(index - 1, 0);
            newElements.splice(prevIndex, 0, element);
        }

        updateElements(newElements);
    }

    // --- History ---

    function saveToHistory() {
        const state = JSON.stringify(elements.value);
        if (history.value.length > 0 && history.value[history.value.length - 1] === state) return;
        
        history.value.push(state);
        if (history.value.length > 50) history.value.shift();
        redoStack.value = [];
    }

    function undo() {
        if (history.value.length <= 1) {
            if (history.value.length === 1) {
                const current = history.value.pop()!;
                redoStack.value.push(current);
                elements.value = [];
                broadcastUpdate();
            }
            return;
        }
        
        const current = history.value.pop()!;
        redoStack.value.push(current);
        const prev = JSON.parse(history.value[history.value.length - 1]);
        elements.value = prev;
        broadcastUpdate();
    }

    function redo() {
        if (redoStack.value.length === 0) return;
        
        const next = redoStack.value.pop()!;
        history.value.push(next);
        elements.value = JSON.parse(next);
        broadcastUpdate();
    }

    function broadcastUpdate() {
        meetingStore.sendAnnotationUpdate({
            type: 'stroke-update',
            lines: elements.value,
            participant_id: meetingStore.localParticipant?.public_id
        });
    }

    function requestSync() {
        meetingStore.sendAnnotationUpdate({
            type: 'request-sync',
            participant_id: meetingStore.localParticipant?.public_id
        });
    }

    function sendCursorMove(nx: number, ny: number, color: string) {
        meetingStore.sendAnnotationUpdate({
            type: 'cursor-move',
            nx,
            ny,
            color,
            participant_id: meetingStore.localParticipant?.public_id
        });
    }
    
    const canDraw = computed(() => {
        if (scope.value === 'global') return true;
        return meetingStore.isHost;
    });

    // --- Watchers ---
    watch(() => meetingStore.lastAnnotationSignal, (data) => {
        if (!data) return;
        handleWhiteboardSignal(data);
    });

    // Cleanup stale collaborators
    setInterval(() => {
        const now = Date.now();
        for (const [id, data] of collaborators.entries()) {
            if (now - data.lastSeen > 5000) {
                collaborators.delete(id);
            }
        }
    }, 2000);

    return {
        elements,
        isVisible,
        backgroundColor,
        selectedId,
        handleWhiteboardSignal,
        setBackgroundColor,
        addElement,
        updateElement,
        updateElements,
        clear,
        reorderElement,
        undo,
        redo,
        saveToHistory,
        requestSync,
        collaborators,
        sendCursorMove,
        scope,
        setScope,
        canDraw
    };
});

/*
- [x] Whiteboard Phase 2: Advanced Tools & Polish
    - [x] Implement Select tool (Move/Scale/Rotate)
    - [x] Add Text tool & Stickers library
    - [x] Implement Layer Management (Context Menu)
    - [x] Add Image Import (Secure, Data URL)
    - [x] Add Presence Indicators (Collaborator Cursors)
    - [x] Enhanced Color Picker & Default Theme
    - [x] Implement Board Color & Paint Bucket
    - [x] Export as Image feature
- [/] Breakout Rooms (Phase 3)
    - [/] Review Design Concept with user
    - [ ] Create detailed implementation plan
    - [ ] Implement breakout signaling & switching
*/
