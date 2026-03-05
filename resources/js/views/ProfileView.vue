<script setup>
import { ref, onMounted, computed, watch } from "vue";
import { useDate } from "@/composables/useDate";
const { formatDate } = useDate();
import { useAuthStore } from "@/stores/auth";
import { useChatStore } from "@/stores/chat";
import { useMiniChatStore } from "@/stores/minichat";
import { Card, Button, Badge, Avatar, Input } from "@/components/ui";
import MiniCalendar from "@/components/ui/MiniCalendar.vue";
import PersonalTaskWidget from "@/components/tasks/PersonalTaskWidget.vue";
import {
    Mail,
    MapPin,
    Calendar,
    Link as LinkIcon,
    Edit,
    Camera,
    Users,
    FileText,
    CheckSquare,
    Plus,
    Trash2,
    Download,
    Briefcase,
    Shield,
    Share,
    Folder,
    CheckCircle,
    Clock,
    Sparkles,
    Globe,
    Copy,
    ExternalLink,
    MessageSquare,
} from "lucide-vue-next";
import { Switch } from "@/components/ui";
import { toast } from "vue-sonner";
import api from "@/lib/api";

const authStore = useAuthStore();
const chatStore = useChatStore();
const miniChatStore = useMiniChatStore();
const isLoading = ref(true);
const userDetails = ref(null);

const projectDisplay = ref("grid");
const teammateFilter = ref("all");
const projects = ref([]);
const teammates = ref([]);

const filteredTeammates = computed(() => {
    if (teammateFilter.value === "all") return teammates.value;
    return teammates.value.filter(
        (t) => t.relationship === teammateFilter.value,
    );
});

const startChat = async (teammate) => {
    try {
        const result = await chatStore.ensureDm(teammate.public_id);

        if (result.status === "invite_required") {
            await chatStore.sendInvite(teammate.public_id);
            toast.success(`Chat invite sent to ${teammate.name}`);
            return;
        }

        if (result.status === "chat_exists" || result.public_id) {
            const chat = result.data || result;
            miniChatStore.openChatWindow(chat);
        }
    } catch (error) {
        console.error("Failed to start chat:", error);
        toast.error("Could not start chat");
    }
};

const fetchProjects = async (publicId) => {
    try {
        const response = await api.get(`/api/users/${publicId}/projects`);
        projects.value = response.data.data;
    } catch (error) {
        console.error(error);
    }
};

const fetchTeammates = async (publicId) => {
    try {
        const response = await api.get(`/api/users/${publicId}/teammates`);
        teammates.value = response.data.data;
    } catch (error) {
        console.error(error);
    }
};

// Mock Data for Calendar
const currentMonth = ref(new Date());
const weekDays = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];

const stats = computed(() => {
    const userStats = userDetails.value?.stats || {
        projects_count: 0,
        completed_tasks_count: 0,
        hours_logged: 0,
    };

    return [
        {
            label: "Projects",
            value: userStats.projects_count,
            icon: Folder,
            color: "text-blue-500",
            bg: "bg-blue-500/10",
        },
        {
            label: "Tasks Completed",
            value: userStats.completed_tasks_count,
            icon: CheckCircle,
            color: "text-green-500",
            bg: "bg-green-500/10",
        },
        {
            label: "Hours Logged",
            value: userStats.hours_logged,
            icon: Clock,
            color: "text-orange-500",
            bg: "bg-orange-500/10",
        },
    ];
});

// Fetch full user details
const fetchUserDetails = async () => {
    isLoading.value = true;
    try {
        const response = await api.get("/api/user/details");
        userDetails.value = response.data;
        isPublic.value = !!response.data.is_public;

        const publicId = response.data.public_id;
        if (publicId) {
            fetchProjects(publicId);
            fetchTeammates(publicId);
        }
    } catch (error) {
        console.error("Failed to fetch user details:", error);
    } finally {
        isLoading.value = false;
    }
};

const isPublic = ref(false);
const isUpdatingVisibility = ref(false);

const publicProfileUrl = computed(() => {
    if (!userDetails.value?.username) return "";
    return `${window.location.origin}/p/${userDetails.value.username}`;
});

const toggleVisibility = async (newValue) => {
    isUpdatingVisibility.value = true;
    try {
        // Optimistic update
        const previousValue = isPublic.value;
        isPublic.value = newValue;

        const response = await api.put("/api/user/profile/visibility", {
            is_public: newValue,
        });
        isPublic.value = response.data.is_public;

        // Update local user details
        if (userDetails.value) {
            userDetails.value.is_public = response.data.is_public;
        }
    } catch (error) {
        console.error("Failed to update visibility:", error);
        // Revert on error
        isPublic.value = !newValue;
    } finally {
        isUpdatingVisibility.value = false;
    }
};

const copyPublicUrl = () => {
    if (!publicProfileUrl.value) return;
    navigator.clipboard.writeText(publicProfileUrl.value);
    toast.success("Link copied to clipboard");
};

const calendarDays = computed(() => {
    const year = currentMonth.value.getFullYear();
    const month = currentMonth.value.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);

    const days = [];

    // Padding for start of month
    for (let i = 0; i < firstDay.getDay(); i++) {
        days.push({ day: "", isCurrentMonth: false });
    }

    // Days of month
    const today = new Date();
    for (let i = 1; i <= lastDay.getDate(); i++) {
        const isToday =
            today.getDate() === i &&
            today.getMonth() === month &&
            today.getFullYear() === year;
        days.push({
            day: i,
            isCurrentMonth: true,
            isToday,
        });
    }

    return days;
});

const files = computed(() => userDetails.value?.files || []);
const teams = computed(() => userDetails.value?.teams || []);
const skills = ref([
    "Vue.js",
    "TypeScript",
    "Node.js",
    "Tailwind CSS",
    "PostgreSQL",
    "Docker",
]);

const coverStyle = computed(() => {
    const offset = authStore.user?.preferences?.appearance?.cover_offset ?? 50;
    return {
        objectPosition: `center ${offset}%`,
    };
});

const isCoverLoaded = ref(false);
const onCoverLoad = () => {
    isCoverLoaded.value = true;
};

watch(
    () => authStore.user?.cover_photo_url,
    () => {
        isCoverLoaded.value = false;
    },
);

onMounted(() => {
    fetchUserDetails();
});
</script>

<template>
    <div class="w-full space-y-6">
        <!-- Profile Header -->
        <Card padding="none" class="overflow-visible">
            <!-- Cover Image -->
            <div
                class="h-50 bg-[var(--surface-secondary)] rounded-t-xl relative overflow-hidden group"
            >
                <img
                    v-if="authStore.user?.cover_photo_url"
                    :src="authStore.user.cover_photo_url"
                    class="w-full h-full object-cover transition-opacity duration-500"
                    :class="{
                        'opacity-0': !isCoverLoaded,
                        'opacity-100': isCoverLoaded,
                    }"
                    :style="coverStyle"
                    alt="Cover Photo"
                    @load="onCoverLoad"
                />
                <div
                    v-else
                    class="w-full h-full bg-gradient-to-r from-[var(--color-primary-500)] to-[var(--color-primary-700)]"
                ></div>

                <router-link to="/settings?tab=profile">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="absolute top-4 right-4 bg-black/20 text-white hover:bg-black/30 backdrop-blur-sm opacity-0 group-hover:opacity-100 transition-opacity"
                    >
                        <Camera class="h-4 w-4 mr-2" />
                        Change cover
                    </Button>
                </router-link>
            </div>

            <!-- Profile Info -->
            <div class="px-8 pb-8">
                <div
                    class="flex flex-col md:flex-row md:items-end gap-6 relative"
                >
                    <!-- Avatar wrapper with negative margin to overlapping cover -->
                    <div
                        class="relative shrink-0 -mt-20 md:-mt-24 mb-4 md:mb-0"
                    >
                        <Avatar
                            :fallback="authStore.initials"
                            :src="authStore.user?.avatar_url"
                            size="4xl"
                            class="border-4 border-[var(--surface-primary)] shadow-xl relative z-10 bg-[var(--surface-primary)]"
                            :status="authStore.user?.presence"
                        />
                        <router-link to="/settings?tab=profile">
                            <button
                                class="absolute bottom-1 right-1 flex h-8 w-8 items-center justify-center rounded-full bg-[var(--surface-elevated)] border border-[var(--border-default)] text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors shadow-sm z-20 cursor-pointer"
                            >
                                <Camera class="h-4 w-4" />
                            </button>
                        </router-link>
                    </div>

                    <div class="flex-1 min-w-0 md:pb-4">
                        <h1
                            class="text-3xl font-bold text-[var(--text-primary)]"
                        >
                            {{ authStore.displayName }}
                        </h1>
                        <p class="text-[var(--text-secondary)] text-lg">
                            {{ authStore.user?.title || "Team Member" }}
                        </p>
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
                <div
                    class="mt-6 p-4 bg-[var(--surface-elevated)] rounded-lg border border-[var(--border-default)] flex flex-col sm:flex-row sm:items-center justify-between gap-4"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="p-2 rounded-full bg-blue-500/10 text-blue-600 dark:text-blue-400"
                        >
                            <Globe class="h-5 w-5" />
                        </div>
                        <div>
                            <p class="font-medium text-[var(--text-primary)]">
                                Public Profile
                            </p>
                            <p class="text-xs text-[var(--text-secondary)]">
                                Allow anyone to view your profile via a unique
                                link
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <div
                            v-if="isPublic"
                            class="flex items-center gap-2 bg-[var(--surface-primary)] px-3 py-1.5 rounded-md border border-[var(--border-default)] max-w-[200px] sm:max-w-xs"
                        >
                            <span
                                class="text-xs text-[var(--text-secondary)] truncate"
                                >{{ publicProfileUrl }}</span
                            >
                            <button
                                @click="copyPublicUrl"
                                class="text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
                                title="Copy Link"
                            >
                                <Copy class="h-3.5 w-3.5" />
                            </button>
                            <a
                                :href="publicProfileUrl"
                                target="_blank"
                                class="text-[var(--text-muted)] hover:text-[var(--interactive-primary)] transition-colors"
                                title="Open Link"
                            >
                                <ExternalLink class="h-3.5 w-3.5" />
                            </a>
                        </div>

                        <div class="flex items-center gap-2">
                            <span
                                class="text-sm font-medium"
                                :class="
                                    isPublic
                                        ? 'text-[var(--text-primary)]'
                                        : 'text-[var(--text-muted)]'
                                "
                            >
                                {{ isPublic ? "On" : "Off" }}
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
                <div
                    class="flex flex-wrap gap-6 mt-8 p-4 bg-[var(--surface-secondary)] rounded-lg border border-[var(--border-default)]"
                >
                    <div
                        class="flex items-center gap-2 text-sm text-[var(--text-secondary)]"
                        v-if="authStore.user?.email"
                    >
                        <Mail class="h-4 w-4" />
                        {{ authStore.user.email }}
                    </div>
                    <div
                        class="flex items-center gap-2 text-sm text-[var(--text-secondary)]"
                        v-if="authStore.user?.location"
                    >
                        <MapPin class="h-4 w-4" />
                        {{ authStore.user.location }}
                    </div>
                    <div
                        class="flex items-center gap-2 text-sm text-[var(--text-secondary)]"
                    >
                        <Calendar class="h-4 w-4" />
                        Joined
                        {{
                            authStore.user?.created_at
                                ? formatDate(authStore.user.created_at)
                                : "Loading..."
                        }}
                    </div>
                    <div
                        class="flex items-center gap-2 text-sm text-[var(--text-secondary)]"
                        v-if="authStore.user?.website"
                    >
                        <LinkIcon class="h-4 w-4" />
                        <a
                            :href="authStore.user.website"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-[var(--interactive-primary)] hover:underline"
                            >{{
                                authStore.user.website.replace(
                                    /^https?:\/\//,
                                    "",
                                )
                            }}</a
                        >
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
                    <h2
                        class="text-xl font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2"
                    >
                        <Briefcase
                            class="h-5 w-5 text-[var(--text-secondary)]"
                        />
                        About
                    </h2>
                    <p
                        class="text-[var(--text-secondary)] leading-relaxed whitespace-pre-line break-words"
                        v-if="authStore.user?.bio"
                    >
                        {{ authStore.user.bio }}
                    </p>
                    <p class="text-[var(--text-muted)] italic" v-else>
                        No bio added yet.
                    </p>

                    <div
                        class="mt-8"
                        v-if="
                            authStore.user?.skills &&
                            authStore.user.skills.length
                        "
                    >
                        <h3
                            class="text-sm font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2"
                        >
                            <Sparkles class="h-4 w-4 text-amber-500" />
                            Skills & Expertise
                        </h3>
                        <div class="flex flex-wrap gap-2.5 mt-2">
                            <div
                                v-for="skill in authStore.user.skills"
                                :key="skill"
                                class="flex items-center gap-2 px-3 py-1.5 bg-(--surface-secondary) dark:bg-white/10 hover:bg-(--surface-tertiary) dark:hover:bg-white/20 border border-(--border-subtle) rounded-lg text-[11px] font-semibold text-(--text-secondary) hover:text-(--text-primary) transition-all duration-200 cursor-default shadow-xs hover:shadow-sm uppercase tracking-wider group/skill"
                            >
                                <div
                                    class="h-1 w-1 rounded-full bg-(--interactive-primary) opacity-50 group-hover/skill:opacity-100 transition-opacity"
                                ></div>
                                {{ skill }}
                            </div>
                        </div>
                    </div>
                </Card>

                <!-- Projects -->
                <Card padding="lg">
                    <div class="flex items-center justify-between mb-6">
                        <h2
                            class="text-xl font-semibold text-[var(--text-primary)] flex items-center gap-2"
                        >
                            <Folder
                                class="h-5 w-5 text-[var(--text-secondary)]"
                            />
                            Projects
                        </h2>
                        <div
                            class="flex gap-2 bg-[var(--surface-primary)] p-1 rounded-lg border border-[var(--border-default)] items-center"
                        >
                            <button
                                @click="projectDisplay = 'grid'"
                                :class="
                                    projectDisplay === 'grid'
                                        ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                        : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                "
                                class="px-3 py-1 text-xs font-medium rounded-md transition-all"
                            >
                                Grid
                            </button>
                            <button
                                @click="projectDisplay = 'list'"
                                :class="
                                    projectDisplay === 'list'
                                        ? 'bg-[var(--surface-elevated)] text-[var(--text-primary)] shadow-sm'
                                        : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                "
                                class="px-3 py-1 text-xs font-medium rounded-md transition-all"
                            >
                                List
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="projects.length === 0"
                        class="text-center py-8 text-[var(--text-muted)] bg-[var(--surface-secondary)] rounded-lg border border-dashed border-[var(--border-default)]"
                    >
                        <Folder class="h-8 w-8 mx-auto mb-2 opacity-50" />
                        <p>No projects yet</p>
                    </div>

                    <!-- Grid View -->
                    <div
                        v-else-if="projectDisplay === 'grid'"
                        class="grid grid-cols-1 sm:grid-cols-2 gap-4"
                    >
                        <div
                            v-for="project in projects"
                            :key="project.id"
                            class="p-4 rounded-xl border border-[var(--border-default)] hover:border-[var(--interactive-primary)] bg-[var(--surface-secondary)] transition-all group flex flex-col gap-2 relative overflow-hidden"
                        >
                            <div
                                class="flex justify-between items-start mb-2 group-hover:px-1 transition-all"
                            >
                                <h3
                                    class="font-medium text-[var(--text-primary)] truncate transition-colors"
                                >
                                    {{ project.name }}
                                </h3>
                                <Badge
                                    :variant="
                                        project.status.value === 'completed'
                                            ? 'success'
                                            : 'outline'
                                    "
                                    size="xs"
                                    >{{ project.status.label }}</Badge
                                >
                            </div>
                            <div
                                class="w-full bg-[var(--surface-tertiary)] rounded-full h-1.5 mt-auto"
                            >
                                <div
                                    class="bg-[var(--interactive-primary)] h-1.5 rounded-full transition-all duration-1000 ease-out"
                                    :style="{
                                        width:
                                            (project.progress_percentage || 0) +
                                            '%',
                                    }"
                                ></div>
                            </div>
                            <div
                                class="flex justify-between text-[11px] text-[var(--text-muted)] mt-1 font-medium"
                            >
                                <span
                                    >{{ project.tasks_count || 0 }} tasks</span
                                >
                                <span
                                    v-if="project.due_date"
                                    :class="{
                                        'text-red-500': project.is_overdue,
                                    }"
                                    >Due
                                    {{ formatDate(project.due_date) }}</span
                                >
                            </div>
                        </div>
                    </div>

                    <!-- List View -->
                    <div v-else class="space-y-3">
                        <div
                            v-for="project in projects"
                            :key="project.id"
                            class="flex flex-col sm:flex-row sm:items-center justify-between p-3 rounded-lg border border-[var(--border-default)] hover:bg-[var(--surface-secondary)] transition-colors group relative overflow-hidden gap-3 sm:gap-0"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="h-10 w-10 rounded-lg bg-[var(--surface-elevated)] flex items-center justify-center shrink-0 border border-[var(--border-default)]"
                                >
                                    <Folder
                                        class="h-5 w-5 text-[var(--text-secondary)] group-hover:text-[var(--interactive-primary)] transition-colors"
                                    />
                                </div>
                                <div class="min-w-0 pr-4">
                                    <p
                                        class="font-medium text-[var(--text-primary)] group-hover:text-[var(--interactive-primary)] transition-colors truncate"
                                    >
                                        {{ project.name }}
                                    </p>
                                    <div
                                        class="text-[11px] text-[var(--text-muted)] flex gap-2 font-medium"
                                    >
                                        <span
                                            >{{
                                                project.progress_percentage ||
                                                0
                                            }}% Complete</span
                                        >
                                        <span
                                            v-if="project.due_date"
                                            :class="{
                                                'text-red-500':
                                                    project.is_overdue,
                                            }"
                                            >• Due
                                            {{
                                                formatDate(project.due_date)
                                            }}</span
                                        >
                                    </div>
                                </div>
                            </div>
                            <Badge
                                :variant="
                                    project.status.value === 'completed'
                                        ? 'success'
                                        : 'outline'
                                "
                                size="xs"
                                class="ml-[3.25rem] sm:ml-0 shrink-0"
                                >{{ project.status.label }}</Badge
                            >
                        </div>
                    </div>
                </Card>

                <!-- Teammates -->
                <Card padding="lg">
                    <div
                        class="flex flex-col sm:flex-row sm:items-center justify-between mb-6 gap-4"
                    >
                        <h2
                            class="text-xl font-semibold text-[var(--text-primary)] flex items-center gap-2"
                        >
                            <Users
                                class="h-5 w-5 text-[var(--text-secondary)]"
                            />
                            Teammates
                        </h2>
                        <select
                            v-model="teammateFilter"
                            class="bg-[var(--surface-elevated)] border border-[var(--border-default)] text-[var(--text-primary)] text-sm rounded-lg focus:ring-[var(--interactive-primary)] focus:border-[var(--interactive-primary)] block py-1.5 px-3 max-w-full sm:max-w-[200px] shadow-sm"
                        >
                            <option value="all">All Teammates</option>
                            <option value="owned">Teams I Own</option>
                            <option value="member">
                                Teams I'm a Member Of
                            </option>
                        </select>
                    </div>

                    <div
                        v-if="filteredTeammates.length === 0"
                        class="text-center py-8 text-[var(--text-muted)] bg-[var(--surface-secondary)] rounded-lg border border-dashed border-[var(--border-default)]"
                    >
                        <Users class="h-8 w-8 mx-auto mb-2 opacity-50" />
                        <p>No teammates found</p>
                    </div>

                    <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div
                            v-for="teammate in filteredTeammates"
                            :key="teammate.public_id"
                            class="flex items-center gap-4 p-3 rounded-xl border border-[var(--border-default)] hover:border-[var(--interactive-primary)] hover:bg-[var(--surface-secondary)] hover:shadow-sm transition-all group"
                        >
                            <Avatar
                                :fallback="teammate.initials"
                                :src="teammate.avatar_url"
                                size="md"
                                class="shrink-0"
                                :status="teammate.presence"
                            />
                            <div class="flex-1 min-w-0">
                                <p
                                    class="font-medium text-[var(--text-primary)] truncate group-hover:text-[var(--interactive-primary)] transition-colors"
                                >
                                    {{ teammate.name }}
                                </p>
                                <p
                                    class="text-[11px] text-[var(--text-muted)] truncate font-medium"
                                >
                                    {{ teammate.job_title || "Team Member" }}
                                </p>
                            </div>
                            <Button
                                @click.prevent="startChat(teammate)"
                                variant="ghost"
                                size="icon-sm"
                                class="shrink-0 opacity-0 group-hover:opacity-100 transition-all text-blue-500 hover:text-blue-600 bg-blue-50 dark:bg-blue-500/10 hover:bg-blue-100 dark:hover:bg-blue-500/20 translate-x-2 group-hover:translate-x-0"
                                title="Send Message"
                            >
                                <MessageSquare class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                </Card>

                <!-- Uploaded Files -->
                <Card padding="lg">
                    <div class="flex items-center justify-between mb-6">
                        <h2
                            class="text-xl font-semibold text-[var(--text-primary)] flex items-center gap-2"
                        >
                            <FileText
                                class="h-5 w-5 text-[var(--text-secondary)]"
                            />
                            Recent Files
                        </h2>
                        <router-link to="/settings?tab=profile">
                            <Button variant="outline" size="sm">
                                <Plus class="h-4 w-4 mr-2" />
                                Upload
                            </Button>
                        </router-link>
                    </div>

                    <div
                        v-if="files.length === 0"
                        class="text-center py-12 text-[var(--text-muted)] bg-[var(--surface-secondary)] rounded-lg border border-dashed border-[var(--border-default)]"
                    >
                        <FileText class="h-10 w-10 mx-auto mb-3 opacity-50" />
                        <p class="font-medium">No files uploaded</p>
                        <p class="text-sm">
                            Upload documents to share with your team
                        </p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="file in files"
                            :key="file.id"
                            class="flex items-center justify-between p-3 rounded-lg border border-[var(--border-default)] hover:bg-[var(--surface-secondary)] transition-colors group"
                        >
                            <div
                                class="flex items-center gap-3 overflow-hidden"
                            >
                                <div
                                    class="h-10 w-10 rounded-lg bg-[var(--surface-elevated)] flex items-center justify-center shrink-0 border border-[var(--border-default)]"
                                >
                                    <FileText
                                        class="h-5 w-5 text-[var(--text-secondary)]"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <p
                                        class="font-medium text-[var(--text-primary)] truncate"
                                    >
                                        {{ file.name }}
                                    </p>
                                    <p class="text-xs text-[var(--text-muted)]">
                                        {{ formatDate(file.created_at) }} •
                                        {{ (file.size / 1024).toFixed(1) }} KB
                                    </p>
                                </div>
                            </div>
                            <div
                                class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity"
                            >
                                <a
                                    :href="file.download_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon-sm"
                                        title="Download"
                                    >
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
                    <h2
                        class="text-sm font-semibold text-[var(--text-muted)] uppercase tracking-wider mb-4"
                    >
                        Overview
                    </h2>
                    <div class="grid grid-cols-3 gap-4">
                        <div
                            v-for="stat in stats"
                            :key="stat.label"
                            class="flex flex-col items-center justify-center p-4 rounded-xl bg-[var(--surface-secondary)] border border-[var(--border-default)]"
                        >
                            <div class="p-2 rounded-full mb-3" :class="stat.bg">
                                <component
                                    :is="stat.icon"
                                    class="h-5 w-5"
                                    :class="stat.color"
                                />
                            </div>
                            <p
                                class="text-2xl font-bold text-[var(--text-primary)]"
                            >
                                {{ stat.value }}
                            </p>
                            <p
                                class="text-xs text-[var(--text-muted)] mt-1 truncate font-medium"
                            >
                                {{ stat.label }}
                            </p>
                        </div>
                    </div>
                </Card>

                <!-- Mini Calendar -->
                <Card padding="lg">
                    <MiniCalendar :show-holidays="true" country-code="US" />
                </Card>

                <!-- To-Do List (Now Personal Tasks) -->
                <PersonalTaskWidget />
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
