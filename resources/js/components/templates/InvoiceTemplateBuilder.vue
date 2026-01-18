<script setup lang="ts">
import { ref } from 'vue';
import { invoiceTemplateService, type CreateInvoiceTemplateInput, type InvoiceTemplate } from '@/services/invoice-template.service';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import SelectFilter from '@/components/ui/SelectFilter.vue';
import Textarea from '@/components/ui/Textarea.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import { Plus, Trash2 } from 'lucide-vue-next';

const props = defineProps<{
    teamId: string;
    template?: InvoiceTemplate; // If provided, edit mode
}>();

const emit = defineEmits(['saved', 'cancelled']);

const form = ref<CreateInvoiceTemplateInput>({
    name: '',
    description: '',
    currency: 'USD',
    default_terms: '',
    default_notes: '',
    logo_url: '',
    is_active: true,
    line_items: [],
});

const loading = ref(false);

// Init form if editing
if (props.template) {
    form.value = {
        name: props.template.name,
        description: props.template.description || '',
        currency: props.template.currency,
        default_terms: props.template.default_terms || '',
        default_notes: props.template.default_notes || '',
        logo_url: props.template.logo_url || '',
        is_active: props.template.is_active,
        line_items: props.template.line_items || [],
    };
}

const addLineItem = () => {
    form.value.line_items = [
        ...(form.value.line_items || []),
        { description: '', quantity: 1, unit_price: 0 }
    ];
};

const removeLineItem = (index: number) => {
    if (!form.value.line_items) return;
    form.value.line_items = form.value.line_items.filter((_, i) => i !== index);
};

const handleSubmit = async () => {
    loading.value = true;
    try {
        if (props.template) {
            await invoiceTemplateService.update(props.teamId, props.template.id, form.value);
        } else {
            await invoiceTemplateService.create(props.teamId, form.value);
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
                <Input v-model="form.name" required placeholder="e.g., Standard Service, Consulting Retainer" />
            </div>

            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Description</label>
                <Textarea v-model="form.description" rows="2" placeholder="Internal description..." />
            </div>

            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Currency</label>
                <SelectFilter v-model="form.currency" :options="[
                    { value: 'USD', label: 'USD ($)' },
                    { value: 'EUR', label: 'EUR (€)' },
                    { value: 'GBP', label: 'GBP (£)' },
                    { value: 'CAD', label: 'CAD ($)' },
                    { value: 'AUD', label: 'AUD ($)' },
                ]" />
            </div>

            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Default Terms</label>
                <Textarea v-model="form.default_terms" rows="4" placeholder="e.g., Net 30, Please pay via Bank Transfer..." />
            </div>

            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-3">Line Items</label>
                <div class="space-y-3">
                    <div 
                        v-for="(item, index) in form.line_items" 
                        :key="index"
                        class="grid grid-cols-12 gap-2 items-start"
                    >
                        <div class="col-span-6">
                            <Input v-model="item.description" placeholder="Description" required />
                        </div>
                        <div class="col-span-2">
                            <Input v-model.number="item.quantity" type="number" min="0" step="0.01" placeholder="Qty" required />
                        </div>
                        <div class="col-span-3">
                            <Input v-model.number="item.unit_price" type="number" min="0" step="0.01" placeholder="Price" required />
                        </div>
                        <div class="col-span-1 pt-1">
                            <Button 
                                type="button" 
                                variant="ghost" 
                                size="sm"
                                class="text-red-500 hover:text-red-700 hover:bg-red-50" 
                                @click="removeLineItem(index)"
                            >
                                <Trash2 class="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                    
                    <Button type="button" variant="secondary" size="sm" @click="addLineItem">
                        <Plus class="h-4 w-4 mr-2" />
                        Add Item
                    </Button>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Default Notes</label>
                <Textarea v-model="form.default_notes" rows="2" placeholder="e.g., Thank you for your business!" />
            </div>

            <div>
                <label class="block text-sm font-medium text-[var(--text-primary)] mb-1">Logo URL</label>
                <Input v-model="form.logo_url" placeholder="https://..." />
                <p class="text-xs text-[var(--text-muted)] mt-1">Provide a public URL for your logo.</p>
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
