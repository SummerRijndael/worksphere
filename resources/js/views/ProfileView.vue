<script setup>
import { ref, onMounted, computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { Card, Button, Badge, Avatar, Input } from '@/components/ui';
import MiniCalendar from '@/components/ui/MiniCalendar.vue';
import { 
    Mail, MapPin, Calendar, Link as LinkIcon, Edit, Camera, 
    Users, FileText, CheckSquare, Plus, Trash2, Download,
    Briefcase, Shield, Share, Folder, CheckCircle, Clock, Sparkles,
    Globe, Copy, ExternalLink
} from 'lucide-vue-next';
import { Switch } from '@/components/ui';
import { toast } from 'vue-sonner';
import api from '@/lib/api';

const authStore = useAuthStore();
const isLoading = ref(true);
const userDetails = ref(null);

// Mock Data for Calendar
const currentMonth = ref(new Date());
const weekDays = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

// To-Do List State
const newTodo = ref('');
const todos = ref([
    { id: 1, text: 'Review quarter reports', completed: false },
    { id: 2, text: 'Update user documentation', completed: true },
    { id: 3, text: 'Team sync meeting', completed: false },
]);

const stats = ref([
    { label: 'Projects', value: '24', icon: Folder, color: 'text-blue-500', bg: 'bg-blue-500/10' },
    { label: 'Tasks Completed', value: '142', icon: CheckCircle, color: 'text-green-500', bg: 'bg-green-500/10' },
    { label: 'Hours Logged', value: '1.2K', icon: Clock, color: 'text-orange-500', bg: 'bg-orange-500/10' },
]);

// Fetch full user details
const fetchUserDetails = async () => {
    isLoading.value = true;
    try {
        const response = await api.get('/api/user/details');
        userDetails.value = response.data;
        isPublic.value = !!response.data.is_public;
    } catch (error) {
        console.error('Failed to fetch user details:', error);
    } finally {
        isLoading.value = false;
    }
};

const addTodo = () => {
    if (!newTodo.value.trim()) return;
    todos.value.unshift({
        id: Date.now(),
        text: newTodo.value,
        completed: false
    });
    newTodo.value = '';
    newTodo.value = '';
};

const isPublic = ref(false);
const isUpdatingVisibility = ref(false);

const publicProfileUrl = computed(() => {
    if (!userDetails.value?.username) return '';
    return `${window.location.origin}/p/${userDetails.value.username}`;
});

const toggleVisibility = async (newValue) => {
    isUpdatingVisibility.value = true;
    try {
        // Optimistic update
        const previousValue = isPublic.value;
        isPublic.value = newValue;

        const response = await api.put('/api/user/profile/visibility', {
            is_public: newValue
        });
        isPublic.value = response.data.is_public;
        
        // Update local user details
        if (userDetails.value) {
            userDetails.value.is_public = response.data.is_public;
        }
    } catch (error) {
        console.error('Failed to update visibility:', error);
        // Revert on error
        isPublic.value = !newValue; 
    } finally {
        isUpdatingVisibility.value = false;
    }
};

const copyPublicUrl = () => {
    if (!publicProfileUrl.value) return;
    navigator.clipboard.writeText(publicProfileUrl.value);
    toast.success('Link copied to clipboard');
};

const toggleTodo = (id) => {
    const todo = todos.value.find(t => t.id === id);
    if (todo) todo.completed = !todo.completed;
};

const removeTodo = (id) => {
    todos.value = todos.value.filter(t => t.id !== id);
};

const calendarDays = computed(() => {
    const year = currentMonth.value.getFullYear();
    const month = currentMonth.value.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    
    const days = [];
    
    // Padding for start of month
    for (let i = 0; i < firstDay.getDay(); i++) {
        days.push({ day: '', isCurrentMonth: false });
    }
    
    // Days of month
    const today = new Date();
    for (let i = 1; i <= lastDay.getDate(); i++) {
        const isToday = today.getDate() === i && 
                        today.getMonth() === month && 
                        today.getFullYear() === year;
        days.push({ 
            day: i, 
            isCurrentMonth: true,
            isToday 
        });
    }
    
    return days;
});

const files = computed(() => userDetails.value?.files || []);
const teams = computed(() => userDetails.value?.teams || []);
const skills = ref(['Vue.js', 'TypeScript', 'Node.js', 'Tailwind CSS', 'PostgreSQL', 'Docker']);

onMounted(() => {
    fetchUserDetails();
});
</script>

<template>
    <div class="w-full space-y-6">
        <!-- Profile Header -->
        <Card padding="none" class="overflow-visible">
            <!-- Cover Image -->
            <div class="h-50 bg-[var(--surface-secondary)] rounded-t-xl relative overflow-hidden group">
                 <img 
                    v-if="authStore.user?.cover_photo_url" 
                    :src="authStore.user.cover_photo_url" 
                    class="w-full h-full object-cover" 
                    alt="Cover Photo"
                />
                <div v-else class="w-full h-full bg-gradient-to-r from-[var(--color-primary-500)] to-[var(--color-primary-700)]"></div>
                
                <router-link to="/settings?tab=profile">
                    <Button variant="ghost" size="sm" class="absolute top-4 right-4 bg-black/20 text-white hover:bg-black/30 backdrop-blur-sm opacity-0 group-hover:opacity-100 transition-opacity">
                        <Camera class="h-4 w-4 mr-2" />
                        Change cover
                    </Button>
                </router-link>
            </div>

            <!-- Profile Info -->
            <div class="px-8 pb-8">
                <div class="flex flex-col md:flex-row md:items-end gap-6 relative">
                    <!-- Avatar wrapper with negative margin to overlapping cover -->
                    <div class="relative shrink-0 -mt-20 md:-mt-24 mb-4 md:mb-0">
                        <Avatar
                            :fallback="authStore.initials"
                            :src="authStore.user?.avatar_url"
                            size="4xl"
                            class="border-4 border-[var(--surface-primary)] shadow-xl relative z-10 bg-[var(--surface-primary)]"
                            :status="authStore.user?.presence"
                        />
                         <router-link to="/settings?tab=profile">
                            <button class="absolute bottom-1 right-1 flex h-8 w-8 items-center justify-center rounded-full bg-[var(--surface-elevated)] border border-[var(--border-default)] text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors shadow-sm z-20 cursor-pointer">
                                <Camera class="h-4 w-4" />
                            </button>
                        </router-link>
                    </div>

                    <div class="flex-1 min-w-0 md:pb-4">
                        <h1 class="text-3xl font-bold text-[var(--text-primary)]">
                            {{ authStore.displayName }}
                        </h1>
                        <p class="text-[var(--text-secondary)] text-lg">{{ authStore.user?.title || 'Team Member' }}</p>
                    </div>

                    <div class="flex gap-3 md:pb-4">
                        <Button variant="outline" @click="copyPublicUrl">
                            <Share class="h-4 w-4 mr-2" />
                            Share
                        </Button>
                        <router-link to="/settings?tab=profile">
                            <Button>
                                <Edit class="h-4 w-4 mr-2" />
                                Edit Profile
                            </Button>
                        </router-link>
                    </div>
                </div>

                <!-- Public Profile visibility -->
                <div class="mt-6 p-4 bg-[var(--surface-elevated)] rounded-lg border border-[var(--border-default)] flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <div class="p-2 rounded-full bg-blue-500/10 text-blue-600 dark:text-blue-400">
                            <Globe class="h-5 w-5" />
                        </div>
                        <div>
                            <p class="font-medium text-[var(--text-primary)]">Public Profile</p>
                            <p class="text-xs text-[var(--text-secondary)]">Allow anyone to view your profile via a unique link</p>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-4">
                         <div v-if="isPublic" class="flex items-center gap-2 bg-[var(--surface-primary)] px-3 py-1.5 rounded-md border border-[var(--border-default)] max-w-[200px] sm:max-w-xs">
                            <span class="text-xs text-[var(--text-secondary)] truncate">{{ publicProfileUrl }}</span>
                            <button @click="copyPublicUrl" class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors" title="Copy Link">
                                <Copy class="h-3.5 w-3.5" />
                            </button>
                            <a :href="publicProfileUrl" target="_blank" class="text-[var(--text-muted)] hover:text-[var(--interactive-primary)] transition-colors" title="Open Link">
                                <ExternalLink class="h-3.5 w-3.5" />
                            </a>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium" :class="isPublic ? 'text-[var(--text-primary)]' : 'text-[var(--text-muted)]'">
                                {{ isPublic ? 'On' : 'Off' }}
                            </span>
                            <Switch 
                                :model-value="isPublic" 
                                @update:model-value="toggleVisibility" 
                                :disabled="isUpdatingVisibility"
                            />
                        </div>
                    </div>
                </div>

                <!-- Meta Info -->
                <div class="flex flex-wrap gap-6 mt-8 p-4 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)]">
                    <div class="flex items-center gap-2 text-sm text-[var(--text-secondary)]" v-if="authStore.user?.email">
                        <Mail class="h-4 w-4" />
                        {{ authStore.user.email }}
                    </div>
                    <div class="flex items-center gap-2 text-sm text-[var(--text-secondary)]" v-if="authStore.user?.location">
                        <MapPin class="h-4 w-4" />
                        {{ authStore.user.location }}
                    </div>
                    <div class="flex items-center gap-2 text-sm text-[var(--text-secondary)]">
                        <Calendar class="h-4 w-4" />
                        Joined {{ authStore.user?.created_at ? new Date(authStore.user.created_at).toLocaleDateString() : 'Loading...' }}
                    </div>
                    <div class="flex items-center gap-2 text-sm text-[var(--text-secondary)]" v-if="authStore.user?.website">
                        <LinkIcon class="h-4 w-4" />
                        <a :href="authStore.user.website" target="_blank" rel="noopener noreferrer" class="text-[var(--interactive-primary)] hover:underline">{{ authStore.user.website.replace(/^https?:\/\//, '') }}</a>
                    </div>
                </div>
            </div>
        </Card>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left Column: About & Teams -->
            <div class="space-y-6 lg:col-span-2">
                <!-- About -->
                <Card padding="lg">
                    <h2 class="text-xl font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                        <Briefcase class="h-5 w-5 text-[var(--text-secondary)]" />
                        About
                    </h2>
                    <p class="text-[var(--text-secondary)] leading-relaxed whitespace-pre-line break-words" v-if="authStore.user?.bio">
                        {{ authStore.user.bio }}
                    </p>
                    <p class="text-[var(--text-muted)] italic" v-else>
                        No bio added yet.
                    </p>
                    
                    <div class="mt-8" v-if="authStore.user?.skills && authStore.user.skills.length">
                        <h3 class="text-sm font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2">
                             <Sparkles class="h-4 w-4 text-amber-500" />
                            Skills & Expertise
                        </h3>
                        <div class="flex flex-wrap gap-2.5">
                            <div 
                                v-for="skill in authStore.user.skills" 
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

                <!-- Teams -->
                <Card padding="lg">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-semibold text-[var(--text-primary)] flex items-center gap-2">
                            <Users class="h-5 w-5 text-[var(--text-secondary)]" />
                            Teams
                        </h2>
                        <Button variant="ghost" size="sm">View All</Button>
                    </div>
                    
                    <div v-if="teams.length === 0" class="text-center py-8 text-[var(--text-muted)] bg-[var(--surface-secondary)] rounded-lg border border-dashed border-[var(--border-default)]">
                        <Users class="h-8 w-8 mx-auto mb-2 opacity-50" />
                        <p>No teams joined yet</p>
                    </div>

                    <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div v-for="team in teams" :key="team.id" class="flex items-center gap-4 p-4 rounded-xl border border-[var(--border-default)] hover:border-[var(--interactive-primary)] hover:bg-[var(--surface-secondary)] transition-all cursor-pointer group">
                            <Avatar :fallback="team.initials || 'T'" :src="team.avatar" size="md" class="shrink-0" />
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-[var(--text-primary)] truncate group-hover:text-[var(--interactive-primary)]">{{ team.name }}</p>
                                <div class="flex items-center gap-2 mt-1">
                                    <Badge variant="outline" size="xs" class="capitalize">
                                        {{ team.pivot?.role || 'Member' }}
                                    </Badge>
                                    <span class="text-xs text-[var(--text-muted)] px-1.5 py-0.5 rounded bg-[var(--surface-elevated)]">
                                        {{ team.members_count || 1 }} members
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Uploaded Files -->
                <Card padding="lg">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-semibold text-[var(--text-primary)] flex items-center gap-2">
                            <FileText class="h-5 w-5 text-[var(--text-secondary)]" />
                            Recent Files
                        </h2>
                        <router-link to="/settings?tab=profile">
                            <Button variant="outline" size="sm">
                                <Plus class="h-4 w-4 mr-2" />
                                Upload
                            </Button>
                        </router-link>
                    </div>

                    <div v-if="files.length === 0" class="text-center py-12 text-[var(--text-muted)] bg-[var(--surface-secondary)] rounded-lg border border-dashed border-[var(--border-default)]">
                        <FileText class="h-10 w-10 mx-auto mb-3 opacity-50" />
                        <p class="font-medium">No files uploaded</p>
                        <p class="text-sm">Upload documents to share with your team</p>
                    </div>

                    <div v-else class="space-y-3">
                        <div v-for="file in files" :key="file.id" class="flex items-center justify-between p-3 rounded-lg border border-[var(--border-default)] hover:bg-[var(--surface-secondary)] transition-colors group">
                            <div class="flex items-center gap-3 overflow-hidden">
                                <div class="h-10 w-10 rounded-lg bg-[var(--surface-elevated)] flex items-center justify-center shrink-0 border border-[var(--border-default)]">
                                    <FileText class="h-5 w-5 text-[var(--text-secondary)]" />
                                </div>
                                <div class="min-w-0">
                                    <p class="font-medium text-[var(--text-primary)] truncate">{{ file.name }}</p>
                                    <p class="text-xs text-[var(--text-muted)]">{{ file.created_at }} • {{ (file.size / 1024).toFixed(1) }} KB</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                <a :href="file.download_url" target="_blank" rel="noopener noreferrer">
                                    <Button variant="ghost" size="icon-sm" title="Download">
                                        <Download class="h-4 w-4" />
                                    </Button>
                                </a>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Right Column: Stats, To-Do, Calendar -->
            <div class="space-y-6">
                <!-- Stats -->
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

                <!-- Mini Calendar -->
                <Card padding="lg">
                    <MiniCalendar :show-holidays="true" country-code="US" />
                </Card>

                <!-- To-Do List -->
                <Card padding="lg" class="flex flex-col h-auto max-h-[400px]">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-semibold text-[var(--text-primary)] flex items-center gap-2">
                            <CheckSquare class="h-4 w-4 text-[var(--text-secondary)]" />
                            My Tasks
                        </h2>
                        <Badge variant="neutral">{{ todos.filter(t => !t.completed).length }} pending</Badge>
                    </div>

                    <div class="flex gap-2 mb-4">
                        <Input 
                            v-model="newTodo" 
                            placeholder="Add a new task..." 
                            class="h-9 text-sm flex-1"
                            @keyup.enter="addTodo"
                        />
                        <Button size="icon-sm" @click="addTodo" :disabled="!newTodo.trim()">
                            <Plus class="h-4 w-4" />
                        </Button>
                    </div>

                    <div class="flex-1 overflow-y-auto space-y-2 pr-1 custom-scrollbar">
                        <div 
                            v-for="todo in todos" 
                            :key="todo.id" 
                            class="group flex items-center gap-3 p-2 rounded-md hover:bg-[var(--surface-secondary)] transition-colors cursor-pointer"
                            @click="toggleTodo(todo.id)"
                        >
                            <div 
                                class="h-5 w-5 rounded border flex items-center justify-center transition-colors"
                                :class="todo.completed ? 'bg-green-500 border-green-500 text-white' : 'border-[var(--border-default)] bg-[var(--surface-primary)]'"
                            >
                                <svg v-if="todo.completed" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <span 
                                class="text-sm flex-1 transition-all"
                                :class="todo.completed ? 'text-[var(--text-muted)] line-through' : 'text-[var(--text-primary)]'"
                            >
                                {{ todo.text }}
                            </span>
                            <button 
                                class="opacity-0 group-hover:opacity-100 text-[var(--text-muted)] hover:text-red-500 transition-all p-1"
                                @click.stop="removeTodo(todo.id)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>

                        <div v-if="todos.length === 0" class="text-center py-8 text-[var(--text-muted)]">
                            <p class="text-sm">No tasks yet. Enjoy your day!</p>
                        </div>
                    </div>
                </Card>
            </div>
        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: var(--border-default);
    border-radius: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: var(--text-muted);
}
</style>
