<template>
    <div class="w-full min-h-screen bg-[var(--surface-primary)]">
        <!-- Loading State -->
        <div
            v-if="loading"
            class="flex flex-col items-center justify-center p-24 h-[calc(100vh-64px)]"
        >
            <div
                class="animate-spin rounded-full h-12 w-12 border-b-2 border-[var(--interactive-primary)] mb-4"
            ></div>
            <p class="text-[var(--text-secondary)] animate-pulse">
                Loading profile...
            </p>
        </div>

        <!-- Error State -->
        <div
            v-else-if="error"
            class="flex flex-col items-center justify-center p-24 h-[calc(100vh-64px)]"
        >
            <div
                class="bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 p-6 rounded-xl max-w-md text-center border border-red-100 dark:border-red-900/50 shadow-sm"
            >
                <Shield class="w-12 h-12 mx-auto mb-3 opacity-80" />
                <h3 class="text-lg font-bold mb-2">Access Denied</h3>
                <p>{{ error }}</p>
            </div>
        </div>

        <div v-else-if="user" class="space-y-6 pb-12">
            <!-- Hero Section -->
            <div
                class="relative bg-[var(--surface-secondary)] border-b border-[var(--border-default)]"
            >
                <!-- Cover Photo -->
                <div class="h-64 md:h-80 w-full relative overflow-hidden group">
                    <img
                        v-if="user.cover_photo_url"
                        :src="user.cover_photo_url"
                        :alt="user.name + ' Cover'"
                        class="w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-105"
                        :class="{
                            'opacity-0': !isCoverLoaded,
                            'opacity-100': isCoverLoaded,
                        }"
                        :style="{
                            objectPosition: `center ${user.cover_photo_offset ?? 50}%`,
                        }"
                        @load="onCoverLoad"
                    />
                    <div
                        v-else
                        class="w-full h-full bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 dark:from-indigo-900 dark:via-purple-900 dark:to-pink-900 relative overflow-hidden"
                    >
                        <div
                            class="absolute inset-0 bg-repeat opacity-20"
                            style="background-image: url('/patterns/grid.svg')"
                        ></div>
                        <div
                            class="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent"
                        ></div>
                    </div>

                    <!-- Gradient Overlay -->
                    <div
                        class="absolute inset-0 bg-gradient-to-t from-[var(--surface-primary)]/90 via-transparent to-transparent"
                    ></div>
                </div>

                <!-- Profile Info Overlay -->
                <div
                    class="max-w-[1920px] mx-auto px-6 lg:px-8 relative -mt-24 pb-6"
                >
                    <div
                        class="flex flex-col md:flex-row items-end gap-6 md:gap-8"
                    >
                        <!-- Avatar -->
                        <div class="relative group">
                            <img
                                :src="user.avatar_url"
                                :alt="user.name"
                                class="w-32 h-32 md:w-40 md:h-40 rounded-full border-4 border-[var(--surface-primary)] shadow-xl object-cover bg-[var(--surface-secondary)]"
                            />
                            <div
                                class="absolute bottom-2 right-2 w-6 h-6 rounded-full border-2 border-[var(--surface-primary)]"
                                :class="{
                                    'bg-green-500':
                                        !user.status ||
                                        user.status === 'active',
                                    'bg-yellow-500': user.status === 'inactive',
                                    'bg-red-500': user.status === 'suspended',
                                }"
                                title="Status"
                            ></div>
                        </div>

                        <!-- Main Info -->
                        <div
                            class="flex-1 pb-2 w-full text-center md:text-left"
                        >
                            <h1
                                class="text-3xl md:text-4xl font-display font-bold text-[var(--text-primary)] mb-1 flex items-center justify-center md:justify-start gap-3"
                            >
                                {{ user.name }}
                                <span
                                    v-if="user.role_level >= 50"
                                    class="text-xs bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300 px-2 py-0.5 rounded-full border border-purple-200 dark:border-purple-800 uppercase tracking-wider font-bold"
                                    >{{
                                        user.role_level >= 100
                                            ? "Admin"
                                            : "Staff"
                                    }}</span
                                >
                            </h1>
                            <div
                                class="flex flex-wrap items-center justify-center md:justify-start gap-x-4 gap-y-2 text-[var(--text-secondary)] text-sm md:text-base"
                            >
                                <span class="flex items-center gap-1.5">
                                    <span class="text-[var(--text-tertiary)]"
                                        >@{{ user.username }}</span
                                    >
                                </span>
                                <span
                                    v-if="user.job_title"
                                    class="hidden md:inline text-[var(--text-disabled)]"
                                    >•</span
                                >
                                <span
                                    v-if="user.job_title"
                                    class="flex items-center gap-1.5"
                                >
                                    <Briefcase
                                        class="w-4 h-4 text-[var(--text-tertiary)]"
                                    />
                                    {{ user.job_title }}
                                </span>
                                <span
                                    v-if="user.location"
                                    class="hidden md:inline text-[var(--text-disabled)]"
                                    >•</span
                                >
                                <span
                                    v-if="user.location"
                                    class="flex items-center gap-1.5"
                                >
                                    <MapPin
                                        class="w-4 h-4 text-[var(--text-tertiary)]"
                                    />
                                    {{ user.location }}
                                </span>
                                <span
                                    class="hidden md:inline text-[var(--text-disabled)]"
                                    >•</span
                                >
                                <span class="flex items-center gap-1.5">
                                    <Calendar
                                        class="w-4 h-4 text-[var(--text-tertiary)]"
                                    />
                                    Joined {{ formatDate(user.joined_at) }}
                                </span>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-3 pb-4">
                            <!-- Placeholder for future actions like Message/Edit -->
                            <button
                                v-if="isOwnProfile"
                                class="btn btn-secondary"
                            >
                                <Edit2 class="w-4 h-4" />
                                <span>Edit Profile</span>
                            </button>
                            <button
                                v-else
                                class="btn btn-primary shadow-lg shadow-primary-500/20"
                                @click="handleMessage"
                                :disabled="startingChat"
                            >
                                <Loader2
                                    v-if="startingChat"
                                    class="w-4 h-4 animate-spin"
                                />
                                <Mail v-else class="w-4 h-4" />
                                <span>{{
                                    startingChat ? "Opening..." : "Message"
                                }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="max-w-[1920px] mx-auto px-6 lg:px-8">
                <div class="grid grid-cols-1 xl:grid-cols-12 gap-8">
                    <!-- Left Column: Details -->
                    <div class="xl:col-span-4 space-y-6">
                        <!-- About Card -->
                        <div class="card p-6">
                            <h3
                                class="font-display font-semibold text-lg text-[var(--text-primary)] mb-4 flex items-center gap-2"
                            >
                                <User
                                    class="w-5 h-5 text-[var(--interactive-primary)]"
                                />
                                About
                            </h3>

                            <div class="space-y-4">
                                <div
                                    v-if="user.bio"
                                    class="prose dark:prose-invert prose-sm max-w-none text-[var(--text-secondary)]"
                                >
                                    <p>{{ user.bio }}</p>
                                </div>
                                <div
                                    v-else
                                    class="text-sm text-[var(--text-tertiary)] italic"
                                >
                                    No bio provided.
                                </div>

                                <div
                                    class="border-t border-[var(--border-default)] my-4"
                                ></div>

                                <div class="grid grid-cols-1 gap-4 text-sm">
                                    <div
                                        v-if="user.email"
                                        class="flex flex-col"
                                    >
                                        <span
                                            class="text-[var(--text-tertiary)] text-xs uppercase tracking-wider font-semibold mb-1"
                                            >Email</span
                                        >
                                        <a
                                            :href="'mailto:' + user.email"
                                            class="text-[var(--text-primary)] hover:text-[var(--interactive-primary)] transition-colors truncate flex items-center gap-2"
                                        >
                                            <Mail class="w-3.5 h-3.5" />
                                            {{ user.email }}
                                        </a>
                                    </div>
                                    <div
                                        v-if="user.website"
                                        class="flex flex-col"
                                    >
                                        <span
                                            class="text-[var(--text-tertiary)] text-xs uppercase tracking-wider font-semibold mb-1"
                                            >Website</span
                                        >
                                        <a
                                            :href="user.website"
                                            target="_blank"
                                            class="text-[var(--text-primary)] hover:text-[var(--interactive-primary)] transition-colors truncate flex items-center gap-2"
                                        >
                                            <Globe class="w-3.5 h-3.5" />
                                            {{ user.website }}
                                        </a>
                                    </div>
                                    <!-- Teams Section - Splitted -->
                                    <div
                                        v-if="hasTeams"
                                        class="space-y-6 pt-4 border-t border-[var(--border-subtle)]"
                                    >
                                        <!-- Owned Teams -->
                                        <div
                                            v-if="ownedTeams.length"
                                            class="flex flex-col"
                                        >
                                            <div
                                                class="flex items-center gap-2 text-[var(--text-tertiary)] mb-3"
                                            >
                                                <Crown
                                                    class="w-4 h-4 text-amber-500"
                                                />
                                                <span
                                                    class="text-[10px] font-bold uppercase tracking-wider"
                                                    >Teams Owned</span
                                                >
                                            </div>
                                            <div class="grid grid-cols-1 gap-2">
                                                <router-link
                                                    v-for="team in ownedTeams"
                                                    :key="team.public_id"
                                                    :to="`/teams/${team.slug}`"
                                                    class="group flex items-center justify-between p-2 rounded-lg bg-[var(--surface-secondary)]/50 hover:bg-[var(--surface-secondary)] border border-[var(--border-subtle)] transition-all duration-200"
                                                >
                                                    <div
                                                        class="flex items-center gap-3"
                                                    >
                                                        <div
                                                            class="w-8 h-8 rounded bg-gradient-to-br from-indigo-500 to-indigo-600 flex items-center justify-center text-white font-bold text-xs shadow-sm"
                                                        >
                                                            {{
                                                                team.name.charAt(
                                                                    0,
                                                                )
                                                            }}
                                                        </div>
                                                        <span
                                                            class="text-xs font-medium group-hover:text-[var(--interactive-primary)] transition-colors"
                                                            >{{
                                                                team.name
                                                            }}</span
                                                        >
                                                    </div>
                                                    <ChevronRight
                                                        class="w-3.5 h-3.5 text-[var(--text-muted)] group-hover:translate-x-0.5 transition-transform"
                                                    />
                                                </router-link>
                                            </div>
                                        </div>

                                        <!-- Member Teams -->
                                        <div
                                            v-if="memberTeams.length"
                                            class="flex flex-col"
                                        >
                                            <div
                                                class="flex items-center gap-2 text-[var(--text-tertiary)] mb-3"
                                            >
                                                <Users class="w-4 h-4" />
                                                <span
                                                    class="text-[10px] font-bold uppercase tracking-wider"
                                                    >Member of Teams</span
                                                >
                                            </div>
                                            <div class="grid grid-cols-1 gap-2">
                                                <router-link
                                                    v-for="team in memberTeams"
                                                    :key="team.public_id"
                                                    :to="`/teams/${team.slug}`"
                                                    class="group flex items-center justify-between p-2 rounded-lg bg-[var(--surface-secondary)]/30 hover:bg-[var(--surface-secondary)] border border-[var(--border-subtle)] transition-all duration-200"
                                                >
                                                    <div
                                                        class="flex items-center gap-3"
                                                    >
                                                        <div
                                                            class="w-8 h-8 rounded bg-[var(--surface-tertiary)] flex items-center justify-center text-[var(--text-secondary)] font-bold text-xs border border-[var(--border-subtle)]"
                                                        >
                                                            {{
                                                                team.name.charAt(
                                                                    0,
                                                                )
                                                            }}
                                                        </div>
                                                        <div
                                                            class="flex flex-col"
                                                        >
                                                            <span
                                                                class="text-xs font-medium group-hover:text-[var(--interactive-primary)] transition-colors"
                                                                >{{
                                                                    team.name
                                                                }}</span
                                                            >
                                                            <span
                                                                class="text-[10px] text-[var(--text-muted)] leading-none mt-1"
                                                                >{{
                                                                    team.role
                                                                }}</span
                                                            >
                                                        </div>
                                                    </div>
                                                    <ChevronRight
                                                        class="w-3.5 h-3.5 text-[var(--text-muted)] group-hover:translate-x-0.5 transition-transform"
                                                    />
                                                </router-link>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Skills Card -->
                        <div class="card p-6">
                            <h3
                                class="font-display font-semibold text-lg text-[var(--text-primary)] mb-4 flex items-center gap-2"
                            >
                                <Zap class="w-5 h-5 text-yellow-500" />
                                Skills
                            </h3>
                            <div
                                v-if="user.skills && user.skills.length"
                                class="flex flex-wrap gap-2"
                            >
                                <span
                                    v-for="skill in user.skills"
                                    :key="skill"
                                    class="px-3 py-1 bg-[var(--surface-secondary)] text-[var(--text-secondary)] rounded-full text-xs font-medium border border-[var(--border-default)] hover:border-[var(--interactive-primary)] hover:text-[var(--interactive-primary)] transition-colors cursor-default"
                                >
                                    {{ skill }}
                                </span>
                            </div>
                            <div
                                v-else
                                class="text-sm text-[var(--text-tertiary)] italic"
                            >
                                No skills listed.
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Activity & Tasks -->
                    <div class="xl:col-span-8 space-y-6">
                        <!-- Stats Row (Placeholder/Future) -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div
                                class="card p-4 flex flex-col items-center justify-center text-center hover:border-[var(--interactive-primary)] transition-colors"
                            >
                                <span
                                    class="text-2xl font-bold text-[var(--text-primary)]"
                                    >{{ assignedTasks.length }}</span
                                >
                                <span
                                    class="text-xs text-[var(--text-secondary)] uppercase tracking-wider mt-1"
                                    >Assigned Tasks</span
                                >
                            </div>
                            <div
                                class="card p-4 flex flex-col items-center justify-center text-center hover:border-[var(--interactive-primary)] transition-colors"
                            >
                                <span
                                    class="text-2xl font-bold text-[var(--text-primary)]"
                                    >{{
                                        user.joined_at
                                            ? Math.floor(
                                                  (new Date().getTime() -
                                                      new Date(
                                                          user.joined_at,
                                                      ).getTime()) /
                                                      (1000 * 60 * 60 * 24),
                                              )
                                            : 0
                                    }}</span
                                >
                                >
                                <span
                                    class="text-xs text-[var(--text-secondary)] uppercase tracking-wider mt-1"
                                    >Days Active</span
                                >
                            </div>
                            <!-- Add more stats here later -->
                        </div>

                        <!-- Tasks Section -->
                        <div class="card flex flex-col min-h-[500px]">
                            <div
                                class="p-6 border-b border-[var(--border-default)] flex items-center justify-between"
                            >
                                <h3
                                    class="font-display font-semibold text-lg text-[var(--text-primary)] flex items-center gap-2"
                                >
                                    <ListChecks
                                        class="w-5 h-5 text-[var(--interactive-primary)]"
                                    />
                                    Assigned Work
                                </h3>
                                <div
                                    class="flex items-center gap-2 text-sm text-[var(--text-secondary)]"
                                >
                                    <span
                                        v-if="loadingTasks"
                                        class="flex items-center gap-2 animate-pulse"
                                    >
                                        <Loader2
                                            class="w-3.5 h-3.5 animate-spin"
                                        />
                                        Updating...
                                    </span>
                                    <span v-else
                                        >{{ assignedTasks.length }} Task{{
                                            assignedTasks.length !== 1
                                                ? "s"
                                                : ""
                                        }}</span
                                    >
                                </div>
                            </div>

                            <div class="flex-1 p-6 relative">
                                <div
                                    v-if="loadingTasks"
                                    class="absolute inset-0 flex items-center justify-center bg-[var(--surface-elevated)]/50 backdrop-blur-sm z-10 rounded-b-xl"
                                >
                                    <Loader2
                                        class="w-8 h-8 animate-spin text-[var(--interactive-primary)]"
                                    />
                                </div>

                                <TaskList
                                    v-if="assignedTasks.length > 0"
                                    :tasks="assignedTasks"
                                    :show-project="true"
                                    class="bg-transparent border-none shadow-none p-0"
                                />

                                <div
                                    v-else-if="!loadingTasks"
                                    class="flex flex-col items-center justify-center py-12 text-center h-full"
                                >
                                    <div
                                        class="w-16 h-16 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mb-4"
                                    >
                                        <ListChecks
                                            class="w-8 h-8 text-[var(--text-tertiary)]"
                                        />
                                    </div>
                                    <h4
                                        class="text-[var(--text-primary)] font-medium mb-1"
                                    >
                                        No tasks assigned
                                    </h4>
                                    <p
                                        class="text-[var(--text-secondary)] text-sm max-w-xs"
                                    >
                                        This user suggests they currently have a
                                        clean plate.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed, watch } from "vue";
import { useRoute } from "vue-router";
import {
    Shield,
    Briefcase,
    MapPin,
    Calendar,
    Edit2,
    CheckCircle2,
    ChevronRight,
    Clock,
    Crown,
    Mail,
    User,
    Globe,
    Zap,
    ListChecks,
    Loader2,
    Users,
} from "lucide-vue-next";
import { useDate } from "@/composables/useDate";
const { formatDate } = useDate();
import api from "@/lib/api";
import { useAuthStore } from "@/stores/auth";
import { useChatStore } from "@/stores/chat";
import { useMiniChatStore } from "@/stores/minichat";
import { useToast } from "@/composables/useToast";
// import PersonalTaskWidget from "@/components/tasks/PersonalTaskWidget.vue";
import TaskList from "@/components/tasks/TaskList.vue";

const route = useRoute();
const authStore = useAuthStore();
const chatStore = useChatStore();
const miniChatStore = useMiniChatStore();
const toast = useToast();

interface UserProfile {
    public_id: string;
    name: string;
    username: string;
    avatar_url: string;
    bio: string | null;
    job_title: string | null;
    location: string | null;
    website: string | null;
    skills: string[];
    joined_at: string;
    role_level: number;
    email?: string;
    cover_photo_url?: string | null;
    cover_photo_offset?: number;
    status?: string | null;
    teams?: Array<{ public_id: string; name: string; role: string }>;
}

const user = ref<UserProfile | null>(null);
const ownedTeams = computed(
    () => user.value?.teams?.filter((t) => t.is_owner) || [],
);
const memberTeams = computed(
    () => user.value?.teams?.filter((t) => !t.is_owner) || [],
);
const hasTeams = computed(
    () => user.value?.teams && user.value.teams.length > 0,
);
const loading = ref(true);
const error = ref<string | null>(null);
const isCoverLoaded = ref(false);
const assignedTasks = ref([]);
const loadingTasks = ref(false);
const startingChat = ref(false);

// Computed property to check if the viewed profile is the current user's own profile
const isOwnProfile = computed(() => {
    return (
        authStore.user &&
        user.value &&
        authStore.user.public_id === user.value.public_id
    );
});

const onCoverLoad = () => {
    isCoverLoaded.value = true;
};

watch(
    () => user.value?.cover_photo_url,
    () => {
        isCoverLoaded.value = false;
    },
);

const handleMessage = async () => {
    if (!user.value) return;

    startingChat.value = true;
    try {
        const response = await chatStore.ensureDm(user.value.public_id);

        if (response.status === "chat_exists" && response.data) {
            miniChatStore.openChatWindow(response.data);
        } else if (response.status === "invite_required") {
            // Send invite immediately or show confirmation?
            // For now, let's just send the invite to streamline the flow as requested "wire it as dm invite"
            await chatStore.sendInvite(user.value.public_id);
            toast.success("Invitation sent to " + user.value.name);
        }
    } catch (err: any) {
        if (
            err.response?.status === 202 &&
            err.response?.data?.status === "invite_pending"
        ) {
            toast.info("Invite already pending.");
        } else {
            console.error("Failed to start chat", err);
            toast.error(err.response?.data?.message || "Failed to start chat.");
        }
    } finally {
        startingChat.value = false;
    }
};

const fetchAssignedTasks = async (publicId: string) => {
    loadingTasks.value = true;
    try {
        const response = await api.get(`/api/users/${publicId}/assigned-tasks`);
        assignedTasks.value = response.data.data;
    } catch (err) {
        console.error("Failed to fetch assigned tasks", err);
    } finally {
        loadingTasks.value = false;
    }
};

const fetchProfile = async () => {
    loading.value = true;
    error.value = null;
    const publicId = route.params.public_id as string;

    if (!publicId) {
        error.value = "No user ID provided.";
        loading.value = false;
        return;
    }

    try {
        // API endpoint includes /api prefix via the api client
        const response = await api.get(`/api/users/${publicId}/profile`);
        user.value = response.data.data || response.data;

        // Fetch assigned tasks after profile is loaded
        fetchAssignedTasks(publicId);
    } catch (err: any) {
        console.error("Failed to load profile", err);
        if (err.response?.status === 403) {
            error.value =
                "You are not authorized to view this profile. You must share a team with this user.";
        } else if (err.response?.status === 404) {
            error.value = "User not found.";
        } else {
            error.value = "Failed to load user profile.";
        }
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchProfile();
});

// Refetch if route changes (e.g. clicking another user profile link)
watch(
    () => route.params.public_id,
    (newId) => {
        if (newId) fetchProfile();
    },
);
</script>
