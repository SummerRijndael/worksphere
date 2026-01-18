<script setup>
import { ref, computed } from 'vue';
import { Card, Button, Badge, Avatar, Input, Modal, Dropdown, DropdownItem, DropdownSeparator } from '@/components/ui';
import {
    Plus,
    Search,
    Grid,
    List,
    MoreVertical,
    Folder,
    Star,
    StarOff,
    Edit,
    Trash2,
    Users,
    Calendar,
} from 'lucide-vue-next';
import DemoBanner from '@/components/common/DemoBanner.vue';

const viewMode = ref('grid');
const searchQuery = ref('');
const showNewProjectModal = ref(false);

const projects = ref([
    { id: 1, name: 'Website Redesign', description: 'Complete overhaul of the company website', progress: 75, status: 'active', starred: true, members: 4, dueDate: '2024-02-15', color: 'blue' },
    { id: 2, name: 'Mobile App v2.0', description: 'New version of the mobile application', progress: 45, status: 'active', starred: true, members: 6, dueDate: '2024-03-01', color: 'purple' },
    { id: 3, name: 'API Documentation', description: 'Complete API reference documentation', progress: 90, status: 'review', starred: false, members: 2, dueDate: '2024-01-30', color: 'green' },
    { id: 4, name: 'Marketing Campaign', description: 'Q1 2024 marketing campaign', progress: 30, status: 'active', starred: false, members: 3, dueDate: '2024-02-28', color: 'orange' },
    { id: 5, name: 'Customer Portal', description: 'Self-service customer portal', progress: 10, status: 'planning', starred: false, members: 5, dueDate: '2024-04-15', color: 'pink' },
    { id: 6, name: 'Data Analytics Dashboard', description: 'Real-time analytics and reporting', progress: 60, status: 'active', starred: true, members: 4, dueDate: '2024-02-20', color: 'teal' },
]);

const filteredProjects = computed(() => {
    if (!searchQuery.value) return projects.value;
    const query = searchQuery.value.toLowerCase();
    return projects.value.filter(p =>
        p.name.toLowerCase().includes(query) ||
        p.description.toLowerCase().includes(query)
    );
});

function toggleStar(id) {
    const project = projects.value.find(p => p.id === id);
    if (project) {
        project.starred = !project.starred;
    }
}

function getStatusVariant(status) {
    switch (status) {
        case 'active': return 'primary';
        case 'review': return 'warning';
        case 'planning': return 'secondary';
        default: return 'default';
    }
}

function getColorClass(color) {
    const colors = {
        blue: 'bg-blue-500',
        purple: 'bg-purple-500',
        green: 'bg-green-500',
        orange: 'bg-orange-500',
        pink: 'bg-pink-500',
        teal: 'bg-teal-500',
    };
    return colors[color] || 'bg-gray-500';
}
</script>

<template>
    <div class="space-y-6">
        <DemoBanner />
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">Projects</h1>
                <p class="text-[var(--text-secondary)] mt-1">
                    Manage and track your team's projects.
                </p>
            </div>
            <Button @click="showNewProjectModal = true">
                <Plus class="h-4 w-4" />
                New Project
            </Button>
        </div>

        <!-- Toolbar -->
        <div class="flex flex-col sm:flex-row gap-4 sm:items-center sm:justify-between">
            <div class="relative flex-1 max-w-sm">
                <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]" />
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search projects..."
                    class="h-10 w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] pl-10 pr-4 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 transition-all"
                />
            </div>

            <div class="flex gap-1 p-1 rounded-lg bg-[var(--surface-secondary)]">
                <Button
                    :variant="viewMode === 'grid' ? 'secondary' : 'ghost'"
                    size="icon"
                    class="h-8 w-8"
                    @click="viewMode = 'grid'"
                >
                    <Grid class="h-4 w-4" />
                </Button>
                <Button
                    :variant="viewMode === 'list' ? 'secondary' : 'ghost'"
                    size="icon"
                    class="h-8 w-8"
                    @click="viewMode = 'list'"
                >
                    <List class="h-4 w-4" />
                </Button>
            </div>
        </div>

        <!-- Grid View -->
        <div v-if="viewMode === 'grid'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Card
                v-for="project in filteredProjects"
                :key="project.id"
                padding="none"
                hover
                clickable
            >
                <div class="p-5">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-3">
                            <div :class="['h-10 w-10 rounded-lg flex items-center justify-center', getColorClass(project.color)]">
                                <Folder class="h-5 w-5 text-white" />
                            </div>
                            <div>
                                <h3 class="font-semibold text-[var(--text-primary)]">{{ project.name }}</h3>
                                <Badge :variant="getStatusVariant(project.status)" size="sm" class="mt-1">
                                    {{ project.status }}
                                </Badge>
                            </div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button
                                class="p-1.5 rounded-lg text-[var(--text-muted)] hover:text-yellow-500 transition-colors"
                                @click.stop="toggleStar(project.id)"
                            >
                                <Star v-if="project.starred" class="h-4 w-4 fill-yellow-500 text-yellow-500" />
                                <Star v-else class="h-4 w-4" />
                            </button>
                            <Dropdown align="end">
                                <template #trigger>
                                    <button class="p-1.5 rounded-lg text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors">
                                        <MoreVertical class="h-4 w-4" />
                                    </button>
                                </template>
                                <DropdownItem>
                                    <Edit class="h-4 w-4" />
                                    Edit
                                </DropdownItem>
                                <DropdownSeparator />
                                <DropdownItem destructive>
                                    <Trash2 class="h-4 w-4" />
                                    Delete
                                </DropdownItem>
                            </Dropdown>
                        </div>
                    </div>

                    <p class="text-sm text-[var(--text-secondary)] mt-3 line-clamp-2">
                        {{ project.description }}
                    </p>

                    <div class="mt-4">
                        <div class="flex items-center justify-between text-sm mb-1.5">
                            <span class="text-[var(--text-muted)]">Progress</span>
                            <span class="font-medium text-[var(--text-primary)]">{{ project.progress }}%</span>
                        </div>
                        <div class="h-1.5 rounded-full bg-[var(--surface-tertiary)] overflow-hidden">
                            <div
                                class="h-full rounded-full bg-[var(--interactive-primary)] transition-all duration-500"
                                :style="{ width: `${project.progress}%` }"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between px-5 py-3 border-t border-[var(--border-default)] bg-[var(--surface-secondary)]">
                    <div class="flex items-center gap-1.5 text-sm text-[var(--text-muted)]">
                        <Users class="h-4 w-4" />
                        {{ project.members }}
                    </div>
                    <div class="flex items-center gap-1.5 text-sm text-[var(--text-muted)]">
                        <Calendar class="h-4 w-4" />
                        {{ project.dueDate }}
                    </div>
                </div>
            </Card>
        </div>

        <!-- List View -->
        <Card v-else padding="none">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-[var(--border-default)]">
                            <th class="px-5 py-3 text-left text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">Project</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">Status</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">Progress</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">Team</th>
                            <th class="px-5 py-3 text-left text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">Due Date</th>
                            <th class="px-5 py-3 text-right text-xs font-medium text-[var(--text-muted)] uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-muted)]">
                        <tr v-for="project in filteredProjects" :key="project.id" class="hover:bg-[var(--surface-secondary)] transition-colors">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <div :class="['h-8 w-8 rounded-lg flex items-center justify-center', getColorClass(project.color)]">
                                        <Folder class="h-4 w-4 text-white" />
                                    </div>
                                    <div>
                                        <p class="font-medium text-[var(--text-primary)]">{{ project.name }}</p>
                                        <p class="text-xs text-[var(--text-muted)]">{{ project.description }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <Badge :variant="getStatusVariant(project.status)" size="sm">
                                    {{ project.status }}
                                </Badge>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-20 h-1.5 rounded-full bg-[var(--surface-tertiary)] overflow-hidden">
                                        <div
                                            class="h-full rounded-full bg-[var(--interactive-primary)]"
                                            :style="{ width: `${project.progress}%` }"
                                        />
                                    </div>
                                    <span class="text-sm text-[var(--text-secondary)]">{{ project.progress }}%</span>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <span class="text-sm text-[var(--text-secondary)]">{{ project.members }} members</span>
                            </td>
                            <td class="px-5 py-4">
                                <span class="text-sm text-[var(--text-secondary)]">{{ project.dueDate }}</span>
                            </td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button
                                        class="p-1.5 rounded-lg text-[var(--text-muted)] hover:text-yellow-500 transition-colors"
                                        @click="toggleStar(project.id)"
                                    >
                                        <Star v-if="project.starred" class="h-4 w-4 fill-yellow-500 text-yellow-500" />
                                        <Star v-else class="h-4 w-4" />
                                    </button>
                                    <Dropdown align="end">
                                        <template #trigger>
                                            <button class="p-1.5 rounded-lg text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors">
                                                <MoreVertical class="h-4 w-4" />
                                            </button>
                                        </template>
                                        <DropdownItem>
                                            <Edit class="h-4 w-4" />
                                            Edit
                                        </DropdownItem>
                                        <DropdownSeparator />
                                        <DropdownItem destructive>
                                            <Trash2 class="h-4 w-4" />
                                            Delete
                                        </DropdownItem>
                                    </Dropdown>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <!-- New Project Modal -->
        <Modal
            v-model:open="showNewProjectModal"
            title="Create New Project"
            description="Add a new project to your workspace"
            size="md"
        >
            <div class="space-y-4">
                <Input
                    label="Project Name"
                    placeholder="Enter project name"
                />
                <div>
                    <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Description</label>
                    <textarea
                        rows="3"
                        class="w-full rounded-lg border border-[var(--border-default)] bg-[var(--surface-elevated)] px-3.5 py-2.5 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-muted)] focus:border-[var(--interactive-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 transition-all resize-none"
                        placeholder="Describe your project"
                    />
                </div>
                <Input
                    type="date"
                    label="Due Date"
                />
            </div>

            <template #footer>
                <Button variant="outline" @click="showNewProjectModal = false">Cancel</Button>
                <Button @click="showNewProjectModal = false">Create Project</Button>
            </template>
        </Modal>
    </div>
</template>
