<script setup>
import { computed } from 'vue';
import { format } from 'date-fns';
import { MapPin, Clock } from 'lucide-vue-next';

const props = defineProps({
    event: Object,
    position: Object // { x, y }
});

const formattedTime = computed(() => {
    if (!props.event) return '';
    if (props.event.is_all_day) return 'All Day';
    
    const start = props.event.start_time instanceof Date ? props.event.start_time : new Date(props.event.start_time);
    const end = props.event.end_time instanceof Date ? props.event.end_time : new Date(props.event.end_time);
    
    return `${format(start, 'h:mm a')} - ${format(end, 'h:mm a')}`;
});

const attendees = computed(() => {
    return props.event.attendees || props.event.extendedProps?.attendees || [];
});

const style = computed(() => ({
    top: `${props.position?.y + 12}px`,
    left: `${props.position?.x + 12}px`,
}));
</script>

<template>
    <div 
        v-if="event"
        class="fixed z-50 w-80 bg-white dark:bg-gray-900 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-800 p-4 pointer-events-none transform transition-all duration-200 ease-in-out flex flex-col gap-3"
        :style="style"
    >
        <!-- Header -->
        <div>
            <h3 class="text-base font-bold text-gray-900 dark:text-white leading-snug mb-1">
                {{ event.title }}
            </h3>
            <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <div class="flex items-center gap-1.5 bg-gray-50 dark:bg-gray-800 px-1.5 py-0.5 rounded">
                    <Clock class="w-3 h-3" />
                    <span class="font-medium">{{ formattedTime }}</span>
                </div>
                <div v-if="event.location" class="flex items-center gap-1.5">
                    <MapPin class="w-3 h-3" />
                    <span class="truncate max-w-[120px]">{{ event.location }}</span>
                </div>
            </div>
        </div>

        <!-- Description Snippet -->
        <div v-if="event.extendedProps?.description" class="text-xs text-gray-600 dark:text-gray-300 line-clamp-2 leading-relaxed">
            {{ event.extendedProps.description }}
        </div>

        <!-- Attendees -->
        <div v-if="attendees.length > 0" class="flex items-center justify-between pt-3 border-t border-gray-100 dark:border-gray-800">
            <div class="flex -space-x-2 pl-1">
                <template v-for="(attendee, i) in attendees.slice(0, 5)" :key="i">
                    <!-- Avatar -->
                    <img 
                        v-if="attendee.avatar_url" 
                        :src="attendee.avatar_url" 
                        class="inline-block h-7 w-7 rounded-full ring-2 ring-white dark:ring-gray-900 bg-white dark:bg-gray-800 object-cover"
                        :title="attendee.name"
                    />
                    <!-- Initials Fallback -->
                    <div 
                        v-else 
                        class="inline-flex h-7 w-7 items-center justify-center rounded-full ring-2 ring-white dark:ring-gray-900 bg-gray-100 dark:bg-gray-800 text-[10px] font-bold text-gray-600 dark:text-gray-300"
                    >
                        {{ attendee.initials }}
                    </div>
                </template>
                <!-- Overflow -->
                <div v-if="attendees.length > 5" class="inline-flex h-7 w-7 items-center justify-center rounded-full ring-2 ring-white dark:ring-gray-900 bg-gray-50 dark:bg-gray-800 text-[10px] font-medium text-gray-500 dark:text-gray-400">
                    +{{ attendees.length - 5 }}
                </div>
            </div>
            <span class="text-[10px] uppercase font-semibold text-gray-400 tracking-wider">
                {{ attendees.length }} Participant{{ attendees.length !== 1 ? 's' : '' }}
            </span>
        </div>
    </div>
</template>
