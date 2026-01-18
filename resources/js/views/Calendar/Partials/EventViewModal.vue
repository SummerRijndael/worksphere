<script setup>
import { computed } from 'vue';
import { format } from 'date-fns';
import { MapPin, Clock, AlignLeft, Calendar as CalendarIcon, Users, Edit, X, Trash2, Mail } from 'lucide-vue-next';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import Badge from '@/components/ui/Badge.vue'; // Assuming Badge component exists, or we replace

const props = defineProps({
    open: Boolean,
    event: Object,
});

const emit = defineEmits(['update:open', 'edit', 'delete']);

const formattedDate = computed(() => {
    if (!props.event) return '';
    const start = new Date(props.event.start_time);
    return format(start, 'EEEE, MMMM d, yyyy');
});

const formattedTime = computed(() => {
    if (!props.event) return '';
    if (props.event.is_all_day) return 'All Day';
    
    const start = new Date(props.event.start_time);
    const end = new Date(props.event.end_time);
    return `${format(start, 'h:mm a')} - ${format(end, 'h:mm a')}`;
});

const attendees = computed(() => {
    if (!props.event) return [];
    const users = props.event.attendees || [];
    const emails = props.event.external_attendees || [];
    return [...users, ...emails];
});

// Helper for status badge
const getStatusColor = (status) => {
    switch (status) {
        case 'accepted': return 'success';
        case 'declined': return 'danger';
        case 'tentative': return 'warning';
        case 'pending': return 'warning'; // Changed pending to warning/yellow for visibility but distinct from success
        default: return 'secondary';
    }
};

const getStatusLabel = (status) => {
    switch(status) {
        case 'pending': return 'Invited';
        default: return status;
    }
}


</script>

<template>
    <Modal
        :open="open"
        title="Event Details"
        size="lg"
        @update:open="$emit('update:open', $event)"
    >
        <div v-if="event" class="space-y-6">
            <!-- Header Info -->
            <div class="flex items-start gap-5">
                <div class="h-14 w-14 rounded-2xl bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0 shadow-sm border border-blue-100 dark:border-blue-500/20">
                    <CalendarIcon class="w-7 h-7" />
                </div>
                <div class="flex-1 min-w-0 pt-0.5">
                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white leading-tight mb-2">{{ event.title }}</h2>
                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                            <Clock class="w-4 h-4 text-gray-400 dark:text-gray-500" />
                            <span class="font-medium">{{ formattedDate }}</span>
                            <span class="text-gray-300 dark:text-gray-600">•</span>
                            <span>{{ formattedTime }}</span>
                        </div>
                        <div v-if="event.location" class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                            <MapPin class="w-4 h-4 text-gray-400 dark:text-gray-500" />
                            <span>{{ event.location }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div v-if="event.description" class="p-4 rounded-xl bg-gray-50 dark:bg-gray-800/50 border border-gray-100 dark:border-gray-800">
                <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2 flex items-center gap-2">
                    Description
                </h3>
                <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap leading-relaxed">
                    {{ event.description }}
                </p>
            </div>

            <!-- Participants -->
            <div v-if="attendees.length > 0">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                    Participants 
                    <span class="px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-800 text-xs text-gray-600 dark:text-gray-400">{{ attendees.length }}</span>
                </h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <template v-for="(p, i) in attendees" :key="i">
                        <!-- User -->
                        <div v-if="!p.includes && p.name" class="group flex items-center gap-3 p-2.5 rounded-xl border border-gray-100 dark:border-gray-800 hover:border-gray-200 dark:hover:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-all">
                            <img v-if="p.avatar_url" :src="p.avatar_url" class="w-9 h-9 rounded-full ring-2 ring-white dark:ring-gray-900" />
                            <div v-else class="w-9 h-9 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-xs font-bold text-gray-600 dark:text-gray-400 ring-2 ring-white dark:ring-gray-900">
                                {{ p.initials }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">{{ p.name }}</div>
                                    <span v-if="p.status" :class="[
                                        'px-1.5 py-0.5 rounded text-[10px] font-medium capitalize',
                                        p.status === 'accepted' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 
                                        p.status === 'declined' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' :
                                        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                                    ]">
                                        {{ getStatusLabel(p.status) }}
                                    </span>
                                </div>
                                <div class="text-xs text-gray-500 truncate">{{ p.email }}</div>
                            </div>
                        </div>
                        <!-- External Email -->
                        <div v-else class="flex items-center gap-3 p-2.5 rounded-xl border border-gray-100 dark:border-gray-800 hover:border-gray-200 dark:hover:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-all">
                             <div class="w-9 h-9 rounded-full bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400 flex items-center justify-center ring-2 ring-white dark:ring-gray-900">
                                <Mail class="w-4 h-4" />
                             </div>
                             <div class="flex-1 min-w-0">
                                <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">{{ p }}</div>
                                <div class="text-xs text-orange-600 dark:text-orange-400 font-medium">External Guest</div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-between w-full pt-2">
                <Button 
                    variant="danger" 
                    variant-type="ghost"
                    size="sm"
                    class="text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20"
                    @click="$emit('delete', event)"
                >
                    <Trash2 class="w-4 h-4 mr-2" />
                    Delete Event
                </Button>
                <div class="flex gap-3">
                    <Button variant="ghost" @click="$emit('update:open', false)">Close</Button>
                    <Button variant="primary" @click="$emit('edit', event)" class="min-w-[100px]">
                        <Edit class="w-4 h-4 mr-2" />
                        Edit
                    </Button>
                </div>
            </div>
        </template>
    </Modal>
</template>
