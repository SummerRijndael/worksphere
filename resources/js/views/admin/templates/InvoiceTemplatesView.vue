<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { invoiceTemplateService, type InvoiceTemplate } from '@/services/invoice-template.service';
import Button from '@/components/ui/Button.vue';
import Card from '@/components/ui/Card.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import Modal from '@/components/ui/Modal.vue';
import InvoiceTemplateBuilder from '@/components/templates/InvoiceTemplateBuilder.vue';
import { Pencil, Trash2, Plus, FileText, ArrowLeft } from 'lucide-vue-next';

// Props/Route
const route = useRoute();
const router = useRouter();
const teamId = route.params.id as string; 

const templates = ref<InvoiceTemplate[]>([]);
const loading = ref(true);
const isModalOpen = ref(false);
const selectedTemplate = ref<InvoiceTemplate | undefined>(undefined);

const fetchTemplates = async () => {
    loading.value = true;
    try {
        if (teamId) {
            const data = await invoiceTemplateService.getAll(teamId);
            templates.value = data || [];
        }
    } catch (error) {
        console.error('Failed to fetch invoice templates', error);
        templates.value = [];
    } finally {
        loading.value = false;
    }
};

const handleCreate = () => {
    selectedTemplate.value = undefined;
    isModalOpen.value = true;
};

const handleEdit = (template: InvoiceTemplate) => {
    selectedTemplate.value = template;
    isModalOpen.value = true;
};

const handleDelete = async (id: string) => {
    if (!confirm('Are you sure you want to delete this template?')) return;
    try {
        await invoiceTemplateService.delete(teamId, id);
        await fetchTemplates();
    } catch (error) {
        console.error('Failed to delete template', error);
    }
};

const handleSaved = async () => {
    isModalOpen.value = false;
    selectedTemplate.value = undefined;
    await fetchTemplates();
};

onMounted(() => {
    fetchTemplates();
});
</script>

<template>
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" @click="router.push({ name: 'team-profile', params: { public_id: teamId }, query: { tab: 'templates' } })">
                    <ArrowLeft class="h-5 w-5" />
                </Button>
                <div>
                    <h1 class="text-2xl font-semibold text-[var(--text-primary)]">Invoice Templates</h1>
                    <p class="text-[var(--text-secondary)] mt-1">Manage invoice templates for your team.</p>
                </div>
            </div>
            <Button @click="handleCreate">
                <Plus class="h-4 w-4 mr-2" />
                New Template
            </Button>
        </div>

        <Card>
            <div v-if="loading" class="p-8 text-center text-[var(--text-muted)]">
                Loading templates...
            </div>
            <div v-else-if="templates?.length === 0" class="p-12 text-center flex flex-col items-center justify-center">
                <div class="h-12 w-12 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mb-4">
                    <FileText class="h-6 w-6 text-[var(--text-muted)]" />
                </div>
                <h3 class="text-lg font-medium text-[var(--text-primary)]">No templates found</h3>
                <p class="text-[var(--text-secondary)] mt-1 mb-6 max-w-sm">
                    Create templates to quickly generate invoices with predefined terms and branding.
                </p>
                <Button @click="handleCreate">
                    <Plus class="h-4 w-4 mr-2" />
                    Create Template
                </Button>
            </div>
            <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-[var(--surface-secondary)] border-b border-[var(--border-default)]">
                        <tr>
                            <th class="text-left px-6 py-3 text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider">Name</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider">Currency</th>
                            <th class="text-left px-6 py-3 text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider">Status</th>
                            <th class="text-right px-6 py-3 text-xs font-medium text-[var(--text-secondary)] uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-default)]">
                        <tr v-for="template in templates" :key="template.id" class="hover:bg-[var(--surface-secondary)]/50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-medium text-[var(--text-primary)]">{{ template.name }}</div>
                                <div v-if="template.description" class="text-xs text-[var(--text-muted)] mt-0.5 line-clamp-1">
                                    {{ template.description }}
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-[var(--text-secondary)]">
                                {{ template.currency }}
                            </td>
                            <td class="px-6 py-4">
                                <StatusBadge :status="template.is_active ? 'success' : 'neutral'">
                                    {{ template.is_active ? 'Active' : 'Inactive' }}
                                </StatusBadge>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <Button variant="ghost" size="sm" @click="handleEdit(template)">
                                        <Pencil class="h-4 w-4" />
                                    </Button>
                                    <Button variant="ghost" size="sm" class="text-red-500 hover:text-red-600 hover:bg-red-50" @click="handleDelete(template.id)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Modal 
            :open="isModalOpen" 
            @update:open="isModalOpen = $event"
            :title="selectedTemplate ? 'Edit Invoice Template' : 'Create Invoice Template'"
            size="2xl"
        >
            <InvoiceTemplateBuilder 
                v-if="isModalOpen"
                :team-id="teamId"
                :template="selectedTemplate"
                @saved="handleSaved"
                @cancelled="isModalOpen = false"
            />
        </Modal>
    </div>
</template>
