<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { Card, Button, Badge, PageLoader, Input, SelectFilter, Avatar, Dropdown } from '@/components/ui';
import { 
    Folder, Search, Plus, ChevronLeft, ChevronRight, MoreHorizontal, 
    LayoutGrid, LayoutList, Archive, Trash2, Calendar, Clock,
    Filter, RefreshCw, Eye, Edit
} from 'lucide-vue-next';
import axios from 'axios';
import { useAuthStore } from '@/stores/auth';
import { toast } from 'vue-sonner';
import ProjectFormModal from '@/components/projects/ProjectFormModal.vue';

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

const isLoading = ref(true);
const isRefreshing = ref(false);
const projects = ref<any[]>([]);

const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});

const filters = ref({
    status: '',
    search: '',
    archived: false,
    sort_by: 'created_at',
    sort_order: 'desc',
    project_id: '',
});

const viewMode = ref<'list' | 'grid'>('grid');

const currentTeamId = computed(() => authStore.currentTeam?.public_id);

const showCreateModal = ref(false);
const selectedProject = ref<any | null>(null);

const teamOptions = computed(() => {
    return authStore.user?.teams?.map(t => ({
        value: t.public_id,
        label: t.name
    })) || [];
});

const allProjects = ref<any[]>([]);
const projectOptions = computed(() => {
    return allProjects.value.map(p => ({
        value: p.public_id,
        label: p.name
    }));
});

const selectedTeamId = computed({
    get: () => authStore.currentTeam?.public_id || '',
    set: (val: string) => {
        if (val) authStore.switchTeam(val);
    }
});

const statusOptions = [
    { value: '', label: 'All Statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On Hold' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
];

const formatDate = (dateString: string) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};



const getStatusVariant = (statusData: any) => {
    const status = statusData?.value || statusData;
    const variants: Record<string, string> = {
        draft: 'secondary',
        active: 'primary',
        on_hold: 'warning',
        completed: 'success',
        cancelled: 'error',
        archived: 'secondary',
    };
    return variants[status] || 'secondary';
};



const getPriorityColor = (priorityData: any) => {
    const priority = priorityData?.value || priorityData;
    const colors: Record<string, string> = {
        low: 'text-gray-500',
        medium: 'text-blue-500',
        high: 'text-orange-500',
        urgent: 'text-red-500',
    };
    return colors[priority] || 'text-gray-500';
};

const fetchProjects = async (page = 1) => {
    isLoading.value = true;
    
    if (!currentTeamId.value) {
        isLoading.value = false;
        return;
    }
    
    try {
        const params: Record<string, any> = {
            page,
            per_page: pagination.value.per_page,
            sort_by: filters.value.sort_by,
            sort_order: filters.value.sort_order,
            include_archived: true,
        };

        if (filters.value.status) params.status = filters.value.status;
        if (filters.value.search) params.search = filters.value.search;
        if (filters.value.archived) params.archived = true;
        if (filters.value.project_id) params.public_id = filters.value.project_id;

        const response = await axios.get(`/api/teams/${currentTeamId.value}/projects`, { params });
        console.log('Projects API Response:', response.data); // DEBUG
        
        projects.value = response.data.data || [];
        
        if (!Array.isArray(projects.value)) {
            console.error('Projects data is not an array:', projects.value);
            projects.value = [];
        }

        pagination.value = {
            current_page: response.data.meta?.current_page || 1,
            last_page: response.data.meta?.last_page || 1,
            per_page: response.data.meta?.per_page || 15,
            total: response.data.meta?.total || 0,
        };
    } catch (err) {
        console.error('Failed to fetch projects', err);
        toast.error('Failed to load projects');
        projects.value = [];
    } finally {
        isLoading.value = false;
        console.log('Fetch Projects completed. isLoading:', isLoading.value, 'Projects count:', projects.value.length);
    }
};

const fetchAllProjects = async () => {
    if (!currentTeamId.value) return;
    try {
        // Fetch a larger list for the dropdown, maybe optimize fields later
        const response = await axios.get(`/api/teams/${currentTeamId.value}/projects`, {
            params: { per_page: 100, sort_by: 'name', sort_order: 'asc' }
        });
        allProjects.value = response.data.data || [];
    } catch (err) {
        console.error('Failed to fetch project options', err);
    }
};

const refreshData = async () => {
    isRefreshing.value = true;
    await fetchProjects(pagination.value.current_page);
    isRefreshing.value = false;
};

const changePage = (page: number) => {
    if (page >= 1 && page <= pagination.value.last_page) {
        fetchProjects(page);
    }
};

const viewProject = (project: any) => {
    router.push(`/admin/projects/${project.public_id}`);
};

const onCreateProject = () => {
    selectedProject.value = null;
    showCreateModal.value = true;
};

const onEditProject = (project: any) => {
    selectedProject.value = project;
    showCreateModal.value = true;
};

const onProjectSaved = () => {
    fetchProjects();
    showCreateModal.value = false;
};

const toggleArchive = async (project: any) => {
    if (!currentTeamId.value) return;
    
    const action = project.status === 'archived' ? 'unarchive' : 'archive';
    if (!confirm(`Are you sure you want to ${action} this project?`)) return;
    
    try {
        await axios.post(`/api/teams/${currentTeamId.value}/projects/${project.public_id}/${action}`);
        toast.success(`Project ${action}d successfully`);
        refreshData();
    } catch (err: any) {
        toast.error(err.response?.data?.message || `Failed to ${action} project`);
    }
};

const deleteProject = async (project: any) => {
    if (!currentTeamId.value) return;
    if (!confirm('Are you sure you want to delete this project? This action cannot be undone.')) return;
    
    try {
        await axios.delete(`/api/teams/${currentTeamId.value}/projects/${project.public_id}`);
        toast.success('Project deleted');
        refreshData();
    } catch (err: any) {
        toast.error(err.response?.data?.message || 'Failed to delete project');
    }
};

const getProjectActions = (project: any) => {
    const actions = [
        { label: 'View Details', icon: Eye, action: () => viewProject(project) },
        { label: 'Edit Project', icon: Edit, action: () => onEditProject(project) },
    ];
    
    // Add logic for archive/unarchive visibility
    if (project.status === 'archived') {
        actions.push({ label: 'Unarchive', icon: Archive, action: () => toggleArchive(project) });
    } else {
        actions.push({ label: 'Archive', icon: Archive, action: () => toggleArchive(project) });
    }
    
    actions.push({ label: 'Delete', icon: Trash2, action: () => deleteProject(project), variant: 'danger' });
    
    return actions;
};

// Debounced search
let searchTimeout: ReturnType<typeof setTimeout>;
const onSearchChange = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchProjects(1);
    }, 300);
};

watch(() => filters.value.status, () => fetchProjects(1));
watch(() => filters.value.archived, () => fetchProjects(1));
watch(() => filters.value.project_id, () => fetchProjects(1));
watch(() => currentTeamId.value, (newTeam, oldTeam) => {
    console.log('Team changed from', oldTeam, 'to', newTeam);
    if (newTeam) {
        // If we have a project filter active, clearing it will trigger the project_id watcher,
        // which calls fetchProjects. We don't want to call it twice.
        const willTriggerWatcher = !!filters.value.project_id;
        filters.value.project_id = ''; 
        
        if (!willTriggerWatcher) {
            fetchProjects();
        }
        
        fetchAllProjects();
    }
}, { immediate: true });

onMounted(() => {
    console.log('ProjectsManageView mounted');
    console.log('Initial ViewMode:', viewMode.value);
    console.log('Current Team ID:', currentTeamId.value);
    // Data fetching is handled by the immediate watcher on currentTeamId

    // Check for create=true query param to open modal from nav
    if (route.query.create === 'true') {
        showCreateModal.value = true;
        // Clear the query param from URL without navigation
        router.replace({ path: route.path, query: {} });
    }
});
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">Projects</h1>
                <p class="text-[var(--text-secondary)]">Manage your team's projects</p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" :loading="isRefreshing" @click="refreshData">
                    <RefreshCw class="w-4 h-4" />
                </Button>
                <div v-if="teamOptions.length > 0" class="w-48">
                    <SelectFilter
                        v-model="selectedTeamId"
                        :options="teamOptions"
                        placeholder="Select Team"
                    />
                </div>
                <Button @click="onCreateProject">
                    <Plus class="w-4 h-4 mr-2" />
                    New Project
                </Button>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between">
            <div class="flex flex-col sm:flex-row gap-4 flex-1">
                <div class="w-full sm:w-64">
                    <Input
                        v-model="filters.search"
                        placeholder="Search projects..."
                        @input="onSearchChange"
                    >
                        <template #prefix>
                            <Search class="w-4 h-4 text-[var(--text-muted)]" />
                        </template>
                    </Input>
                </div>
                <SelectFilter
                    v-model="filters.status"
                    :options="statusOptions"
                    placeholder="Filter by status"
                    class="w-full sm:w-44"
                />
                <SelectFilter
                    v-model="filters.project_id"
                    :options="projectOptions"
                    placeholder="Filter by Project"
                    class="w-full sm:w-48"
                    searchable
                />
                <Button 
                    variant="outline" 
                    :class="{ 'bg-[var(--surface-tertiary)]': filters.archived }"
                    @click="filters.archived = !filters.archived"
                >
                    <Archive class="w-4 h-4 mr-2" />
                    {{ filters.archived ? 'Show Active' : 'Show Archived' }}
                </Button>
            </div>
            <!-- View Toggle -->
            <div class="flex items-center gap-2 bg-[var(--surface-secondary)] p-1 rounded-lg border border-[var(--border-default)]">
                <button
                    class="p-1.5 rounded-md transition-all"
                    :class="viewMode === 'list' ? 'bg-[var(--surface-elevated)] text-[var(--interactive-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'"
                    @click="viewMode = 'list'"
                >
                    <LayoutList class="w-4 h-4" />
                </button>
                <button
                    class="p-1.5 rounded-md transition-all"
                    :class="viewMode === 'grid' ? 'bg-[var(--surface-elevated)] text-[var(--interactive-primary)] shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'"
                    @click="viewMode = 'grid'"
                >
                    <LayoutGrid class="w-4 h-4" />
                </button>
            </div>
        </div>

        <!-- Loading -->
        <PageLoader v-if="isLoading" />

        <!-- Empty State -->
        <Card v-else-if="projects.length === 0" padding="lg" class="text-center">
            <Folder class="w-12 h-12 mx-auto text-[var(--text-muted)] mb-4" />
            <p class="text-[var(--text-primary)] font-medium">No projects found</p>
            <p class="text-sm text-[var(--text-muted)] mt-1 mb-4">
                {{ filters.status || filters.search ? 'Try adjusting your filters' : 'Create your first project to get started' }}
            </p>
            <Button v-if="!filters.status && !filters.search" @click="onCreateProject">
                <Plus class="w-4 h-4 mr-2" />
                Create Project
            </Button>
        </Card>

        <!-- Project List -->
        <template v-else>
            <!-- Grid View -->
            <TransitionGroup 
                v-if="viewMode === 'grid'" 
                tag="div" 
                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 relative"
                name="list"
            >
                <Card 
                    v-for="project in projects" 
                    :key="project.public_id" 
                    class="group hover:shadow-lg transition-all cursor-pointer overflow-hidden border-t-4"
                    :style="{ borderTopColor: getPriorityColor(project.priority).split('-')[1] }"
                    padding="none"
                    @click="viewProject(project)"
                >
                    <div class="p-6 space-y-4">
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-lg bg-[var(--surface-secondary)] flex items-center justify-center">
                                    <Folder class="w-5 h-5 text-[var(--text-primary)]" />
                                </div>
                                <div>
                                    <h3 class="font-semibold text-[var(--text-primary)] group-hover:text-[var(--interactive-primary)] transition-colors">
                                        {{ project.name }}
                                    </h3>
                                    <p class="text-xs text-[var(--text-secondary)]">{{ project.client?.name || 'Internal Project' }}</p>
                                </div>
                            </div>
                            <Dropdown :items="getProjectActions(project)" align="end">
                                <Button variant="ghost" size="sm" @click.stop>
                                    <MoreHorizontal class="w-4 h-4" />
                                </Button>
                            </Dropdown>
                        </div>
                        
                        <p class="text-sm text-[var(--text-secondary)] line-clamp-2 min-h-[40px]">
                            {{ project.description || 'No description provided.' }}
                        </p>

                        <!-- Progress -->
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs text-[var(--text-muted)]">
                                <span>Progress</span>
                                <span>{{ project.progress_percentage }}%</span>
                            </div>
                            <div class="h-1.5 w-full bg-[var(--surface-secondary)] rounded-full overflow-hidden">
                                <div 
                                    class="h-full bg-[var(--color-primary-600)] rounded-full transition-all duration-500"
                                    :style="{ width: `${project.progress_percentage}%` }"
                                ></div>
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-between pt-4 border-t border-[var(--border-muted)]">
                            <div class="flex -space-x-2">
                                <Avatar 
                                    v-for="member in (project.members || []).slice(0, 3)"
                                    :key="member.id"
                                    :name="member.name"
                                    :src="member.avatar_url"
                                    size="sm"
                                    class="ring-2 ring-[var(--surface-primary)]"
                                />
                                <div v-if="project.members_count > 3" class="w-8 h-8 rounded-full bg-[var(--surface-secondary)] ring-2 ring-[var(--surface-primary)] flex items-center justify-center text-xs font-medium text-[var(--text-muted)]">
                                    +{{ project.members_count - 3 }}
                                </div>
                            </div>
                            <Badge :variant="getStatusVariant(project.status)" size="sm">
                                {{ project.status?.label || project.status_label || project.status }}
                            </Badge>
                        </div>
                    </div>
                </Card>
            </TransitionGroup>

            <!-- List View -->
            <Card v-else padding="none" class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-[var(--surface-secondary)]">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-[var(--text-muted)]">Project</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-[var(--text-muted)]">Client</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-[var(--text-muted)]">Members</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-[var(--text-muted)]">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-[var(--text-muted)]">Due Date</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-[var(--text-muted)]">Progress</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-[var(--text-muted)]">Actions</th>
                            </tr>
                        </thead>
                        <TransitionGroup tag="tbody" name="list" class="divide-y divide-[var(--border-default)] relative">
                            <tr 
                                v-for="project in projects" 
                                :key="project.public_id"
                                class="hover:bg-[var(--surface-secondary)] transition-colors cursor-pointer"
                                @click="viewProject(project)"
                            >
                                <td class="px-4 py-4">
                                    <div class="font-medium text-[var(--text-primary)]">{{ project.name }}</div>
                                </td>
                                <td class="px-4 py-4">
                                    <div v-if="project.client" class="flex items-center gap-2">
                                        <Avatar :name="project.client.name" :src="project.client.avatar_url" size="xs" />
                                        <span class="text-sm text-[var(--text-secondary)]">{{ project.client.name }}</span>
                                    </div>
                                    <span v-else class="text-sm text-[var(--text-muted)]">-</span>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex -space-x-2">
                                        <Avatar 
                                            v-for="member in (project.members || []).slice(0, 3)"
                                            :key="member.id"
                                            :name="member.name"
                                            :src="member.avatar_url"
                                            size="xs"
                                            class="ring-2 ring-[var(--surface-primary)]"
                                        />
                                        <div v-if="project.members_count > 3" class="w-6 h-6 rounded-full bg-[var(--surface-secondary)] ring-2 ring-[var(--surface-primary)] flex items-center justify-center text-[10px] font-medium text-[var(--text-muted)]">
                                            +{{ project.members_count - 3 }}
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <Badge :variant="getStatusVariant(project.status)" size="sm">
                                        {{ project.status?.label || project.status_label || project.status }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-1 text-sm text-[var(--text-muted)]">
                                        <Calendar class="w-3 h-3" />
                                        {{ formatDate(project.due_date) }}
                                    </div>
                                </td>
                                <td class="px-4 py-4 w-32">
                                    <div class="space-y-1">
                                        <div class="flex justify-between text-[10px] text-[var(--text-muted)]">
                                            <span>{{ project.progress_percentage }}%</span>
                                        </div>
                                        <div class="h-1.5 w-full bg-[var(--surface-secondary)] rounded-full overflow-hidden">
                                            <div 
                                                class="h-full bg-[var(--color-primary-600)] rounded-full"
                                                :style="{ width: `${project.progress_percentage}%` }"
                                            ></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-4 text-right" @click.stop>
                            <Dropdown :items="getProjectActions(project)" align="end">
                                        <Button variant="ghost" size="sm">
                                            <MoreHorizontal class="w-4 h-4" />
                                        </Button>
                                    </Dropdown>
                                </td>
                            </tr>
                        </TransitionGroup>
                    </table>
                </div>
            </Card>

            <!-- Pagination -->
            <div v-if="pagination.last_page > 1" class="flex items-center justify-between">
                <p class="text-sm text-[var(--text-muted)]">
                    Showing {{ (pagination.current_page - 1) * pagination.per_page + 1 }} to 
                    {{ Math.min(pagination.current_page * pagination.per_page, pagination.total) }} of 
                    {{ pagination.total }} projects
                </p>
                <div class="flex items-center gap-2">
                    <Button 
                        variant="outline" 
                        size="sm" 
                        :disabled="pagination.current_page === 1"
                        @click="changePage(pagination.current_page - 1)"
                    >
                        <ChevronLeft class="w-4 h-4" />
                    </Button>
                    <span class="text-sm text-[var(--text-secondary)]">
                        Page {{ pagination.current_page }} of {{ pagination.last_page }}
                    </span>
                    <Button 
                        variant="outline" 
                        size="sm" 
                        :disabled="pagination.current_page === pagination.last_page"
                        @click="changePage(pagination.current_page + 1)"
                    >
                        <ChevronRight class="w-4 h-4" />
                    </Button>
                </div>
            </div>
        </template>
        <ProjectFormModal
            v-if="showCreateModal"
            :open="showCreateModal"
            :project="selectedProject"
            @update:open="showCreateModal = $event"
            @saved="onProjectSaved"
        />
    </div>
</template>

<style scoped>
.list-enter-active,
.list-leave-active {
    transition: all 0.3s ease;
}
.list-enter-from,
.list-leave-to {
    opacity: 0;
    transform: translateY(10px);
}
.list-move {
    transition: transform 0.3s ease;
}
.list-leave-active {
    position: absolute;
}
</style>
