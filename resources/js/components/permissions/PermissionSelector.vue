<script setup>
import { computed } from 'vue';
import { Check, Square, CheckSquare } from 'lucide-vue-next';

const props = defineProps({
    allPermissions: {
        type: Array,
        required: true,
        default: () => []
    },
    modelValue: {
        type: Array,
        default: () => []
    },
    readonly: {
        type: Boolean,
        default: false
    },
    searchQuery: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['update:modelValue']);

// Group permissions by category
const groupedPermissions = computed(() => {
    const groups = {};
    
    props.allPermissions.forEach(group => {
        // API returns groups already: { category: 'users', label: 'Users', permissions: [...] }
        groups[group.label] = group.permissions.map(p => ({
            name: p.name,
            label: p.label,
        }));
    });
    
    return groups;
});

// Filter based on search
const filteredPermissions = computed(() => {
    if (!props.searchQuery) return groupedPermissions.value;
    const query = props.searchQuery.toLowerCase();
    
    const filtered = {};
    Object.keys(groupedPermissions.value).forEach(categoryLabel => {
        const matches = groupedPermissions.value[categoryLabel].filter(p => 
            p.name.toLowerCase().includes(query) || 
            p.label.toLowerCase().includes(query) ||
            categoryLabel.toLowerCase().includes(query)
        );
        if (matches.length) filtered[categoryLabel] = matches;
    });
    return filtered;
});

function isSelected(permName) {
    return props.modelValue.includes(permName) || props.modelValue.includes('*');
}

function togglePermission(permName) {
    if (props.readonly) return;
    
    const newSelection = [...props.modelValue];
    const index = newSelection.indexOf(permName);
    
    if (index === -1) {
        newSelection.push(permName);
    } else {
        newSelection.splice(index, 1);
    }
    
    emit('update:modelValue', newSelection);
}
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="(perms, categoryLabel) in filteredPermissions" :key="categoryLabel" class="space-y-2">
            <div class="flex items-center gap-2 px-1">
                <div class="w-1.5 h-1.5 rounded-full bg-[var(--color-primary-500)] shrink-0"></div>
                <span class="text-xs font-bold text-[var(--text-secondary)] uppercase tracking-wider truncate" :title="categoryLabel">
                    {{ categoryLabel }}
                </span>
            </div>
            <div class="bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg overflow-hidden flex flex-col max-h-[300px] overflow-y-auto custom-scrollbar">
                <div 
                    v-for="perm in perms" 
                    :key="perm.name" 
                    class="p-2.5 border-b border-[var(--border-default)] last:border-0 transition-colors flex items-start gap-3 group min-h-[50px]"
                    :class="!readonly ? 'hover:bg-[var(--surface-secondary)] cursor-pointer' : ''"
                    @click="togglePermission(perm.name)"
                >
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-[var(--text-primary)] truncate" :title="perm.label">{{ perm.label }}</div>
                        <div class="text-[10px] text-[var(--text-muted)] font-mono truncate" :title="perm.name">{{ perm.name }}</div>
                    </div>
                    
                    <div class="shrink-0 pt-0.5">
                        <div v-if="readonly">
                            <Check v-if="isSelected(perm.name)" class="h-4 w-4 text-[var(--color-success-fg)]" />
                        </div>
                        <div v-else>
                            <div 
                                class="h-4 w-4 rounded border flex items-center justify-center transition-all duration-200"
                                :class="isSelected(perm.name) 
                                    ? 'bg-[var(--color-primary-500)] border-[var(--color-primary-500)] text-white' 
                                    : 'border-[var(--text-tertiary)] bg-transparent group-hover:border-[var(--text-secondary)]'"
                            >
                                <Check v-if="isSelected(perm.name)" class="h-3 w-3" stroke-width="3" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div v-if="Object.keys(filteredPermissions).length === 0" class="col-span-full text-center py-12 text-[var(--text-secondary)]">
            No permissions found matching your search.
        </div>
    </div>
</template>
