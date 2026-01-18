<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { Button, Input, Card } from '@/components/ui';
import PermissionPicker from './PermissionPicker.vue';
import { Loader2, Save, X } from 'lucide-vue-next';

const props = defineProps({
  role: {
    type: Object,
    default: null,
  },
  availablePermissions: {
    type: Object,
    default: () => ({}),
  },
  isLoading: {
    type: Boolean,
    default: false,
  },
  isSubmitting: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['submit', 'cancel']);

const formData = ref({
  name: '',
  description: '',
  color: 'primary',
  level: 50,
  is_default: false,
  permissions: [],
});

const colorOptions = [
  { value: 'primary', label: 'Blue', class: 'bg-blue-500' },
  { value: 'secondary', label: 'Gray', class: 'bg-gray-500' },
  { value: 'success', label: 'Green', class: 'bg-emerald-500' },
  { value: 'warning', label: 'Orange', class: 'bg-amber-500' },
  { value: 'error', label: 'Red', class: 'bg-red-500' },
  { value: 'info', label: 'Cyan', class: 'bg-cyan-500' },
];

const isEditMode = computed(() => !!props.role);

const formTitle = computed(() => (isEditMode.value ? 'Edit Role' : 'Create Role'));

const initializeForm = () => {
  if (props.role) {
    formData.value = {
      name: props.role.name || '',
      description: props.role.description || '',
      color: props.role.color || 'primary',
      level: props.role.level || 50,
      is_default: props.role.is_default || false,
      permissions: props.role.permissions || [],
    };
  } else {
    formData.value = {
      name: '',
      description: '',
      color: 'primary',
      level: 50,
      is_default: false,
      permissions: [],
    };
  }
};

const handleSubmit = () => {
  emit('submit', { ...formData.value });
};

const handleCancel = () => {
  emit('cancel');
};

watch(
  () => props.role,
  () => {
    initializeForm();
  },
  { immediate: true }
);

onMounted(() => {
  initializeForm();
});
</script>

<template>
  <div class="team-role-form">
    <div v-if="isLoading" class="flex items-center justify-center py-12">
      <Loader2 class="h-8 w-8 animate-spin text-blue-500" />
    </div>

    <form v-else @submit.prevent="handleSubmit" class="space-y-6">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card class="p-6">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
            Basic Information
          </h3>

          <div class="space-y-4">
            <div>
              <label
                for="name"
                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
              >
                Role Name <span class="text-red-500">*</span>
              </label>
              <Input
                id="name"
                v-model="formData.name"
                type="text"
                placeholder="e.g., Project Lead"
                required
                :disabled="isSubmitting"
              />
            </div>

            <div>
              <label
                for="description"
                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
              >
                Description
              </label>
              <textarea
                id="description"
                v-model="formData.description"
                placeholder="Describe what this role can do..."
                rows="3"
                :disabled="isSubmitting"
                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-4 py-2 text-gray-900 dark:text-gray-100 placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none transition-colors disabled:opacity-50"
              ></textarea>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Color
              </label>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="color in colorOptions"
                  :key="color.value"
                  type="button"
                  @click="formData.color = color.value"
                  :class="[
                    'w-8 h-8 rounded-full transition-all',
                    color.class,
                    formData.color === color.value
                      ? 'ring-2 ring-offset-2 ring-offset-white dark:ring-offset-gray-900 ring-blue-500 scale-110'
                      : 'hover:scale-105',
                  ]"
                  :title="color.label"
                  :disabled="isSubmitting"
                />
              </div>
            </div>

            <div>
              <label
                for="level"
                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1"
              >
                Role Level (1-99)
              </label>
              <div class="flex items-center gap-4">
                <input
                  id="level"
                  v-model.number="formData.level"
                  type="range"
                  min="1"
                  max="99"
                  :disabled="isSubmitting"
                  class="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-lg appearance-none cursor-pointer accent-blue-500"
                />
                <span
                  class="w-12 text-center font-mono text-sm text-gray-900 dark:text-gray-100 bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded"
                >
                  {{ formData.level }}
                </span>
              </div>
              <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Higher level roles can manage lower level roles
              </p>
            </div>

            <div class="flex items-center gap-3">
              <input
                id="is_default"
                v-model="formData.is_default"
                type="checkbox"
                :disabled="isSubmitting"
                class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500 dark:bg-gray-800"
              />
              <label
                for="is_default"
                class="text-sm font-medium text-gray-700 dark:text-gray-300"
              >
                Set as default role for new team members
              </label>
            </div>
          </div>
        </Card>

        <Card class="p-6">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
            Permissions
          </h3>

          <PermissionPicker
            v-model="formData.permissions"
            :available-permissions="availablePermissions"
            :disabled="isSubmitting"
          />
        </Card>
      </div>

      <div
        class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200 dark:border-gray-700"
      >
        <Button type="button" variant="outline" @click="handleCancel" :disabled="isSubmitting">
          <X class="h-4 w-4 mr-2" />
          Cancel
        </Button>
        <Button type="submit" :disabled="isSubmitting || !formData.name">
          <Loader2 v-if="isSubmitting" class="h-4 w-4 mr-2 animate-spin" />
          <Save v-else class="h-4 w-4 mr-2" />
          {{ isEditMode ? 'Update Role' : 'Create Role' }}
        </Button>
      </div>
    </form>
  </div>
</template>
