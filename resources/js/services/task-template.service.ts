import api from "@/lib/api";

export interface TaskTemplate {
    public_id: string;
    team_id: number;
    name: string;
    description: string | null;
    default_priority: string | null;
    default_estimated_hours: number | null;
    checklist_template: any[] | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface CreateTaskTemplateInput {
    name: string;
    description?: string;
    default_priority?: string;
    default_estimated_hours?: number;
    checklist_template?: any[];
    is_active?: boolean;
}

export interface UpdateTaskTemplateInput {
    name?: string;
    description?: string;
    default_priority?: string;
    default_estimated_hours?: number;
    checklist_template?: any[];
    is_active?: boolean;
}

export const taskTemplateService = {
    async getAll(teamId: string): Promise<TaskTemplate[]> {
        const response = await api.get(`/api/teams/${teamId}/task-templates`);
        return response.data.data;
    },

    async get(teamId: string, templateId: string): Promise<TaskTemplate> {
        const response = await api.get(`/api/teams/${teamId}/task-templates/${templateId}`);
        return response.data.data;
    },

    async create(teamId: string, input: CreateTaskTemplateInput): Promise<TaskTemplate> {
        const response = await api.post(`/api/teams/${teamId}/task-templates`, input);
        return response.data.data;
    },

    async update(teamId: string, templateId: string, input: UpdateTaskTemplateInput): Promise<TaskTemplate> {
        const response = await api.put(`/api/teams/${teamId}/task-templates/${templateId}`, input);
        return response.data.data;
    },

    async delete(teamId: string, templateId: string): Promise<void> {
        await api.delete(`/api/teams/${teamId}/task-templates/${templateId}`);
    }
};
