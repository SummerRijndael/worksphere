<script setup>
import { ref, onMounted, computed } from 'vue';
import { Button } from '@/components/ui';
import { Info, AlertTriangle, AlertCircle, CheckCircle, X, ExternalLink } from 'lucide-vue-next';
import axios from 'axios';
import { useAuthStore } from '@/stores/auth';
import { RouterLink } from 'vue-router';

const props = defineProps({
    mode: {
        type: String,
        default: 'user', // 'user' or 'public'
    },
});

const authStore = useAuthStore();
const announcements = ref([]);
const isLoading = ref(true);

const typeConfig = {
    info: {
        bg: 'bg-blue-500/10',
        border: 'border-blue-500/20',
        text: 'text-blue-600 dark:text-blue-400',
        icon: Info,
    },
    warning: {
        bg: 'bg-amber-500/10',
        border: 'border-amber-500/20',
        text: 'text-amber-600 dark:text-amber-400',
        icon: AlertTriangle,
    },
    danger: {
        bg: 'bg-red-500/10',
        border: 'border-red-500/20',
        text: 'text-red-600 dark:text-red-400',
        icon: AlertCircle,
    },
    success: {
        bg: 'bg-green-500/10',
        border: 'border-green-500/20',
        text: 'text-green-600 dark:text-green-400',
        icon: CheckCircle,
    },
};

const fetchAnnouncements = async () => {
    try {
        const endpoint = props.mode === 'public' ? '/api/public/announcements' : '/api/announcements/active';
        const response = await axios.get(endpoint);
        announcements.value = response.data.data;
    } catch (error) {
        console.error('Failed to fetch announcements:', error);
    } finally {
        isLoading.value = false;
    }
};

const dismiss = async (id) => {
    try {
        await axios.post(`/api/announcements/${id}/dismiss`);
        announcements.value = announcements.value.filter(a => a.id !== id);
    } catch (error) {
        console.error('Failed to dismiss announcement:', error);
    }
};

const systemAnnouncements = computed(() => {
    if (props.mode === 'public') return [];
    
    const alerts = [];
    if (authStore.isAuthenticated && authStore.user && !authStore.user.is_password_set) {
         alerts.push({
             id: 'system-password-setup',
             type: 'warning',
             title: 'Password Not Set',
             message: 'You have not set a password for your account. Please set one to ensure you can access your account.',
             action_text: 'Setup Password',
             action_route: { name: 'settings', query: { tab: 'security' } }, // Internal route
             is_dismissable: false
         });
    }
    return alerts;
});

const displayedAnnouncements = computed(() => [
    ...systemAnnouncements.value,
    ...announcements.value
]);

const getConfig = (type) => typeConfig[type] || typeConfig.info;

onMounted(fetchAnnouncements);
</script>

<template>
    <div v-if="displayedAnnouncements.length > 0" class="space-y-2 px-4 py-2">
        <div
            v-for="announcement in displayedAnnouncements"
            :key="announcement.id"
            :class="[
                'flex items-center justify-between gap-4 px-4 py-3 rounded-lg border',
                getConfig(announcement.type).bg,
                getConfig(announcement.type).border,
            ]"
        >
            <div class="flex items-center gap-3 min-w-0">
                <component
                    :is="getConfig(announcement.type).icon"
                    :class="['w-5 h-5 shrink-0', getConfig(announcement.type).text]"
                />
                <div class="min-w-0">
                    <p :class="['text-sm font-medium', getConfig(announcement.type).text]">
                        {{ announcement.title }}
                    </p>
                    <p class="text-sm text-[var(--text-secondary)] truncate">
                        {{ announcement.message }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <!-- External Link -->
                <a
                    v-if="announcement.action_url"
                    :href="announcement.action_url"
                    target="_blank"
                    :class="[
                        'inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md transition-colors',
                        getConfig(announcement.type).bg,
                        getConfig(announcement.type).text,
                        'hover:opacity-80',
                    ]"
                >
                    {{ announcement.action_text || 'Learn More' }}
                    <ExternalLink class="w-3 h-3" />
                </a>

                <!-- Internal Route -->
                <RouterLink
                    v-if="announcement.action_route"
                    :to="announcement.action_route"
                    :class="[
                        'inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-md transition-colors',
                        getConfig(announcement.type).bg,
                        getConfig(announcement.type).text,
                        'hover:opacity-80',
                    ]"
                >
                    {{ announcement.action_text || 'Action' }}
                </RouterLink>

                <button
                    v-if="announcement.is_dismissable"
                    @click="dismiss(announcement.id)"
                    class="p-1 rounded-md hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                    aria-label="Dismiss"
                >
                    <X class="w-4 h-4 text-[var(--text-muted)]" />
                </button>
            </div>
        </div>
    </div>
</template>
