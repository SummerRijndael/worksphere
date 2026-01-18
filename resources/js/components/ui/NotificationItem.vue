<script setup>
import { computed } from 'vue';
import { formatDistanceToNow } from 'date-fns';
import { 
    Info, 
    CheckCircle2, 
    AlertTriangle, 
    AlertCircle, 
    Download, 
    ExternalLink, 
    Users, 
    Folder,
    FileText,
    Bell,
    Check,
    X,
    Calendar
} from 'lucide-vue-next';
import { Button } from '@/components/ui';
import { useNotificationsStore } from '@/stores/notifications';
import { ref } from 'vue';
import axios from 'axios';
import { useToast } from '@/composables/useToast.ts';

const { toast } = useToast();
const notificationsStore = useNotificationsStore();


const props = defineProps({
    notification: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['read', 'remove']);

const data = computed(() => props.notification.data || {});
const type = computed(() => data.value.type || 'system');
const isRead = computed(() => !!props.notification.read_at);

// Icon mapping based on type or metadata
const icon = computed(() => {
    switch (type.value) {
        case 'team': return Users;
        case 'project': return Folder;
        case 'download': return Download;
        case 'error': return AlertCircle;
        case 'warning': return AlertTriangle;
        case 'success': return CheckCircle2;
        default: return Bell;
    }
});

const iconColor = computed(() => {
    switch (type.value) {
        case 'error': return 'text-red-500 bg-red-500/10';
        case 'warning': return 'text-yellow-500 bg-yellow-500/10';
        case 'success': return 'text-green-500 bg-green-500/10';
        case 'download': return 'text-blue-500 bg-blue-500/10';
        default: return 'text-[var(--interactive-primary)] bg-[var(--interactive-primary)]/10';
    }
});

const timeAgo = computed(() => {
    try {
        return formatDistanceToNow(new Date(props.notification.created_at), { addSuffix: true });
    } catch (e) {
        return '';
    }
});

const handleAction = () => {
    if (data.value.action_url) {
        window.location.href = data.value.action_url;
    }
};

const acceptInvitation = async (notificationId) => {
    await notificationsStore.acceptInvitation(notificationId);
};

const declineInvitation = async (notificationId) => {
    await notificationsStore.declineInvitation(notificationId);
};
</script>

<template>
    <div 
        class="flex gap-3 p-4 border-b border-[var(--border-muted)] last:border-0 hover:bg-[var(--surface-secondary)] transition-colors group relative"
        :class="{ 'bg-[var(--color-primary-50)] dark:bg-[var(--color-primary-900)]/20': !isRead }"
        @click="$emit('read', notification.id)"
    >
        <!-- Icon -->
        <div 
            class="h-9 w-9 rounded-full flex items-center justify-center shrink-0"
            :class="iconColor"
        >
            <component :is="icon" class="h-4 w-4" />
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
            <div class="flex justify-between items-start gap-2">
                <p class="text-sm font-medium text-[var(--text-primary)] leading-tight">
                    {{ data.title || 'Notification' }}
                </p>
                <span class="text-[10px] text-[var(--text-muted)] whitespace-nowrap shrink-0">
                    {{ timeAgo }}
                </span>
            </div>
            
            <p class="text-sm text-[var(--text-secondary)] mt-1 break-words leading-snug">
                {{ data.message }}
            </p>

            <!-- Action Button -->
            <div v-if="data.action_url" class="mt-2.5">
                <Button 
                    v-if="type === 'download'"
                    variant="outline" 
                    size="xs" 
                    class="gap-1.5 h-7"
                    @click.stop="handleAction"
                >
                    <Download class="h-3.5 w-3.5" />
                    {{ data.action_label || 'Download File' }}
                </Button>
                <Button 
                    v-else
                    variant="outline" 
                    size="xs" 
                    class="gap-1.5 h-7"
                    @click.stop="handleAction"
                >
                    {{ data.action_label || 'View Details' }}
                    <ExternalLink class="h-3 w-3" />
                </Button>
            </div>

            <!-- Event Reminder Action -->
            <div v-if="type === 'event_reminder' || notification.type === 'App\\Notifications\\EventReminder'" class="mt-2.5">
                <Button 
                    size="xs" 
                    variant="outline"
                    class="h-7 px-3 gap-1.5"
                    @click.stop="$router.push('/calendar')"
                >
                    <Calendar class="h-3.5 w-3.5" />
                    View in Calendar
                </Button>
            </div>

            <!-- Team Invitation Actions -->
            <div v-if="(data.type === 'team_invitation' || notification.type === 'App\\Notifications\\TeamInvitationNotification') && !isRead" class="mt-2.5 flex items-center gap-2">
                <Button
                    size="xs"
                    variant="default"
                    class="h-7 px-3 gap-1.5 bg-green-600 hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-800 text-white border-transparent"
                    @click.stop="acceptInvitation(notification.id)"
                >
                    <Check class="h-3.5 w-3.5" />
                    Accept
                </Button>
                <Button 
                    size="xs" 
                    variant="outline"
                    class="h-7 px-3 gap-1.5 text-red-600 border-red-200 hover:bg-red-50 hover:text-red-700 dark:border-red-900/30 dark:hover:bg-red-900/20"
                    @click.stop="declineInvitation(notification.id)"
                >
                    <X class="h-3.5 w-3.5" />
                    Decline
                </Button>
            </div>
        </div>


        <!-- Unread Dot -->
        <div 
            v-if="!isRead"
            class="absolute top-4 right-4 h-2 w-2 rounded-full bg-[var(--interactive-primary)]"
        />
    </div>
</template>
