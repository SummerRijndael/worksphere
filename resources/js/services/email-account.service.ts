import { BaseService } from './base.service';
import type { ApiResponse } from '@/types';

export interface EmailAccount {
    id: string; // UUID from public_id
    name: string;
    email: string;
    provider: 'custom' | 'gmail' | 'outlook';
    auth_type: 'password' | 'oauth';
    imap_host?: string;
    imap_port?: number;
    imap_encryption?: 'ssl' | 'tls' | 'none';
    smtp_host?: string;
    smtp_port?: number;
    smtp_encryption?: 'ssl' | 'tls' | 'none';
    username?: string;
    is_active: boolean;
    is_verified: boolean;
    is_default: boolean;
    is_personal: boolean;
    is_shared: boolean;
    last_error?: string;
    sync_status?: string;
}

export interface CreateAccountParams {
    name: string;
    email: string;
    provider: 'custom' | 'gmail' | 'outlook';
    auth_type: 'password' | 'oauth';
    imap_host?: string;
    imap_port?: number;
    imap_encryption?: 'ssl' | 'tls' | 'none';
    smtp_host?: string;
    smtp_port?: number;
    smtp_encryption?: 'ssl' | 'tls' | 'none';
    username?: string;
    password?: string;
    team_id?: number | null;
}

export class EmailAccountService extends BaseService {
    async list(): Promise<EmailAccount[]> {
        try {
            const response = await this.api.get<ApiResponse<EmailAccount[]>>('/api/email-accounts');
            return this.extractData(response);
        } catch (error) {
            this.handleError(error);
        }
    }

    async create(data: CreateAccountParams): Promise<EmailAccount> {
        try {
            const response = await this.api.post<ApiResponse<EmailAccount>>('/api/email-accounts', data);
            return this.extractData(response);
        } catch (error) {
            this.handleError(error);
        }
    }

    async update(id: number, data: Partial<EmailAccount> & { password?: string }): Promise<EmailAccount> {
        try {
            const response = await this.api.put<ApiResponse<EmailAccount>>(`/api/email-accounts/${id}`, data);
            return this.extractData(response);
        } catch (error) {
            this.handleError(error);
        }
    }

    async delete(id: number): Promise<void> {
        try {
            await this.api.delete(`/api/email-accounts/${id}`);
        } catch (error) {
            this.handleError(error);
        }
    }
    
    async testConnection(id: number): Promise<{ success: boolean; message: string }> {
        try {
             // Response is not wrapped in standard ApiResponse data envelope in controller for this specific route logic?
             // Controller: return response()->json($result); where result = ['success' => bool, 'message' => string]
             // So it's direct JSON.
             const response = await this.api.post(`/api/email-accounts/${id}/test`);
             return response.data;
        } catch (error) {
            // this.handleError(error); // Might want custom handling
            throw error;
        }
    }

    async testConfiguration(data: CreateAccountParams): Promise<{ success: boolean; message: string }> {
        try {
             const response = await this.api.post('/api/email-accounts/test-configuration', data);
             return response.data;
        } catch (error) {
             throw error;
        }
    }
      
    async getProviders(): Promise<any[]> {
        try {
            const response = await this.api.get<ApiResponse<any[]>>('/api/email-accounts/providers');
            return this.extractData(response); 
        } catch (error) {
             this.handleError(error);
        }
    }

    async sync(id: number | string): Promise<{ message: string; status: string }> {
        try {
            const response = await this.api.post(`/api/email-accounts/${id}/sync`);
            return response.data;
        } catch (error) {
            this.handleError(error);
            throw error;
        }
    }
}

export const emailAccountService = new EmailAccountService();
