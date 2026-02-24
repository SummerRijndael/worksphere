<script setup lang="ts">
import { ref, reactive, onMounted, onUnmounted } from 'vue';
import { useMeetingStore } from '@/stores/meeting';
import { Icon } from '@/components/ui';

const meetingStore = useMeetingStore();

// Draggable Logic
const position = reactive({ x: 0, y: 80 });
const isDragging = ref(false);
const dragOffset = reactive({ x: 0, y: 0 });

const startDrag = (event: MouseEvent | TouchEvent) => {
    const target = event.target as HTMLElement;
    // Don't drag if clicking buttons, selects, or inputs
    if (target.closest("button") || target.closest("select") || target.closest("input")) return;

    isDragging.value = true;
    const clientX = "touches" in event ? (event as TouchEvent).touches[0].clientX : (event as MouseEvent).clientX;
    const clientY = "touches" in event ? (event as TouchEvent).touches[0].clientY : (event as MouseEvent).clientY;

    const panelEl = document.querySelector(".meeting-dev-panel") as HTMLElement;
    if (panelEl) {
        const rect = panelEl.getBoundingClientRect();
        dragOffset.x = clientX - rect.left;
        dragOffset.y = clientY - rect.top;
    }

    window.addEventListener("mousemove", onDrag);
    window.addEventListener("mouseup", stopDrag);
    window.addEventListener("touchmove", onDrag, { passive: false });
    window.addEventListener("touchend", stopDrag);
};

const onDrag = (event: MouseEvent | TouchEvent) => {
    if (!isDragging.value) return;
    
    // Prevent scrolling on touch
    if (event.type === 'touchmove') event.preventDefault();

    const clientX = "touches" in event ? (event as TouchEvent).touches[0].clientX : (event as MouseEvent).clientX;
    const clientY = "touches" in event ? (event as TouchEvent).touches[0].clientY : (event as MouseEvent).clientY;

    let newX = clientX - dragOffset.x;
    let newY = clientY - dragOffset.y;

    // Bounds checking
    const padding = 10;
    const panelEl = document.querySelector(".meeting-dev-panel") as HTMLElement;
    if (panelEl) {
        const maxX = window.innerWidth - panelEl.offsetWidth - padding;
        const maxY = window.innerHeight - panelEl.offsetHeight - padding;
        
        newX = Math.max(padding, Math.min(newX, maxX));
        newY = Math.max(padding, Math.min(newY, maxY));
    }

    position.x = newX;
    position.y = newY;
};

const stopDrag = () => {
    isDragging.value = false;
    window.removeEventListener("mousemove", onDrag);
    window.removeEventListener("mouseup", stopDrag);
    window.removeEventListener("touchmove", onDrag);
    window.removeEventListener("touchend", stopDrag);
};

onMounted(() => {
    // Initial position: top right
    setTimeout(() => {
        const panelEl = document.querySelector(".meeting-dev-panel") as HTMLElement;
        if (panelEl) {
            position.x = window.innerWidth - panelEl.offsetWidth - 20;
            position.y = 80;
        }
    }, 100);
});

onUnmounted(() => {
    stopDrag();
});
</script>

<template>
    <div 
        v-if="meetingStore.isDevMode" 
        class="meeting-dev-panel"
        :style="{
            left: `${position.x}px`,
            top: `${position.y}px`,
            transition: isDragging ? 'none' : 'all 0.3s cubic-bezier(0.2, 0, 0, 1)'
        }"
        @mousedown="startDrag"
        @touchstart="startDrag"
    >
        <div class="panel-header" @mousedown="startDrag" @touchstart="startDrag">
            <div class="header-main">
                <Icon name="terminal" size="16" />
                <span>Debugger</span>
            </div>
            <button @click.stop="meetingStore.toggleDevMode" class="close-btn">
                <Icon name="x" size="14" />
            </button>
        </div>

        <div class="panel-content">
            <!-- Simulated Role -->
            <div class="debug-section">
                <label>Simulate Role</label>
                <div class="button-group">
                    <button 
                        :class="{ active: meetingStore.simulatedRole === 'host' }" 
                        @click="meetingStore.setSimulatedRole('host')"
                    >
                        Host
                    </button>
                    <button 
                        :class="{ active: meetingStore.simulatedRole === 'participant' }" 
                        @click="meetingStore.setSimulatedRole('participant')"
                    >
                        Participant
                    </button>
                    <button 
                        :class="{ active: meetingStore.simulatedRole === null }" 
                        @click="meetingStore.setSimulatedRole(null)"
                    >
                        Reset
                    </button>
                </div>
            </div>

            <!-- Participant Simulation -->
            <div class="debug-section">
                <label>Simulation ({{ meetingStore.allParticipants.length }} total)</label>
                <div class="button-group">
                    <button @click="meetingStore.addMockParticipant" class="action-btn">
                        <Icon name="user-plus" size="14" /> Add Mock
                    </button>
                    <button 
                        @click="meetingStore.removeMockParticipant" 
                        class="action-btn"
                        :disabled="meetingStore.mockParticipants.length === 0"
                    >
                        <Icon name="user-minus" size="14" /> Remove
                    </button>
                </div>
            </div>

            <!-- Active Speaker Simulation -->
            <div class="debug-section">
                <label>Simulate Speaker</label>
                <select 
                    class="debug-select"
                    :value="meetingStore.activeSpeakerId"
                    @change="meetingStore.activeSpeakerId = ($event.target as HTMLSelectElement).value || null"
                >
                    <option value="">None</option>
                    <option 
                        v-for="p in meetingStore.allParticipants" 
                        :key="p.public_id" 
                        :value="p.public_id"
                    >
                        {{ p.user?.name || p.metadata?.guest_name || p.public_id }}
                    </option>
                </select>
            </div>

            <div class="debug-info">
              <p>Real: {{ meetingStore.participants.length }}</p>
              <p>Mocks: {{ meetingStore.mockParticipants.length }}</p>
              <p>Effective Role: {{ meetingStore.isHost ? 'Host' : 'Participant' }}</p>
            </div>

            <div class="debug-section" style="margin-top: 12px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 12px;">
                <button @click="meetingStore.resetSimulation" class="action-btn w-full reset-all">
                    <Icon name="refresh-cw" size="14" /> Reset Simulation
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.meeting-dev-panel {
    position: fixed;
    z-index: 9999;
    width: 240px;
    background: rgba(15, 23, 42, 0.9);
    backdrop-filter: blur(16px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 10px 10px -5px rgba(0, 0, 0, 0.3);
    overflow: hidden;
    user-select: none;
    cursor: grab;
}

.meeting-dev-panel:active {
    cursor: grabbing;
}

.panel-header {
    background: rgba(255, 255, 255, 0.05);
    padding: 8px 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    cursor: grab;
}

.header-main {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #94a3b8;
}

.close-btn {
    background: transparent;
    border: none;
    color: rgba(255, 255, 255, 0.5);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.close-btn:hover {
    color: white;
}

.panel-content {
    padding: 12px;
}

.debug-section {
    margin-bottom: 16px;
}

.debug-section label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    color: rgba(255, 255, 255, 0.5);
    margin-bottom: 8px;
    font-weight: 700;
    letter-spacing: 0.05em;
}

.button-group {
    display: flex;
    gap: 4px;
    background: rgba(0, 0, 0, 0.2);
    padding: 2px;
    border-radius: 6px;
}

.button-group button {
    flex: 1;
    background: transparent;
    border: none;
    color: rgba(255, 255, 255, 0.7);
    padding: 6px 4px;
    font-size: 11px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.button-group button:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.05);
    color: white;
}

.button-group button.active {
    background: #4f46e5;
    color: white;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: rgba(255, 255, 255, 0.1) !important;
}

.action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.debug-info {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.4);
    border-top: 1px solid rgba(255, 255, 255, 0.05);
    padding-top: 8px;
    margin-top: 8px;
}

.debug-info p {
    margin: 2px 0;
}

.debug-select {
    width: 100%;
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 6px;
    color: white;
    padding: 6px 8px;
    font-size: 11px;
    outline: none;
    cursor: pointer;
}

.debug-select option {
    background: #0f172a;
    color: white;
}

.reset-all {
    background: rgba(239, 68, 68, 0.2) !important;
    border: 1px solid rgba(239, 68, 68, 0.3) !important;
}

.reset-all:hover {
    background: rgba(239, 68, 68, 0.4) !important;
}

.w-full {
    width: 100%;
}
</style>
