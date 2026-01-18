import { ref } from 'vue';

export interface EmailTemplate {
    id: string;
    name: string;
    subject: string;
    body: string; // HTML content for editor
}

const defaultTemplates: EmailTemplate[] = [
    {
        id: 'tpl-1',
        name: 'Meeting Request',
        subject: 'Meeting Request: [Topic]',
        body: '<p>Hi [Name],</p><p>I would like to request a meeting to discuss [Topic]. Are you available on [Date/Time]?</p><p>Best,</p>'
    },
    {
        id: 'tpl-2',
        name: 'Weekly Report',
        subject: 'Weekly Status Report - [Date]',
        body: '<h3>Weekly Status Report</h3><p><strong>Highlights:</strong></p><ul><li>...</li></ul><p><strong>Blockers:</strong></p><ul><li>...</li></ul>'
    },
    {
        id: 'tpl-3',
        name: 'Follow Up',
        subject: 'Following up on our conversation',
        body: '<p>Hi [Name],</p><p>Just wanted to follow up on our conversation earlier regarding...</p>'
    }
];

const templates = ref<EmailTemplate[]>([...defaultTemplates]);

function getTemplateById(id: string): EmailTemplate | undefined {
    return templates.value.find(t => t.id === id);
}

function addTemplate(template: Omit<EmailTemplate, 'id'>) {
    const newTemplate = {
        ...template,
        id: `tpl-${Date.now()}`
    };
    templates.value.push(newTemplate);
    return newTemplate;
}

function updateTemplate(id: string, updates: Partial<Omit<EmailTemplate, 'id'>>) {
    const index = templates.value.findIndex(t => t.id === id);
    if (index !== -1) {
        templates.value[index] = { ...templates.value[index], ...updates };
    }
}

function deleteTemplate(id: string) {
    templates.value = templates.value.filter(t => t.id !== id);
}

export function useEmailTemplates() {
    return {
        templates,
        getTemplateById,
        addTemplate,
        updateTemplate,
        deleteTemplate
    };
}
