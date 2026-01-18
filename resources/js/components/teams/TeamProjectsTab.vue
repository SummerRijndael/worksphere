<script setup lang="ts">
import { ref, onMounted } from "vue";
import { Badge, Card, Avatar } from "@/components/ui";
import { Folder, Clock } from "lucide-vue-next";
import { useRouter } from "vue-router";
import axios from "axios";
import { format } from "date-fns";

const props = defineProps<{
    teamId: string;
}>();

const router = useRouter();
const projects = ref<any[]>([]);
const loading = ref(true);

const fetchProjects = async () => {
    loading.value = true;
    try {
        const response = await axios.get(`/api/teams/${props.teamId}/projects`);
        projects.value = response.data.data;
    } catch (error) {
        console.error("Error fetching projects:", error);
    } finally {
        loading.value = false;
    }
};

const formatDate = (dateString?: string) => {
    if (!dateString) return "-";
    return format(new Date(dateString), "MMM d, yyyy");
};

const getStatusColor = (status: any) => {
    // Handle object or string status
    const value = status?.value || status || 'draft';
    const variants: Record<string, string> = {
        draft: 'secondary',
        active: 'primary',
        on_hold: 'warning',
        completed: 'success',
        cancelled: 'error',
        archived: 'secondary',
    };
    return variants[value] || 'secondary';
};

const getStatusLabel = (status: any) => {
    return status?.label || status?.value || status || 'Unknown';
}

const navigateToProject = (projectId: string) => {
    router.push({ 
        name: 'admin-project-detail', 
        params: { id: projectId } 
    });
};

onMounted(() => {
    fetchProjects();
});
</script>

<template>
    <div class="space-y-6">
        <!-- Header / Actions -->
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-lg font-semibold text-[var(--text-primary)]">Projects</h3>
                <p class="text-sm text-[var(--text-secondary)]">Manage and track all projects for this team.</p>
            </div>
            <!-- Future: Add 'Create Project' button if needed -->
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
             <div v-for="i in 3" :key="i" class="h-48 rounded-xl bg-[var(--surface-secondary)] animate-pulse"></div>
        </div>

        <!-- Empty State -->
        <div v-else-if="projects.length === 0" class="flex flex-col items-center justify-center p-12 bg-[var(--surface-secondary)] rounded-xl border border-[var(--border-subtle)] border-dashed">
            <div class="w-12 h-12 rounded-full bg-[var(--surface-primary)] flex items-center justify-center mb-4">
                <Folder class="w-6 h-6 text-[var(--text-muted)]" />
            </div>
            <h3 class="text-base font-medium text-[var(--text-primary)]">No projects found</h3>
            <p class="text-sm text-[var(--text-secondary)] mt-1">There are no active projects for this team yet.</p>
        </div>

        <!-- Projects Grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <Card 
                v-for="project in projects" 
                :key="project.id" 
                class="group hover:border-[var(--interactive-primary)]/50 transition-all duration-200 cursor-pointer flex flex-col h-full"
                @click="navigateToProject(project.public_id || project.id)"
            >
                <div class="p-5 flex flex-col h-full">
                    <!-- Project Header -->
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <Avatar 
                                :name="project.name" 
                                variant="square" 
                                size="md" 
                                :src="project.logo"
                                class="ring-1 ring-[var(--border-subtle)] rounded-lg"
                            />
                            <div>
                                <h4 class="font-semibold text-[var(--text-primary)] line-clamp-1 group-hover:text-[var(--interactive-primary)] transition-colors">
                                    {{ project.name }}
                                </h4>
                                <div class="text-xs text-[var(--text-secondary)] flex items-center gap-1 mt-0.5" v-if="project.client">
                                    {{ project.client.name }}
                                </div>
                            </div>
                        </div>
                        <Badge :variant="getStatusColor(project.status)" size="xs" class="capitalize">
                            {{ getStatusLabel(project.status) }}
                        </Badge>
                    </div>

                    <!-- Description -->
                    <p class="text-sm text-[var(--text-secondary)] line-clamp-2 mb-6 flex-1">
                        {{ project.description || 'No description provided.' }}
                    </p>

                    <!-- Stats / Footer -->
                    <div class="mt-auto space-y-4">
                        <!-- Progress Bar (Mockup for now, assuming stats might not be in basic list) -->
                        <!-- If API returns task counts, show them. Usually project list includes meta counts -->
                        <div class="flex items-center justify-between text-xs text-[var(--text-secondary)] mb-1">
                            <span>Tasks</span>
                            <span class="font-medium text-[var(--text-primary)]">
                                {{ project.tasks_count || 0 }} total
                            </span>
                        </div>
                        
                         <!-- Meta Info -->
                        <div class="pt-4 border-t border-[var(--border-subtle)] flex items-center justify-between text-xs">
                            <div class="flex items-center gap-1.5 text-[var(--text-muted)]">
                                <Clock class="w-3.5 h-3.5" />
                                {{ formatDate(project.due_date) }}
                            </div>
                            
                            <div class="flex -space-x-2">
                                <template v-for="member in (project.members || []).slice(0, 3)" :key="member.id">
                                    <Avatar 
                                        :name="member.name" 
                                        :src="member.avatar_url" 
                                        size="xs" 
                                        class="ring-2 ring-[var(--surface-primary)]"
                                    />
                                </template>
                                <div v-if="(project.members || []).length > 3" class="w-6 h-6 rounded-full bg-[var(--surface-secondary)] ring-2 ring-[var(--surface-primary)] flex items-center justify-center text-[10px] font-medium text-[var(--text-secondary)]">
                                    +{{ (project.members?.length || 0) - 3 }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </div>
</template>
