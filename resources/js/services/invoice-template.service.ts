import api from "@/lib/api";

export interface InvoiceTemplate {
    public_id: string;
    team_id: number;
    name: string;
    description: string | null;
    currency: string;
    default_terms: string | null;
    default_notes: string | null;
    logo_url: string | null;
    is_active: boolean;
    line_items?: { description: string; quantity: number; unit_price: number }[];
    created_at: string;
    updated_at: string;
}

export interface CreateInvoiceTemplateInput {
    name: string;
    description?: string;
    currency?: string;
    default_terms?: string;
    default_notes?: string;
    logo_url?: string;
    is_active?: boolean;
    line_items?: { description: string; quantity: number; unit_price: number }[];
}

export interface UpdateInvoiceTemplateInput {
    name?: string;
    description?: string;
    currency?: string;
    default_terms?: string;
    default_notes?: string;
    logo_url?: string;
    is_active?: boolean;
}

export const invoiceTemplateService = {
    async getAll(teamId: string): Promise<InvoiceTemplate[]> {
        const response = await api.get(`/api/teams/${teamId}/invoice-templates`);
        return response.data.data;
    },

    async get(teamId: string, templateId: string): Promise<InvoiceTemplate> {
        const response = await api.get(`/api/teams/${teamId}/invoice-templates/${templateId}`);
        return response.data.data;
    },

    async create(teamId: string, input: CreateInvoiceTemplateInput): Promise<InvoiceTemplate> {
        const response = await api.post(`/api/teams/${teamId}/invoice-templates`, input);
        return response.data.data;
    },

    async update(teamId: string, templateId: string, input: UpdateInvoiceTemplateInput): Promise<InvoiceTemplate> {
        const response = await api.put(`/api/teams/${teamId}/invoice-templates/${templateId}`, input);
        return response.data.data;
    },

    async delete(teamId: string, templateId: string): Promise<void> {
        await api.delete(`/api/teams/${teamId}/invoice-templates/${templateId}`);
    }
};
