<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Modal, Input, Button, Switch, StatusBadge, Avatar, TimezoneSelect } from '@/components/ui';
import ParticipantSelector from '@/components/ui/ParticipantSelector.vue';
import { fromZonedTime, toZonedTime } from 'date-fns-tz';
import { format } from 'date-fns';
import { Mail, Download, Calendar, Send } from 'lucide-vue-next';
import api from '@/lib/api';
import { useToast } from '@/composables/useToast';

interface TeamEvent {
    id: string;
    title: string;
    description: string;
    start_time: string; // ISO string (UTC from server)
    end_time?: string;
    is_all_day: boolean;
    color: string;
    location: string;
    participants_details?: Array<{
        id: number;
        name: string;
        avatar_url: string;
        status: string;
    }>;
    // FullCalendar event properties (mapped from server data)
    start?: string;
    end?: string;
    allDay?: boolean;
    backgroundColor?: string;
    extendedProps?: {
        location?: string;
        reminder_minutes_before?: number | null;
        participants?: number[];
    };
}

const props = defineProps<{
    open: boolean;
    event?: TeamEvent | null; // If editing
    loading?: boolean;
    teamMembers?: Array<any>;
    teamId?: string; // Team public_id for API calls
}>();

const emit = defineEmits(['update:open', 'save', 'delete', 'invite']);

interface ParticipantItem {
    type: 'user' | 'email';
    id?: string;
    email?: string;
    name?: string;
    avatar?: string;
}

interface EventForm {
    title: string;
    description: string;
    start_time: string;
    end_time: string;
    is_all_day: boolean;
    color: string;
    location: string;
    reminder_minutes_before: number | null;
    participants: ParticipantItem[];
    timezone: string;
}

const form = ref<EventForm>({
    title: '',
    description: '',
    start_time: '',
    end_time: '',
    is_all_day: false,
    color: '#8B5CF6',
    location: '',
    reminder_minutes_before: null,
    participants: [],
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
});

const sendInvite = ref(false);

const participantsFetchUrl = computed(() => {
    return props.teamId ? `/api/teams/${props.teamId}/participants` : '/api/users';
});

const colors = [
    '#3B82F6', // Blue
    '#10B981', // Green
    '#8B5CF6', // Purple
    '#F59E0B', // Amber
    '#EF4444', // Red
    '#EC4899', // Pink
    '#6366F1', // Indigo
];

// Helper: Convert UTC Date (ISO) -> "YYYY-MM-DDTHH:mm" in Specific Timezone
const toFormFormat = (isoString: string, timeZone: string) => {
    if (!isoString) return '';
    try {
        const utcDate = new Date(isoString);
        const zoned = toZonedTime(utcDate, timeZone);
        return format(zoned, "yyyy-MM-dd'T'HH:mm");
    } catch (e) {
        console.error('Date conversion error', e);
        return isoString.slice(0, 16);
    }
};

// Helper: Convert "YYYY-MM-DDTHH:mm" (User Time) -> UTC ISO String
const toISO = (formDateStr: string, timeZone: string) => {
    if (!formDateStr) return null;
    try {
        const zonedDate = fromZonedTime(formDateStr, timeZone);
        return zonedDate.toISOString();
    } catch (e) {
        return null;
    }
};

watch(() => props.event, (newEvent) => {
    if (newEvent) {
        const currentTimezone = form.value.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
        
        // Map existing participants to ParticipantItem format
        const existingParticipants: ParticipantItem[] = (newEvent.participants_details || []).map(p => ({
            type: 'user' as const,
            id: String(p.id),
            name: p.name,
            avatar: p.avatar_url,
        }));
        
        form.value = {
            title: newEvent.title,
            description: newEvent.description || '',
            start_time: newEvent.start ? toFormFormat(newEvent.start, currentTimezone) : toFormFormat(newEvent.start_time, currentTimezone),
            end_time: newEvent.end ? toFormFormat(newEvent.end, currentTimezone) : (newEvent.end_time ? toFormFormat(newEvent.end_time, currentTimezone) : ''),
            is_all_day: newEvent.is_all_day || newEvent.allDay || false,
            color: newEvent.color || newEvent.backgroundColor || '#8B5CF6',
            location: newEvent.location || newEvent.extendedProps?.location || '',
            reminder_minutes_before: newEvent.extendedProps?.reminder_minutes_before || null,
            participants: existingParticipants,
            timezone: currentTimezone,
        };
        sendInvite.value = false;
    } else {
        // Reset defaults
        const now = new Date();
        now.setMinutes(0, 0, 0);
        now.setHours(now.getHours() + 1);
        
        const startStr = format(now, "yyyy-MM-dd'T'HH:mm");
        
        now.setHours(now.getHours() + 1);
        const endStr = format(now, "yyyy-MM-dd'T'HH:mm");

        form.value = {
            title: '',
            description: '',
            start_time: startStr,
            end_time: endStr,
            is_all_day: false,
            color: '#8B5CF6',
            location: '',
            reminder_minutes_before: 15,
            participants: [],
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        };
    }
}, { immediate: true });

// Listen for Timezone Changes -> Update Time Display to keep Absolute Moment
watch(() => form.value.timezone, (newZone, oldZone) => {
    if (!oldZone || !newZone || newZone === oldZone) return;
    
    // We assume form.start_time was in 'oldZone'. 
    // We want to convert it to 'newZone' representation of the same absolute time.
    if (form.value.start_time) {
        const utc = fromZonedTime(form.value.start_time, oldZone);
        const newZoned = toZonedTime(utc, newZone);
        form.value.start_time = format(newZoned, "yyyy-MM-dd'T'HH:mm");
    }
    
    if (form.value.end_time) {
         const utc = fromZonedTime(form.value.end_time, oldZone);
        const newZoned = toZonedTime(utc, newZone);
        form.value.end_time = format(newZoned, "yyyy-MM-dd'T'HH:mm");
    }
});

watch(() => form.value.is_all_day, (isAllDay) => {
    if (isAllDay) {
        if (form.value.start_time) form.value.start_time = form.value.start_time.split('T')[0];
        if (form.value.end_time) form.value.end_time = form.value.end_time.split('T')[0];
    } else {
         const now = new Date();
        const currentParams = {
            hour: now.getHours(),
            minute: 0
        };
        const timeStr = `${String(currentParams.hour).padStart(2, '0')}:00`;
        const timeStrEnd = `${String(currentParams.hour + 1).padStart(2, '0')}:00`;

        if (form.value.start_time && !form.value.start_time.includes('T')) {
            form.value.start_time = `${form.value.start_time}T${timeStr}`;
        }
        if (form.value.end_time && !form.value.end_time.includes('T')) {
             form.value.end_time = `${form.value.end_time}T${timeStrEnd}`;
        }
    }
});

// Auto-adjust end_time (Local Comparison)
watch(() => form.value.start_time, (newStart) => {
    if (!newStart || !form.value.end_time) return;
    if (newStart > form.value.end_time) {
        if (form.value.is_all_day) {
            form.value.end_time = newStart;
        } else {
            // Logic is a bit complex with timezone strings but manageable
            // If we assume same timezone, basic arithmetic holds for "next hour" visually
             try {
                // Parse as local wall time for the zone
                const startDate = new Date(newStart); 
                startDate.setHours(startDate.getHours() + 1);
                form.value.end_time = format(startDate, "yyyy-MM-dd'T'HH:mm");
            } catch (e) {
                form.value.end_time = newStart; 
            }
        }
    }
});

const isValid = computed(() => {
    return form.value.title && form.value.start_time;
});

const handleSubmit = () => {
    if (!isValid.value) return;
    
    // Convert to UTC before sending
    // For All-Day: Send as is (YYYY-MM-DD)? Back end might expect that.
    // For DateTime: Send UTC ISO
    
    let submitStart = form.value.start_time;
    let submitEnd = form.value.end_time;
    
    if (!form.value.is_all_day) {
        submitStart = toISO(form.value.start_time, form.value.timezone) || '';
        submitEnd = toISO(form.value.end_time, form.value.timezone) || '';
    }

    // Extract user IDs and external emails
    const participantIds = form.value.participants
        .filter(p => p.type === 'user')
        .map(p => p.id);
    const externalEmails = form.value.participants
        .filter(p => p.type === 'email')
        .map(p => p.email);

    emit('save', { 
        ...form.value,
        start_time: submitStart,
        end_time: submitEnd,
        participants: participantIds,
        external_emails: externalEmails,
        send_invite: sendInvite.value,
        id: props.event?.id
    });
};

const handleDelete = () => {
    if (confirm('Are you sure you want to delete this event?')) {
        emit('delete', props.event?.id);
    }
};

// Invite and Download ICS functionality
const { toast } = useToast();
const inviteLoading = ref(false);

const handleSendInvite = async () => {
    if (!props.teamId || !props.event?.id) return;
    
    inviteLoading.value = true;
    try {
        await api.post(`/api/teams/${props.teamId}/events/${props.event.id}/invite`);
        toast.success('Invites sent to all participants');
    } catch (error: any) {
        toast.error(error.response?.data?.message || 'Failed to send invites');
    } finally {
        inviteLoading.value = false;
    }
};

const handleDownloadIcs = () => {
    if (!props.teamId || !props.event?.id) return;
    
    // Open download URL in new tab/trigger download
    const url = `/api/teams/${props.teamId}/events/${props.event.id}/ics`;
    window.open(url, '_blank');
};
</script>

<template>
    <Modal :open="open" @update:open="$emit('update:open', $event)" :title="event ? 'Edit Event' : 'New Event'">
        <div class="space-y-4">
            <!-- Title -->
            <div class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-secondary)]">Event Title</label>
                <Input v-model="form.title" placeholder="Team meeting, Review, etc." required />
            </div>

            <!-- All Day -->
             <div class="flex items-center justify-between">
                <label class="text-sm font-medium text-[var(--text-secondary)]">All Day Event</label>
                <Switch 
                    :modelValue="form.is_all_day" 
                    @update:modelValue="form.is_all_day = $event" 
                    size="sm"
                />
            </div>

            <!-- Timezone -->
             <div class="space-y-2" v-if="!form.is_all_day">
                <label class="text-sm font-medium text-[var(--text-secondary)]">Timezone</label>
                <TimezoneSelect v-model="form.timezone" />
            </div>

            <!-- Date/Time -->
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-sm font-medium text-[var(--text-secondary)]">Start</label>
                    <Input 
                        v-model="form.start_time" 
                        :type="form.is_all_day ? 'date' : 'datetime-local'" 
                        required 
                    />
                </div>
                <div class="space-y-2">
                    <label class="text-sm font-medium text-[var(--text-secondary)]">End</label>
                     <Input 
                        v-model="form.end_time" 
                        :type="form.is_all_day ? 'date' : 'datetime-local'" 
                    />
                </div>
            </div>

            <!-- Description -->
             <div class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-secondary)]">Description</label>
                <textarea 
                    v-model="form.description" 
                    rows="3"
                    class="w-full rounded-md border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]"
                ></textarea>
            </div>

             <!-- Location -->
            <div class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-secondary)]">Location</label>
                <Input v-model="form.location" placeholder="Conference Room A, Zoom, etc." />
            </div>

            <!-- Color -->
             <div class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-secondary)]">Color</label>
                <div class="flex gap-2">
                    <button 
                        v-for="c in colors" 
                        :key="c"
                        type="button"
                        class="w-8 h-8 rounded-full border-2 transition-all p-0"
                        :style="{ backgroundColor: c, borderColor: form.color === c ? 'var(--text-primary)' : 'transparent' }"
                        @click="form.color = c"
                    ></button>
                </div>
            </div>

             <!-- Participants -->
            <div class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-secondary)]">Participants</label>
                <ParticipantSelector
                    v-model="form.participants"
                    :fetch-url="participantsFetchUrl"
                    placeholder="Search team members, clients, or enter email..."
                />
            </div>

            <!-- Send Invite Toggle -->
            <label v-if="form.participants.length > 0" class="flex items-center gap-2 cursor-pointer group">
                <input 
                    type="checkbox" 
                    v-model="sendInvite"
                    class="w-4 h-4 rounded border-[var(--border-default)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)]/30"
                />
                <Send class="w-4 h-4 text-[var(--text-tertiary)]" />
                <span class="text-sm text-[var(--text-secondary)] group-hover:text-[var(--text-primary)] transition-colors">
                    Send email invitations on save
                </span>
            </label>

             <!-- Reminder -->
            <div class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-secondary)]">Reminder</label>
                <div class="flex items-center gap-2">
                    <Input 
                        v-model="form.reminder_minutes_before" 
                        type="number" 
                        min="0"
                        placeholder="15"
                    />
                    <span class="text-sm text-[var(--text-muted)]">minutes before</span>
                </div>
            </div>

            <!-- Participant Status (Read Only) -->
            <div v-if="event && event.participants_details && event.participants_details.length > 0" class="space-y-2 pt-2 border-t border-[var(--border-default)]">
                 <label class="text-sm font-medium text-[var(--text-secondary)]">Participation Status</label>
                 <div class="space-y-2 max-h-40 overflow-y-auto">
                    <div v-for="p in event.participants_details" :key="p.id" class="flex items-center justify-between text-sm">
                        <div class="flex items-center gap-2">
                            <Avatar :src="p.avatar_url" :name="p.name" size="sm" />
                            <span>{{ p.name }}</span>
                        </div>
                        <div>
                             <StatusBadge :status="p.status === 'accepted' ? 'approved' : (p.status === 'rejected' ? 'rejected' : 'pending')">
                                {{ p.status.charAt(0).toUpperCase() + p.status.slice(1) }}
                             </StatusBadge>
                        </div>
                    </div>
                 </div>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-between w-full">
                <div class="flex items-center gap-2">
                    <Button v-if="event" variant="ghost" class="text-red-500 hover:text-red-600 hover:bg-red-50" @click="handleDelete">Delete</Button>
                    
                    <!-- Invite & Download buttons for existing events -->
                    <template v-if="event && teamId">
                        <Button 
                            variant="outline" 
                            size="sm" 
                            @click="handleSendInvite" 
                            :loading="inviteLoading"
                            :disabled="!form.participants || form.participants.length === 0"
                            title="Send invites to all participants"
                        >
                            <Mail class="h-4 w-4 mr-1" />
                            Send Invite
                        </Button>
                        <Button 
                            variant="outline" 
                            size="sm" 
                            @click="handleDownloadIcs"
                            title="Download ICS file for your calendar"
                        >
                            <Download class="h-4 w-4 mr-1" />
                            ICS
                        </Button>
                    </template>
                </div>
                
                <div class="flex gap-2">
                    <Button variant="ghost" @click="$emit('update:open', false)">Cancel</Button>
                    <Button :loading="loading" :disabled="!isValid" @click="handleSubmit">
                        {{ event ? 'Update Event' : 'Create Event' }}
                    </Button>
                </div>
            </div>
        </template>
    </Modal>
</template>
