import { ref } from "vue";
import { LinkUnfurlService, type LinkPreviewData } from "@/services/LinkUnfurlService";

export function useLinkPreview() {
    const loading = ref(false);
    const preview = ref<LinkPreviewData | null>(null);
    const error = ref<string | null>(null);

    const fetchPreview = async (url: string) => {
        loading.value = true;
        error.value = null;
        try {
            preview.value = await LinkUnfurlService.unfurl(url);
        } catch (err: any) {
            console.error("Failed to fetch link preview", err);
            error.value = "Failed to load preview";
        } finally {
            loading.value = false;
        }
    };

    return {
        loading,
        preview,
        error,
        fetchPreview,
    };
}
