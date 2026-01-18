import axios from 'axios';

export interface BlockedUrl {
    id: number;
    pattern: string;
    reason: string | null;
    created_at: string;
}

export interface BlockedUrlResponse {
    data: BlockedUrl[];
    links?: any;
    meta?: any;
}

export default {
    async getBlockedUrls(page = 1, search = ''): Promise<BlockedUrlResponse> {
        const params: any = { page };
        if (search) params.search = search;
        
        const response = await axios.get('/api/blocked-urls', { params });
        return response.data;
    },

    async addBlockedUrl(pattern: string, reason: string = ''): Promise<BlockedUrl> {
        const response = await axios.post('/api/blocked-urls', { pattern, reason });
        return response.data.data;
    },

    async deleteBlockedUrl(id: number): Promise<void> {
        await axios.delete(`/api/blocked-urls/${id}`);
    }
}
