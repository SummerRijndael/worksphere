<script setup lang="ts">
import { ref } from 'vue';
import { taskTemplateService, type CreateTaskTemplateInput, type TaskTemplate } from '@/services/task-template.service';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import SelectFilter from '@/components/ui/SelectFilter.vue';
import Textarea from '@/components/ui/Textarea.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import { Trash2 } from 'lucide-vue-next';

const props = defineProps<{
    teamId: string;
    template?: TaskTemplate; // If provided, edit mode
}>();

const emit = defineEmits(['saved', 'cancelled']);

const form = ref<CreateTaskTemplateInput>({
    name: '',
    description: '',
    default_priority: 'medium',
    default_estimated_hours: 0,
    checklist_template: [],
    is_active: true,
});

const loading = ref(false);
const checklistItem = ref('');

// Init form if editing
if (props.template) {
    form.value = {
        name: props.template.name,
        description: props.template.description || '',
        default_priority: props.template.default_priority || 'medium',
        default_estimated_hours: props.template.default_estimated_hours || 0,
        checklist_template: props.template.checklist_template || [],
        is_active: props.template.is_active,
    };
}

const addChecklistItem = () => {
    if (!checklistItem.value.trim()) return;
    form.value.checklist_template = [...(form.value.checklist_template || []), { text: checklistItem.value.trim(), is_completed: false }];
    checklistItem.value = '';
};

const removeChecklistItem = (index: number) => {
    if (!form.value.checklist_template) return;
    form.value.checklist_template = form.value.checklist_template.filter((_, i) => i !== index);
};

const handleSubmit = async () => {
    loading.value = true;
    try {
        if (props.template) {
            await taskTemplateService.update(props.teamId, props.template.id, form.value);
        } else {
            await taskTemplateService.create(props.teamId, form.value);
        }
        emit('saved');
    } catch (error) {
        console.error('Failed to save template', error);
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <form @submit.prevent="handleSubmit" class="space-y-6">
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Template Name</label>
                <Input v-model="form.name" required placeholder="e.g., Bug Report, Feature Request" />
            </div>

            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Description</label>
                <Textarea v-model="form.description" rows="3" placeholder="Describe the purpose of this template..." />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Default Priority</label>
                    <SelectFilter 
                        v-model="form.default_priority"
                        :options="[
                            { value: 'low', label: 'Low' },
                            { value: 'medium', label: 'Medium' },
                            { value: 'high', label: 'High' },
                            { value: 'urgent', label: 'Urgent' }
                        ]"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Est. Hours</label>
                    <Input v-model.number="form.default_estimated_hours" type="number" step="0.5" min="0" />
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Default Checklist</label>
                <div class="flex gap-2 mb-2">
                    <Input 
                        v-model="checklistItem" 
                        placeholder="Add checklist item..." 
                        @keydown.enter.prevent="addChecklistItem"
                    />
                    <Button type="button" @click="addChecklistItem" variant="secondary">Add</Button>
                </div>
                <div class="space-y-2">
                    <div 
                        v-for="(item, index) in form.checklist_template" 
                        :key="index"
                        class="flex items-center justify-between p-2 bg-[var(--surface-secondary)] rounded-md"
                    >
                        <span class="text-sm text-[var(--text-primary)]">{{ item.text }}</span>
                        <button 
                            type="button" 
                            @click="removeChecklistItem(index)"
                            class="text-[var(--text-muted)] hover:text-red-500"
                        >
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <Checkbox v-model="form.is_active" />
                    <span class="text-sm text-[var(--text-primary)]">Active</span>
                </label>
            </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-[var(--border-default)]">
            <Button type="button" variant="ghost" @click="$emit('cancelled')">Cancel</Button>
            <Button type="submit" :loading="loading">{{ props.template ? 'Update' : 'Create' }} Template</Button>
        </div>
    </form>
</template>
