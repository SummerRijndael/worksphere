<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { fromZonedTime, toZonedTime } from 'date-fns-tz';
import { format } from 'date-fns';
import api from '@/lib/api';
import Modal from '@/components/ui/Modal.vue';
import Input from '@/components/ui/Input.vue';
import Button from '@/components/ui/Button.vue';
import ParticipantSelector from '@/components/ui/ParticipantSelector.vue';
import TimezoneSelect from '@/components/ui/TimezoneSelect.vue';
import { toast } from 'vue-sonner';
import { MapPin, Bell, AlignLeft, Calendar, Mail } from 'lucide-vue-next';

const props = defineProps({
    open: Boolean,
    event: Object,
    selectedDate: Date,
});

const emit = defineEmits(['update:open', 'saved', 'deleted']);

const isLoading = ref(false);
const sendInvite = ref(false);

const form = ref({
    title: '',
    description: '',
    start_time: '',
    end_time: '',
    location: '',
    is_all_day: false,
    reminder_minutes_before: 15,
    participants: [], // Array of { type: 'user'|'email', id?, email?, name? }
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
});

// Helper: Convert UTC Date (ISO) -> "YYYY-MM-DDTHH:mm" in Specific Timezone
const toFormFormat = (isoString, timeZone) => {
    if (!isoString) return '';
    try {
        const utcDate = new Date(isoString);
        const zoned = toZonedTime(utcDate, timeZone);
        return format(zoned, "yyyy-MM-dd'T'HH:mm");
    } catch (e) {
        return isoString.slice(0, 16);
    }
};

// Helper: Convert "YYYY-MM-DDTHH:mm" (User Time) -> UTC ISO String
const toISO = (formDateStr, timeZone) => {
    if (!formDateStr) return null;
    try {
        const zonedDate = fromZonedTime(formDateStr, timeZone);
        return zonedDate.toISOString();
    } catch (e) {
        return null;
    }
};



watch(() => props.open, (isOpen) => {
    if (isOpen) {
        const currentTimezone = form.value.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (props.event) {
            // Map existing attendees to participant format
            const userParticipants = (props.event.attendees || props.event.extendedProps?.attendees || []).map(u => ({
                type: 'user',
                id: u.id || u.public_id,
                name: u.name,
                email: u.email,
                avatar: u.avatar_url,
                status: u.status
            }));

            const emailParticipants = (props.event.external_attendees || []).map(email => ({
                type: 'email',
                id: email, // use email as ID for external
                name: email,
                email: email
            }));

            const existingParticipants = [...userParticipants, ...emailParticipants];
            
            form.value = {
                title: props.event.title,
                description: props.event.description || '',
                start_time: format(props.event.start_time, "yyyy-MM-dd'T'HH:mm"),
                end_time: format(props.event.end_time, "yyyy-MM-dd'T'HH:mm"),
                location: props.event.location || '',
                is_all_day: props.event.is_all_day,
                reminder_minutes_before: props.event.extendedProps?.reminder_minutes_before || 15,
                participants: existingParticipants,
                timezone: currentTimezone
            };
            sendInvite.value = false;
        } else {
            // New Event
            const date = props.selectedDate || new Date();
            const start = new Date(date);
            if (props.selectedDate && !props.selectedDate.getHours()) {
                start.setHours(9, 0, 0);
            }
            
            const end = new Date(start);
            end.setHours(end.getHours() + 1);

            form.value = {
                title: '',
                description: '',
                start_time: format(start, "yyyy-MM-dd'T'HH:mm"),
                end_time: format(end, "yyyy-MM-dd'T'HH:mm"),
                location: '',
                is_all_day: false,
                reminder_minutes_before: 15,
                participants: [],
                timezone: currentTimezone
            };
            sendInvite.value = true; // Default to send invites for new events
        }
    }
});

// Timezone Change Logic
watch(() => form.value.timezone, (newZone, oldZone) => {
    if (!oldZone || !newZone || newZone === oldZone) return;
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

async function save() {
    if (!form.value.title.trim()) {
        toast.error('Please enter an event title');
        return;
    }

    isLoading.value = true;
    try {
        // Convert to UTC
        let submitStart = form.value.start_time;
        let submitEnd = form.value.end_time;

        if (!form.value.is_all_day) {
            submitStart = toISO(form.value.start_time, form.value.timezone) || form.value.start_time;
            submitEnd = toISO(form.value.end_time, form.value.timezone) || form.value.end_time;
        } else {
            // strip time for all day? Backend usually takes date or datetime. 
            // If datetime, set to 00:00? formatting 'yyyy-MM-dd' usually enough if casted.
            // But let's send full ISO just in case, or date string.
            // CalendarController expects 'date'.
            // Let's send straight string for now, backend `date` validation handles it.
            submitStart = form.value.start_time.split('T')[0];
            submitEnd = form.value.end_time ? form.value.end_time.split('T')[0] : submitStart;
        }

        // Extract user IDs and external emails from participants
        const attendees = form.value.participants
            .filter(p => p.type === 'user')
            .map(p => p.id);
        const externalEmails = form.value.participants
            .filter(p => p.type === 'email')
            .map(p => p.email);

        const payload = {
            title: form.value.title,
            description: form.value.description,
            start_time: submitStart,
            end_time: submitEnd,
            location: form.value.location,
            is_all_day: form.value.is_all_day,
            reminder_minutes_before: form.value.reminder_minutes_before || null,
            attendees: attendees,
            external_emails: externalEmails,
            send_invite: sendInvite.value,
        };

        if (props.event && props.event.id) {
            await api.put(`/api/calendar/events/${props.event.id}`, payload);
            toast.success('Event updated successfully');
        } else {
            await api.post('/api/calendar/events', payload);
            toast.success('Event created successfully');
        }
        
        emit('update:open', false);
        emit('saved');
    } catch (error) {
        console.error('Failed to save event:', error);
        toast.error(error.response?.data?.message || 'Failed to save event');
    } finally {
        isLoading.value = false;
    }
}

async function deleteEvent() {
    if (!props.event) return;
    if (!confirm('Are you sure you want to delete this event? This action cannot be undone.')) return;
    
    isLoading.value = true;
    try {
        await api.delete(`/api/calendar/events/${props.event.id}`);
        toast.success('Event deleted');
        emit('update:open', false);
        emit('saved');
    } catch (error) {
        console.error('Failed to delete event:', error);
        toast.error('Failed to delete event');
    } finally {
        isLoading.value = false;
    }
}
</script>

<template>
    <Modal
        :open="open"
        :title="event ? 'Edit Event' : 'New Event'"
        size="xl"
        @update:open="$emit('update:open', $event)"
    >
        <div class="space-y-5">
            <!-- Title Field -->
            <Input
                v-model="form.title"
                label="Event Title"
                placeholder="What's the event about?"
            />

            <!-- Timezone -->
             <div class="space-y-2" v-if="!form.is_all_day">
                <label class="text-sm font-medium text-[var(--text-secondary)]">Timezone</label>
                <TimezoneSelect v-model="form.timezone" />
            </div>

            <!-- Date & Time Section -->
            <div class="rounded-xl border border-[var(--border-default)] bg-[var(--surface-secondary)]/50 p-4">
                <div class="flex items-center gap-2 mb-4">
                    <Calendar class="w-4 h-4 text-[var(--text-tertiary)]" />
                    <span class="text-sm font-medium text-[var(--text-secondary)]">Date & Time</span>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <!-- Start Date/Time -->
                     <Input 
                        label="Starts"
                        v-model="form.start_time" 
                        :type="form.is_all_day ? 'date' : 'datetime-local'" 
                        required 
                    />
                    
                    <!-- End Date/Time -->
                     <Input 
                        label="Ends"
                        v-model="form.end_time" 
                        :type="form.is_all_day ? 'date' : 'datetime-local'" 
                    />
                </div>

                <!-- All Day Toggle -->
                <label class="flex items-center gap-2 mt-4 cursor-pointer group">
                    <input 
                        type="checkbox" 
                        v-model="form.is_all_day"
                        class="w-4 h-4 rounded border-[var(--border-default)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)]/30"
                    />
                    <span class="text-sm text-[var(--text-secondary)] group-hover:text-[var(--text-primary)] transition-colors">All-day event</span>
                </label>
            </div>

            <!-- Location -->
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <MapPin class="w-4 h-4 text-[var(--text-tertiary)]" />
                    <label class="text-sm font-medium text-[var(--text-primary)]">Location</label>
                </div>
                <Input v-model="form.location" placeholder="Add a location or meeting link" />
            </div>

             <!-- Participants -->
            <div class="space-y-2">
                <label class="text-sm font-medium text-[var(--text-secondary)]">Invite People</label>
                        <ParticipantSelector
                            v-model="form.participants"
                            :fetch-url="`/api/users`"
                            placeholder="Add people or enter email..."
                            :max="25"
                        />
                        <p class="text-xs text-gray-500 mt-1">
                            Add internal users or external email addresses (Max 25)
                        </p>
            </div>

            <!-- Send Invite Toggle -->
            <label v-if="form.participants.length > 0" class="flex items-center gap-2 cursor-pointer group">
                <input 
                    type="checkbox" 
                    v-model="sendInvite"
                    class="w-4 h-4 rounded border-[var(--border-default)] text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)]/30"
                />
                <Mail class="w-4 h-4 text-[var(--text-tertiary)]" />
                <span class="text-sm text-[var(--text-secondary)] group-hover:text-[var(--text-primary)] transition-colors">
                    Send email invitations
                </span>
            </label>

            <!-- Reminder -->
             <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <Bell class="w-4 h-4 text-[var(--text-tertiary)]" />
                    <label class="text-sm font-medium text-[var(--text-primary)]">Reminder</label>
                </div>
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
            
            <!-- Description -->
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <AlignLeft class="w-4 h-4 text-[var(--text-tertiary)]" />
                    <label class="text-sm font-medium text-[var(--text-primary)]">Description</label>
                </div>
                <textarea
                    v-model="form.description"
                    class="w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] text-[var(--text-primary)] p-3 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/30 focus:border-[var(--interactive-primary)] resize-none transition-all placeholder:text-[var(--text-tertiary)]"
                    rows="3"
                    placeholder="Add notes, agenda, or any additional details..."
                ></textarea>
            </div>
        </div>

        <template #footer>
            <div class="flex items-center w-full gap-3">
                <Button
                    v-if="event"
                    variant="danger"
                    :loading="isLoading"
                    @click="deleteEvent"
                    size="sm"
                >
                    Delete Event
                </Button>
                <div class="flex-1"></div>
                <Button
                    variant="ghost"
                    @click="$emit('update:open', false)"
                    :disabled="isLoading"
                >
                    Cancel
                </Button>
                <Button
                    variant="primary"
                    :loading="isLoading"
                    @click="save"
                >
                    {{ event ? 'Update Event' : 'Create Event' }}
                </Button>
            </div>
        </template>
    </Modal>
</template>

<style scoped>
/* Custom styling for native date/time inputs */
input[type="date"]::-webkit-calendar-picker-indicator,
input[type="time"]::-webkit-calendar-picker-indicator {
    filter: invert(0.5);
    cursor: pointer;
}

input[type="date"]::-webkit-calendar-picker-indicator:hover,
input[type="time"]::-webkit-calendar-picker-indicator:hover {
    filter: invert(0.7);
}
</style>
