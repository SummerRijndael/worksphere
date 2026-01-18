<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Button, Card, Badge, Input, Modal } from '@/components/ui';
import { teamRoleService } from '@/services';
import TeamRoleForm from '@/components/teams/TeamRoleForm.vue';
import RoleAssignModal from '@/components/teams/RoleAssignModal.vue';
import { toast } from 'vue-sonner';
import {
  Plus,
  Search,
  Pencil,
  Trash2,
  Users,
  Shield,
  Loader2,
  AlertTriangle,
  UserPlus,
  Lock,
  ChevronRight,
} from 'lucide-vue-next';

const route = useRoute();
const router = useRouter();

const teamId = computed(() => route.params.team);

const roles = ref([]);
const availablePermissions = ref({});
const isLoading = ref(true);
const loadError = ref(null);
const searchQuery = ref('');

const showCreateModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const showAssignModal = ref(false);

const selectedRole = ref(null);
const isSubmitting = ref(false);
const isDeleting = ref(false);

const filteredRoles = computed(() => {
  if (!searchQuery.value) return roles.value;

  const query = searchQuery.value.toLowerCase();
  return roles.value.filter(
    (role) =>
      role.name.toLowerCase().includes(query) ||
      (role.description && role.description.toLowerCase().includes(query))
  );
});

const fetchRoles = async () => {
  isLoading.value = true;
  loadError.value = null;

  try {
    const [rolesData, permsData] = await Promise.all([
      teamRoleService.fetchRoles(teamId.value),
      teamRoleService.fetchAvailablePermissions(teamId.value),
    ]);

    roles.value = Array.isArray(rolesData) ? rolesData : rolesData.data || [];
    availablePermissions.value = permsData;
  } catch (error) {
    loadError.value = error.message || 'Failed to load roles';
    toast.error('Failed to load roles');
  } finally {
    isLoading.value = false;
  }
};

const handleCreateRole = async (formData) => {
  isSubmitting.value = true;

  try {
    const newRole = await teamRoleService.createRole(teamId.value, formData);
    roles.value.push(newRole);
    showCreateModal.value = false;
    toast.success('Role created successfully');
  } catch (error) {
    toast.error(error.message || 'Failed to create role');
  } finally {
    isSubmitting.value = false;
  }
};

const handleUpdateRole = async (formData) => {
  if (!selectedRole.value) return;

  isSubmitting.value = true;

  try {
    const updatedRole = await teamRoleService.updateRole(
      teamId.value,
      selectedRole.value.public_id,
      formData
    );

    const index = roles.value.findIndex((r) => r.public_id === selectedRole.value.public_id);
    if (index !== -1) {
      roles.value[index] = updatedRole;
    }

    showEditModal.value = false;
    selectedRole.value = null;
    toast.success('Role updated successfully');
  } catch (error) {
    toast.error(error.message || 'Failed to update role');
  } finally {
    isSubmitting.value = false;
  }
};

const handleDeleteRole = async () => {
  if (!selectedRole.value) return;

  isDeleting.value = true;

  try {
    await teamRoleService.deleteRole(teamId.value, selectedRole.value.public_id);
    roles.value = roles.value.filter((r) => r.public_id !== selectedRole.value.public_id);
    showDeleteModal.value = false;
    selectedRole.value = null;
    toast.success('Role deleted successfully');
  } catch (error) {
    toast.error(error.message || 'Failed to delete role');
  } finally {
    isDeleting.value = false;
  }
};

const openEditModal = (role) => {
  selectedRole.value = role;
  showEditModal.value = true;
};

const openDeleteModal = (role) => {
  selectedRole.value = role;
  showDeleteModal.value = true;
};

const openAssignModal = (role) => {
  selectedRole.value = role;
  showAssignModal.value = true;
};

const handleRoleAssigned = () => {
  toast.success('Role assigned successfully');
  fetchRoles();
};

const getColorClass = (color) => {
  const colorMap = {
    primary: 'bg-blue-500',
    secondary: 'bg-gray-500',
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    error: 'bg-red-500',
    info: 'bg-cyan-500',
  };
  return colorMap[color] || colorMap.primary;
};

const getBadgeVariant = (color) => {
  const variantMap = {
    primary: 'primary',
    secondary: 'secondary',
    success: 'success',
    warning: 'warning',
    error: 'error',
    info: 'info',
  };
  return variantMap[color] || 'default';
};

onMounted(() => {
  fetchRoles();
});
</script>

<template>
  <div class="team-roles-page">
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Team Roles</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Manage custom roles and permissions for your team
        </p>
      </div>

      <Button @click="showCreateModal = true">
        <Plus class="h-4 w-4 mr-2" />
        Create Role
      </Button>
    </div>

    <div class="mb-6">
      <div class="relative max-w-md">
        <Search
          class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400"
        />
        <Input
          v-model="searchQuery"
          type="text"
          placeholder="Search roles..."
          class="pl-10"
        />
      </div>
    </div>

    <div v-if="isLoading" class="flex items-center justify-center py-12">
      <Loader2 class="h-8 w-8 animate-spin text-blue-500" />
    </div>

    <div
      v-else-if="loadError"
      class="text-center py-12 bg-red-50 dark:bg-red-900/20 rounded-lg"
    >
      <AlertTriangle class="h-12 w-12 mx-auto mb-3 text-red-500" />
      <p class="text-red-600 dark:text-red-400">{{ loadError }}</p>
      <Button variant="outline" class="mt-4" @click="fetchRoles">
        Try Again
      </Button>
    </div>

    <div
      v-else-if="filteredRoles.length === 0"
      class="text-center py-12 bg-gray-50 dark:bg-gray-800/50 rounded-lg"
    >
      <Shield class="h-12 w-12 mx-auto mb-3 text-gray-400" />
      <p class="text-gray-500 dark:text-gray-400">
        {{ searchQuery ? 'No roles match your search' : 'No custom roles yet' }}
      </p>
      <Button v-if="!searchQuery" class="mt-4" @click="showCreateModal = true">
        <Plus class="h-4 w-4 mr-2" />
        Create First Role
      </Button>
    </div>

    <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      <Card
        v-for="role in filteredRoles"
        :key="role.public_id"
        class="p-5 hover:shadow-lg transition-shadow"
      >
        <div class="flex items-start justify-between mb-4">
          <div class="flex items-center gap-3">
            <div :class="['w-4 h-4 rounded-full', getColorClass(role.color)]" />
            <div>
              <h3 class="font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                {{ role.name }}
                <Lock
                  v-if="role.is_system"
                  class="h-3.5 w-3.5 text-gray-400"
                  title="System role"
                />
              </h3>
              <div class="flex items-center gap-2 mt-1">
                <Badge :variant="getBadgeVariant(role.color)" size="xs">
                  Level {{ role.level }}
                </Badge>
                <Badge v-if="role.is_default" variant="success" size="xs">
                  Default
                </Badge>
              </div>
            </div>
          </div>
        </div>

        <p
          v-if="role.description"
          class="text-sm text-gray-600 dark:text-gray-400 mb-4 line-clamp-2"
        >
          {{ role.description }}
        </p>

        <div class="flex items-center gap-4 text-sm text-gray-500 dark:text-gray-400 mb-4">
          <div class="flex items-center gap-1">
            <Users class="h-4 w-4" />
            <span>{{ role.member_count }} member{{ role.member_count === 1 ? '' : 's' }}</span>
          </div>
          <div class="flex items-center gap-1">
            <Shield class="h-4 w-4" />
            <span>{{ role.permissions?.length || 0 }} permissions</span>
          </div>
        </div>

        <div class="flex items-center gap-2 pt-4 border-t border-gray-100 dark:border-gray-800">
          <Button
            variant="ghost"
            size="sm"
            @click="openAssignModal(role)"
            title="Assign to member"
          >
            <UserPlus class="h-4 w-4" />
          </Button>

          <Button
            v-if="!role.is_system"
            variant="ghost"
            size="sm"
            @click="openEditModal(role)"
            title="Edit role"
          >
            <Pencil class="h-4 w-4" />
          </Button>

          <Button
            v-if="role.can_be_deleted"
            variant="ghost"
            size="sm"
            @click="openDeleteModal(role)"
            title="Delete role"
            class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20"
          >
            <Trash2 class="h-4 w-4" />
          </Button>

          <div class="flex-1" />

          <Button
            variant="ghost"
            size="sm"
            @click="openEditModal(role)"
            :disabled="role.is_system"
          >
            View
            <ChevronRight class="h-4 w-4 ml-1" />
          </Button>
        </div>
      </Card>
    </div>

    <Modal :open="showCreateModal" @close="showCreateModal = false" size="5xl">
      <template #title>Create New Role</template>
      <TeamRoleForm
        :available-permissions="availablePermissions"
        :is-submitting="isSubmitting"
        @submit="handleCreateRole"
        @cancel="showCreateModal = false"
      />
    </Modal>

    <Modal :open="showEditModal" @close="showEditModal = false" size="5xl">
      <template #title>Edit Role</template>
      <TeamRoleForm
        :role="selectedRole"
        :available-permissions="availablePermissions"
        :is-submitting="isSubmitting"
        @submit="handleUpdateRole"
        @cancel="showEditModal = false"
      />
    </Modal>

    <Modal :open="showDeleteModal" @close="showDeleteModal = false" size="sm">
      <template #title>
        <div class="flex items-center gap-2 text-red-600">
          <AlertTriangle class="h-5 w-5" />
          Delete Role
        </div>
      </template>

      <div class="py-4">
        <p class="text-gray-600 dark:text-gray-400">
          Are you sure you want to delete the role
          <span class="font-semibold text-gray-900 dark:text-gray-100">
            "{{ selectedRole?.name }}"
          </span>?
          This action cannot be undone.
        </p>
      </div>

      <template #footer>
        <div class="flex items-center justify-end gap-3">
          <Button variant="outline" @click="showDeleteModal = false" :disabled="isDeleting">
            Cancel
          </Button>
          <Button variant="danger" @click="handleDeleteRole" :disabled="isDeleting">
            <Loader2 v-if="isDeleting" class="h-4 w-4 mr-2 animate-spin" />
            <Trash2 v-else class="h-4 w-4 mr-2" />
            Delete Role
          </Button>
        </div>
      </template>
    </Modal>

    <RoleAssignModal
      :open="showAssignModal"
      :team-id="teamId"
      :role="selectedRole"
      @close="showAssignModal = false"
      @assigned="handleRoleAssigned"
    />
  </div>
</template>
