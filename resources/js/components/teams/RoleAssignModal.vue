<script setup>
import { ref, computed, watch } from 'vue';
import { Modal, Button, Input, Avatar, Badge } from '@/components/ui';
import { Search, Loader2, UserCheck, Users } from 'lucide-vue-next';
import api from '@/lib/api';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  teamId: {
    type: String,
    required: true,
  },
  role: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'assigned']);

const isLoading = ref(false);
const isAssigning = ref(false);
const members = ref([]);
const searchQuery = ref('');
const selectedMemberId = ref(null);

const filteredMembers = computed(() => {
  if (!searchQuery.value) return members.value;

  const query = searchQuery.value.toLowerCase();
  return members.value.filter(
    (member) =>
      member.name.toLowerCase().includes(query) ||
      member.email.toLowerCase().includes(query)
  );
});

const fetchMembers = async () => {
  isLoading.value = true;
  try {
    const response = await api.get(`/api/teams/${props.teamId}/members`, {
      params: { per_page: 100 },
    });
    members.value = response.data.data || response.data || [];
  } catch (error) {
    console.error('Failed to fetch team members:', error);
    members.value = [];
  } finally {
    isLoading.value = false;
  }
};

const assignRole = async () => {
  if (!selectedMemberId.value || !props.role) return;

  isAssigning.value = true;
  try {
    await api.post(
      `/api/teams/${props.teamId}/roles/${props.role.id}/assign/${selectedMemberId.value}`
    );
    emit('assigned', { memberId: selectedMemberId.value, role: props.role });
    handleClose();
  } catch (error) {
    console.error('Failed to assign role:', error);
  } finally {
    isAssigning.value = false;
  }
};

const handleClose = () => {
  selectedMemberId.value = null;
  searchQuery.value = '';
  emit('close');
};

const getMemberCurrentRole = (member) => {
  return member.pivot?.role || 'member';
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

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      fetchMembers();
    }
  }
);
</script>

<template>
  <Modal :open="open" @close="handleClose" size="lg">
    <template #title>
      <div class="flex items-center gap-3">
        <div :class="['w-3 h-3 rounded-full', getColorClass(role?.color)]" />
        <span>Assign "{{ role?.name }}" Role</span>
      </div>
    </template>

    <div class="py-4">
      <div class="mb-4">
        <div class="relative">
          <Search
            class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400"
          />
          <Input
            v-model="searchQuery"
            type="text"
            placeholder="Search members..."
            class="pl-10"
          />
        </div>
      </div>

      <div v-if="isLoading" class="flex items-center justify-center py-12">
        <Loader2 class="h-8 w-8 animate-spin text-blue-500" />
      </div>

      <div
        v-else-if="filteredMembers.length === 0"
        class="text-center py-12 text-gray-500 dark:text-gray-400"
      >
        <Users class="h-12 w-12 mx-auto mb-3 opacity-50" />
        <p>No members found</p>
      </div>

      <div v-else class="space-y-2 max-h-96 overflow-y-auto">
        <button
          v-for="member in filteredMembers"
          :key="member.public_id || member.id"
          type="button"
          @click="selectedMemberId = member.public_id || member.id"
          :class="[
            'w-full flex items-center gap-4 p-3 rounded-lg border transition-all text-left',
            selectedMemberId === (member.public_id || member.id)
              ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20 ring-2 ring-blue-500/20'
              : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-800/50',
          ]"
        >
          <Avatar :src="member.avatar_url" :name="member.name" size="md" />

          <div class="flex-1 min-w-0">
            <div class="font-medium text-gray-900 dark:text-gray-100 truncate">
              {{ member.name }}
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400 truncate">
              {{ member.email }}
            </div>
          </div>

          <Badge variant="secondary" size="sm">
            {{ getMemberCurrentRole(member) }}
          </Badge>

          <div
            v-if="selectedMemberId === (member.public_id || member.id)"
            class="shrink-0"
          >
            <UserCheck class="h-5 w-5 text-blue-500" />
          </div>
        </button>
      </div>
    </div>

    <template #footer>
      <div class="flex items-center justify-end gap-3">
        <Button variant="outline" @click="handleClose" :disabled="isAssigning">
          Cancel
        </Button>
        <Button
          @click="assignRole"
          :disabled="!selectedMemberId || isAssigning"
        >
          <Loader2 v-if="isAssigning" class="h-4 w-4 mr-2 animate-spin" />
          <UserCheck v-else class="h-4 w-4 mr-2" />
          Assign Role
        </Button>
      </div>
    </template>
  </Modal>
</template>
