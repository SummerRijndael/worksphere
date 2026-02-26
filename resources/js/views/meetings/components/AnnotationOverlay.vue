<template>
    <div 
        class="annotation-overlay"
        :class="{ 'annotation-overlay--active': isAnnotating }"
    >
        <AnnotationLayer
            v-if="shouldRender"
            :is-local="isLocal"
            :participant-id="participantId"
            :initial-lines="elements"
            @update="handleAnnotationUpdate"
        />
    </div>
</template>

<script setup lang="ts">
import { ref, watch, computed, onMounted } from 'vue';
import { useMeetingStore } from '@/stores/meeting';
import AnnotationLayer from './AnnotationLayer.vue';

const props = defineProps<{
    participantId: string;
    isLocal: boolean;
}>();

const meetingStore = useMeetingStore();
const isAnnotating = ref(false);
const elements = ref<any[]>([]);

const shouldRender = computed(() => isAnnotating.value || !props.isLocal);

function handleAnnotationUpdate(data: any) {
    if (!props.isLocal) return;
    
    // Update local state so we can respond to 'request-sync' from late joiners
    if (data.type === 'new-stroke') {
        elements.value.push(data.stroke);
    } else if (data.type === 'clear') {
        elements.value = [];
    } else if (data.type === 'stroke-update') {
        elements.value = data.lines || [];
    }

    // Sync updates via meeting store
    meetingStore.sendAnnotationUpdate({
        ...data,
        participant_id: props.participantId
    });
}

onMounted(() => {
    // If we are a joiner seeing a screen share, request the initial state
    // We add a small delay to ensure the signaling channel is fully connected
    if (!props.isLocal) {
        setTimeout(() => {
            meetingStore.sendAnnotationUpdate({
                type: 'request-sync',
                target_participant_id: props.participantId
            });
        }, 500);
    }
});

// Watch for store changes to toggle annotation mode
watch(() => meetingStore.isAnnotating, (active) => {
    isAnnotating.value = active;
}, { immediate: true });

// Handle remote updates
defineExpose({
    handleRemoteUpdate(data: any) {
        const myId = props.participantId.toLowerCase();
        
        // If we are the presenter and someone requests sync, send them our state
        if (props.isLocal && data.type === 'request-sync') {
            const targetId = data.target_participant_id?.toLowerCase();
            console.log(`[AnnotationSync] Received request-sync from ${data.participant_id} targeting ${targetId}`);
            
            // Only respond if we are the one being targeted
            if (targetId === myId) {
                console.log(`[AnnotationSync] Responding to sync request with ${elements.value.length} strokes`);
                // Optimization: strip 'points' from lines before sending full-sync
                const cleanElements = elements.value.map(l => {
                    const lCopy = { ...l };
                    delete lCopy.points;
                    return lCopy;
                });
                
                meetingStore.sendAnnotationUpdate({
                    type: 'full-sync',
                    lines: cleanElements,
                    target_participant_id: data.participant_id // Direct it back to requester
                });
            }
            return;
        }

        if (props.isLocal) return;
        
        console.log(`[AnnotationSync] Processing remote update: ${data.type} from ${data.participant_id}`);

        if (data.type === 'new-stroke') {
            elements.value.push(data.stroke);
        } else if (data.type === 'full-sync') {
            // If targeted sync is used, verify we are the target
            if (data.target_participant_id && data.target_participant_id.toLowerCase() !== meetingStore.localParticipant?.public_id.toLowerCase()) {
                return;
            }
            console.log(`[AnnotationSync] Applying full-sync: ${data.lines?.length} strokes`);
            elements.value = data.lines || [];
        } else if (data.type === 'clear') {
            elements.value = [];
        } else if (data.type === 'stroke-update') {
            elements.value = data.lines || [];
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
</style>
