import axios from "axios";

export interface LinkPreviewData {
    title?: string;
    description?: string;
    image?: string;
    url: string;
    type?: string;
    site_name?: string;
    error?: string;
}

export class LinkUnfurlService {
    static async unfurl(url: string): Promise<LinkPreviewData> {
        try {
            const response = await axios.post<LinkPreviewData>("/api/link/unfurl", { url });
            return response.data;
        } catch (error: any) {
            if (error.response?.data?.error === 'unsafe_content_blocked') {
                 return {
                    url,
                    error: 'unsafe_content_blocked'
                };
            }
            throw error;
        }
    }
}
