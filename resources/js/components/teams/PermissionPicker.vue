<script setup>
import { ref, computed, watch } from 'vue';
import { cn } from '@/lib/utils';
import { Input, Checkbox } from '@/components/ui';
import { ChevronDown, ChevronRight, Search, Check } from 'lucide-vue-next';

const props = defineProps({
  availablePermissions: {
    type: Object,
    default: () => ({}),
  },
  modelValue: {
    type: Array,
    default: () => [],
  },
  disabled: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['update:modelValue']);

const searchQuery = ref('');
const expandedGroups = ref([]);

const selectedPermissions = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
});

const filteredPermissions = computed(() => {
  const query = searchQuery.value.toLowerCase();
  if (!query) return props.availablePermissions;

  const filtered = {};
  for (const [module, permissions] of Object.entries(props.availablePermissions)) {
    const matchingPerms = permissions.filter(
      (p) =>
        p.key.toLowerCase().includes(query) ||
        p.label.toLowerCase().includes(query) ||
        module.toLowerCase().includes(query)
    );
    if (matchingPerms.length > 0) {
      filtered[module] = matchingPerms;
    }
  }
  return filtered;
});

const toggleGroup = (module) => {
  const index = expandedGroups.value.indexOf(module);
  if (index === -1) {
    expandedGroups.value.push(module);
  } else {
    expandedGroups.value.splice(index, 1);
  }
};

const isGroupExpanded = (module) => expandedGroups.value.includes(module);

const togglePermission = (permissionKey) => {
  if (props.disabled) return;

  const index = selectedPermissions.value.indexOf(permissionKey);
  const newValue = [...selectedPermissions.value];

  if (index === -1) {
    newValue.push(permissionKey);
  } else {
    newValue.splice(index, 1);
  }

  selectedPermissions.value = newValue;
};

const isPermissionSelected = (permissionKey) => {
  return selectedPermissions.value.includes(permissionKey);
};

const toggleAllInGroup = (module) => {
  if (props.disabled) return;

  const permissions = props.availablePermissions[module] || [];
  const permissionKeys = permissions.map((p) => p.key);
  const allSelected = permissionKeys.every((key) => selectedPermissions.value.includes(key));

  let newValue = [...selectedPermissions.value];

  if (allSelected) {
    newValue = newValue.filter((key) => !permissionKeys.includes(key));
  } else {
    permissionKeys.forEach((key) => {
      if (!newValue.includes(key)) {
        newValue.push(key);
      }
    });
  }

  selectedPermissions.value = newValue;
};

const isGroupFullySelected = (module) => {
  const permissions = props.availablePermissions[module] || [];
  return permissions.every((p) => selectedPermissions.value.includes(p.key));
};

const isGroupPartiallySelected = (module) => {
  const permissions = props.availablePermissions[module] || [];
  const selectedCount = permissions.filter((p) => selectedPermissions.value.includes(p.key)).length;
  return selectedCount > 0 && selectedCount < permissions.length;
};

const formatModuleName = (module) => {
  return module
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase());
};

watch(
  () => searchQuery.value,
  (query) => {
    if (query) {
      expandedGroups.value = Object.keys(filteredPermissions.value);
    }
  }
);
</script>

<template>
  <div class="permission-picker">
    <div class="mb-4">
      <div class="relative">
        <Search
          class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400"
        />
        <Input
          v-model="searchQuery"
          type="text"
          placeholder="Search permissions..."
          class="pl-10"
          :disabled="disabled"
        />
      </div>
    </div>

    <div
      v-if="Object.keys(filteredPermissions).length === 0"
      class="text-center py-8 text-gray-500 dark:text-gray-400"
    >
      No permissions found matching your search.
    </div>

    <div class="space-y-2">
      <div
        v-for="(permissions, module) in filteredPermissions"
        :key="module"
        class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden"
      >
        <button
          type="button"
          @click="toggleGroup(module)"
          class="w-full flex items-center justify-between px-4 py-3 bg-gray-50 dark:bg-gray-800/50 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
          :disabled="disabled"
        >
          <div class="flex items-center gap-3">
            <component
              :is="isGroupExpanded(module) ? ChevronDown : ChevronRight"
              class="h-4 w-4 text-gray-500"
            />
            <span class="font-medium text-gray-900 dark:text-gray-100">
              {{ formatModuleName(module) }}
            </span>
            <span class="text-sm text-gray-500 dark:text-gray-400">
              ({{ permissions.length }})
            </span>
          </div>
          <button
            type="button"
            @click.stop="toggleAllInGroup(module)"
            :class="[
              'text-xs px-2 py-1 rounded transition-colors',
              isGroupFullySelected(module)
                ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300'
                : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400 hover:bg-gray-300 dark:hover:bg-gray-600',
            ]"
            :disabled="disabled"
          >
            {{ isGroupFullySelected(module) ? 'Deselect All' : 'Select All' }}
          </button>
        </button>

        <div
          v-show="isGroupExpanded(module)"
          class="px-4 py-3 space-y-2 bg-white dark:bg-gray-900"
        >
          <label
            v-for="permission in permissions"
            :key="permission.key"
            :class="[
              'flex items-center gap-3 p-2 rounded-md cursor-pointer transition-colors',
              isPermissionSelected(permission.key)
                ? 'bg-blue-50 dark:bg-blue-900/20'
                : 'hover:bg-gray-50 dark:hover:bg-gray-800/50',
              disabled && 'opacity-50 cursor-not-allowed',
            ]"
          >
            <input
              type="checkbox"
              :checked="isPermissionSelected(permission.key)"
              @change="togglePermission(permission.key)"
              :disabled="disabled"
              class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-blue-600 focus:ring-blue-500 focus:ring-offset-0 dark:bg-gray-800"
            />
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                {{ permission.label }}
              </div>
              <div class="text-xs text-gray-500 dark:text-gray-400 font-mono">
                {{ permission.key }}
              </div>
            </div>
            <Check
              v-if="isPermissionSelected(permission.key)"
              class="h-4 w-4 text-blue-600 dark:text-blue-400 shrink-0"
            />
          </label>
        </div>
      </div>
    </div>

    <div
      v-if="selectedPermissions.length > 0"
      class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700"
    >
      <div class="text-sm text-gray-600 dark:text-gray-400">
        <span class="font-medium text-gray-900 dark:text-gray-100">
          {{ selectedPermissions.length }}
        </span>
        permission{{ selectedPermissions.length === 1 ? '' : 's' }} selected
      </div>
    </div>
  </div>
</template>
