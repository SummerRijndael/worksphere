<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Card, Button, Badge, Avatar } from '@/components/ui';
import MiniCalendar from '@/components/ui/MiniCalendar.vue';
import { 
    Mail, MapPin, Calendar, Link as LinkIcon, 
    Briefcase, Sparkles, Folder, CheckCircle, Clock,
    Users, FileText, Globe
} from 'lucide-vue-next';
import api from '@/lib/api';

const route = useRoute();
const isLoading = ref(true);
const error = ref(null);
const user = ref(null);

const stats = ref([
    { label: 'Projects', value: '24', icon: Folder, color: 'text-blue-500', bg: 'bg-blue-500/10' },
    { label: 'Tasks Completed', value: '142', icon: CheckCircle, color: 'text-green-500', bg: 'bg-green-500/10' },
    { label: 'Hours Logged', value: '1.2K', icon: Clock, color: 'text-orange-500', bg: 'bg-orange-500/10' },
]);

// Mock teams/files if not returned by API (since public API might filter them)
// Ideally, the API controls what is returned.
const teams = computed(() => user.value?.teams || []);
// Files are likely hidden for public, but we handle if they exist
const files = computed(() => user.value?.files || []);

const fetchPublicProfile = async () => {
    isLoading.value = true;
    error.value = null;
    try {
        const slug = route.params.slug;
        const response = await api.get(`/api/public/profile/${slug}`);
        user.value = response.data;
    } catch (err) {
        console.error('Failed to fetch public profile:', err);
        error.value = err.response?.status === 404 
            ? 'Profile not found or is private.' 
            : 'Failed to load profile.';
    } finally {
        isLoading.value = false;
    }
};

onMounted(() => {
    fetchPublicProfile();
});

watch(() => route.params.slug, () => {
    fetchPublicProfile();
});
</script>

<template>
    <div class="min-h-screen bg-[var(--surface-primary)]">
        <!-- Error State -->
        <div v-if="error" class="flex flex-col items-center justify-center min-h-[60vh] space-y-4">
            <div class="p-4 rounded-full bg-red-100 dark:bg-red-900/20 text-red-600">
                <Globe class="h-8 w-8" />
            </div>
            <h2 class="text-2xl font-bold text-[var(--text-primary)]">Profile Unavailable</h2>
            <p class="text-[var(--text-secondary)]">{{ error }}</p>
            <router-link to="/">
                <Button variant="outline">Go Home</Button>
            </router-link>
        </div>

        <!-- Loading State -->
        <div v-else-if="isLoading" class="flex items-center justify-center min-h-[60vh]">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--interactive-primary)]"></div>
        </div>

        <!-- content -->
        <div v-else class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
            
            <!-- Profile Header -->
            <Card padding="none" class="overflow-visible">
                <!-- Cover Image -->
                <div class="h-50 bg-[var(--surface-secondary)] rounded-t-xl relative overflow-hidden">
                        <img 
                        v-if="user?.cover_photo_url" 
                        :src="user.cover_photo_url" 
                        class="w-full h-full object-cover" 
                        alt="Cover Photo"
                    />
                    <div v-else class="w-full h-full bg-gradient-to-r from-[var(--color-primary-500)] to-[var(--color-primary-700)]"></div>
                </div>

                <!-- Profile Info -->
                <div class="px-8 pb-8">
                    <div class="flex flex-col md:flex-row md:items-end gap-6 relative">
                        <!-- Avatar -->
                        <div class="relative shrink-0 -mt-20 md:-mt-24 mb-4 md:mb-0">
                            <Avatar
                                :fallback="user?.initials"
                                :src="user?.avatar_url"
                                size="4xl"
                                class="border-4 border-[var(--surface-primary)] shadow-xl relative z-10 bg-[var(--surface-primary)]"
                                :status="user?.presence"
                            />
                        </div>

                        <div class="flex-1 min-w-0 md:pb-4">
                            <h1 class="text-3xl font-bold text-[var(--text-primary)]">
                                {{ user?.display_name }}
                            </h1>
                            <p class="text-[var(--text-secondary)] text-lg">{{ user?.title || 'Team Member' }}</p>
                        </div>
                    </div>

                    <!-- Meta Info -->
                    <div class="flex flex-wrap gap-6 mt-8 p-4 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)]">
                        <!-- Email hidden for public usually, but if API returns it, we show it -->
                        <div class="flex items-center gap-2 text-sm text-[var(--text-secondary)]" v-if="user?.email">
                            <Mail class="h-4 w-4" />
                            {{ user.email }}
                        </div>
                        <div class="flex items-center gap-2 text-sm text-[var(--text-secondary)]" v-if="user?.location">
                            <MapPin class="h-4 w-4" />
                            {{ user.location }}
                        </div>
                        <div class="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                            <Calendar class="h-4 w-4" />
                            Joined {{ user?.created_at ? new Date(user.created_at).toLocaleDateString() : '' }}
                        </div>
                        <div class="flex items-center gap-2 text-sm text-[var(--text-secondary)]" v-if="user?.website">
                            <LinkIcon class="h-4 w-4" />
                            <a :href="user.website" target="_blank" rel="noopener noreferrer" class="text-[var(--interactive-primary)] hover:underline">{{ user.website.replace(/^https?:\/\//, '') }}</a>
                        </div>
                    </div>
                </div>
            </Card>

            <!-- Main Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column: About -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- About -->
                    <Card padding="lg">
                        <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                            <Briefcase class="h-5 w-5 text-[var(--text-secondary)]" />
                            About
                        </h2>
                        <p class="text-[var(--text-secondary)] leading-relaxed whitespace-pre-line break-words" v-if="user?.bio">
                            {{ user.bio }}
                        </p>
                        <p class="text-[var(--text-muted)] italic" v-else>
                            No bio added yet.
                        </p>
                        
                        <div class="mt-8" v-if="user?.skills && user.skills.length">
                            <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                                    <Sparkles class="h-4 w-4 text-amber-500" />
                                Skills & Expertise
                            </h3>
                            <div class="flex flex-wrap gap-2.5">
                                <div 
                                    v-for="skill in user.skills" 
                                    :key="skill" 
                                    class="group relative px-4 py-1.5 rounded-full text-sm font-medium transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg cursor-default overflow-hidden bg-[var(--surface-primary)]"
                                >
                                    <!-- Gradient Background -->
                                    <div class="absolute inset-0 bg-gradient-to-r from-blue-500/10 via-indigo-500/10 to-purple-500/10 opacity-100 group-hover:opacity-80 transition-opacity"></div>
                                    
                                    <!-- Border -->
                                    <div class="absolute inset-0 rounded-full border border-blue-200/50 dark:border-blue-700/30 group-hover:border-indigo-400/50 transition-colors"></div>
                                    
                                    <!-- Content -->
                                    <div class="relative flex items-center gap-1.5">
                                        <span class="text-blue-400 group-hover:text-indigo-500 transition-colors">#</span>
                                        <span class="text-[var(--text-secondary)] group-hover:text-[var(--text-primary)] transition-colors">
                                            {{ skill }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>

                     <!-- My Teams (Only if visible) -->
                    <Card padding="lg" v-if="teams.length > 0">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-xl font-semibold text-[var(--text-primary)] flex items-center gap-2">
                                <Users class="h-5 w-5 text-[var(--text-secondary)]" />
                                Teams
                            </h2>
                        </div>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div v-for="team in teams" :key="team.id" class="flex items-center gap-4 p-4 rounded-xl border border-[var(--border-default)] transition-all cursor-default bg-[var(--surface-secondary)]">
                                <Avatar :fallback="team.initials || 'T'" :src="team.avatar" size="md" class="shrink-0" />
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-[var(--text-primary)] truncate">{{ team.name }}</p>
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-xs text-[var(--text-muted)] px-1.5 py-0.5 rounded bg-[var(--surface-elevated)]">
                                            {{ team.members_count || 1 }} members
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Right Column: Stats, Calendar -->
                <div class="space-y-6">
                     <!-- Stats (Optional - currently using mock data or if API provides) -->
                    <Card padding="lg">
                        <h2 class="text-sm font-semibold text-[var(--text-muted)] uppercase tracking-wider mb-4">Overview</h2>
                        <div class="grid grid-cols-3 gap-4">
                            <div v-for="stat in stats" :key="stat.label" class="flex flex-col items-center justify-center p-4 rounded-xl bg-[var(--surface-secondary)] border border-[var(--border-default)]">
                                <div class="p-2 rounded-full mb-3" :class="stat.bg">
                                    <component :is="stat.icon" class="h-5 w-5" :class="stat.color" />
                                </div>
                                <p class="text-2xl font-bold text-[var(--text-primary)]">{{ stat.value }}</p>
                                <p class="text-xs text-[var(--text-muted)] mt-1 truncate font-medium">{{ stat.label }}</p>
                            </div>
                        </div>
                    </Card>

                    <!-- Mini Calendar (ReadOnly) -->
                    <Card padding="lg">
                        <MiniCalendar :show-holidays="true" country-code="US" :clickable="false" />
                    </Card>
                </div>
            </div>
        </div>
    </div>
</template>
