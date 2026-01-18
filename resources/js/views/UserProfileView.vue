<template>
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div v-if="loading" class="flex justify-center py-12">
            <div
                class="animate-spin rounded-full h-12 w-12 border-b-2 border-brand-500"
            ></div>
        </div>

        <div v-else-if="error" class="text-center py-12">
            <div class="bg-red-50 text-red-600 p-4 rounded-lg inline-block">
                {{ error }}
            </div>
        </div>

        <div v-else-if="user" class="space-y-6">
            <!-- Header Profile Card -->
            <div
                class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 overflow-hidden"
            >
                <div
                    class="h-32 bg-gradient-to-r from-brand-500 to-indigo-600 relative"
                >
                    <div class="absolute -bottom-12 left-8">
                        <img
                            :src="user.avatar_url"
                            :alt="user.name"
                            class="w-24 h-24 rounded-full border-4 border-white dark:border-zinc-900 object-cover shadow-md"
                        />
                    </div>
                </div>
                <div class="pt-16 pb-6 px-8 flex justify-between items-start">
                    <div>
                        <h1
                            class="text-2xl font-bold text-zinc-900 dark:text-zinc-100"
                        >
                            {{ user.name }}
                        </h1>
                        <p class="text-zinc-500 dark:text-zinc-400">
                            @{{ user.username }}
                        </p>
                        <div class="flex items-center gap-2 mt-2">
                            <span
                                class="px-2 py-1 text-xs font-semibold rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 border border-zinc-200 dark:border-zinc-700"
                            >
                                Level {{ user.role_level }}
                            </span>
                            <span
                                class="text-sm text-zinc-500 dark:text-zinc-400"
                                v-if="user.joined_at"
                            >
                                Joined {{ formatDate(user.joined_at) }}
                            </span>
                        </div>
                    </div>
                    <!-- Future Actions like 'Message' could go here -->
                </div>
            </div>

            <!-- Content Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Left Sidebar: Info -->
                <div class="space-y-6">
                    <div
                        class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6"
                    >
                        <h3
                            class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4"
                        >
                            About
                        </h3>

                        <div class="space-y-4">
                            <div v-if="user.job_title">
                                <label
                                    class="text-xs uppercase tracking-wider text-zinc-500 dark:text-zinc-500 font-semibold"
                                    >Role / Title</label
                                >
                                <p class="text-zinc-800 dark:text-zinc-200">
                                    {{ user.job_title }}
                                </p>
                            </div>

                            <div v-if="user.email">
                                <label
                                    class="text-xs uppercase tracking-wider text-zinc-500 dark:text-zinc-500 font-semibold"
                                    >Email</label
                                >
                                <p class="text-zinc-800 dark:text-zinc-200">
                                    {{ user.email }}
                                </p>
                            </div>

                            <div v-if="user.location">
                                <label
                                    class="text-xs uppercase tracking-wider text-zinc-500 dark:text-zinc-500 font-semibold"
                                    >Location</label
                                >
                                <p class="text-zinc-800 dark:text-zinc-200">
                                    {{ user.location }}
                                </p>
                            </div>
                            <div v-if="user.website">
                                <label
                                    class="text-xs uppercase tracking-wider text-zinc-500 dark:text-zinc-500 font-semibold"
                                    >Website</label
                                >
                                <a
                                    :href="user.website"
                                    target="_blank"
                                    class="text-brand-600 hover:text-brand-700 block truncate"
                                    >{{ user.website }}</a
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Main: Bio & Skills -->
                <div class="md:col-span-2 space-y-6">
                    <!-- Bio -->
                    <div
                        class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6"
                        v-if="user.bio"
                    >
                        <h3
                            class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4"
                        >
                            Bio
                        </h3>
                        <p
                            class="text-zinc-600 dark:text-zinc-300 leading-relaxed whitespace-pre-line"
                        >
                            {{ user.bio }}
                        </p>
                    </div>

                    <!-- Skills -->
                    <div
                        class="bg-white dark:bg-zinc-900 rounded-xl shadow-sm border border-zinc-200 dark:border-zinc-800 p-6"
                        v-if="user.skills && user.skills.length"
                    >
                        <h3
                            class="text-lg font-semibold text-zinc-900 dark:text-zinc-100 mb-4"
                        >
                            Skills
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <span
                                v-for="skill in user.skills"
                                :key="skill"
                                class="px-3 py-1 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300 rounded-md text-sm border border-brand-100 dark:border-brand-800"
                            >
                                {{ skill }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { useRoute } from "vue-router";
import api from "@/lib/api";

const route = useRoute();

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString(undefined, {
        year: "numeric",
        month: "long",
        day: "numeric",
    });
};

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
}

const user = ref<UserProfile | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const fetchProfile = async () => {
    loading.value = true;
    error.value = null;
    const publicId = route.params.public_id as string;

    try {
        // Updated backend route is /users/{user}/profile, assuming {user} matches route key 'public_id'
        const response = await api.get(`/users/${publicId}/profile`);
        user.value = response.data.data;
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
