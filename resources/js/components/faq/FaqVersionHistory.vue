<script setup>
import { ref, watch } from "vue";
import api from "@/lib/api";
import { format } from "date-fns";
import { 
    Clock, 
    RotateCcw, 
    User,
    ChevronRight,
    History,
    Calendar,
    Eye
} from "lucide-vue-next";
import { Button } from "@/components/ui";
import { toast } from "vue-sonner";

const props = defineProps({
    articleId: {
        type: [String, Number],
        required: true,
    },
});

const emit = defineEmits(["restore"]);

const versions = ref([]);
const loading = ref(false);
const currentPage = ref(1);
const totalPages = ref(1);

// Preview State
const selectedVersion = ref(null);
const previewLoading = ref(false);

const openPreview = async (version) => {
    selectedVersion.value = version; // Instant feedback
    previewLoading.value = true;

    try {
        // Fetch full content
        const response = await api.get(`/api/admin/faq/versions/${version.id}`);
        selectedVersion.value = response.data;
    } catch (error) {
        console.error("Failed to load version details", error);
        toast.error("Failed to load version details");
    } finally {
        previewLoading.value = false;
    }
};

const fetchVersions = async (page = 1) => {
    loading.value = true;
    try {
        const response = await api.get(
            `/api/admin/faq/articles/${props.articleId}/versions`,
            { params: { page, per_page: 10 } }
        );
        versions.value = response.data.data;
        totalPages.value = response.data.last_page;
        currentPage.value = response.data.current_page;

        // Auto-select first version if none selected
        if (!selectedVersion.value && versions.value.length > 0) {
            openPreview(versions.value[0]);
        }
    } catch (error) {
        console.error("Failed to fetch versions", error);
        toast.error("Failed to load version history");
    } finally {
        loading.value = false;
    }
};

const handleRestore = async () => {
    if (!selectedVersion.value) return;

    if (!confirm("Are you sure you want to restore this version? It will overwrite the current content.")) {
        return;
    }

    try {
        await api.post(`/api/admin/faq/versions/${selectedVersion.value.id}/restore`);
        toast.success("Version restored successfully");
        emit("restore"); // Notify parent to refresh article
        
        // Refresh history to show the new restore point
        // We'll reset pagination to 1 to see the new version
        fetchVersions(1); 
    } catch (error) {
        console.error("Restore failed", error);
        toast.error("Failed to restore version");
    }
};

watch(() => props.articleId, (newId) => {
    if (newId) {
        selectedVersion.value = null; // Reset selection on new article
        fetchVersions();
    }
}, { immediate: true });

</script>

<template>
    <div class="h-full grid lg:grid-cols-3 gap-0 bg-[var(--surface-primary)] divide-y lg:divide-y-0 lg:divide-x divide-[var(--border-default)]">

        <!-- Left Panel: List (1 Col) -->
        <div class="flex flex-col h-full overflow-hidden bg-[var(--surface-primary)]">
            <!-- Header -->
            <div class="p-4 border-b border-[var(--border-default)] flex items-center justify-between shrink-0 bg-[var(--surface-elevated)]">
                <h3 class="font-medium text-[var(--text-primary)] flex items-center gap-2">
                    <History class="w-4 h-4 text-[var(--text-muted)]" />
                    History
                </h3>
                <Button variant="ghost" size="icon-sm" @click="fetchVersions(currentPage)" title="Refresh">
                    <RotateCcw class="w-3.5 h-3.5" />
                </Button>
            </div>

            <!-- List Content -->
             <div class="flex-1 overflow-y-auto p-4">
                <div v-if="loading" class="flex justify-center py-8">
                    <div class="animate-spin h-6 w-6 border-2 border-[var(--primary)] border-b-transparent rounded-full"></div>
                </div>

                <div v-else-if="versions.length === 0" class="text-center py-8 text-[var(--text-muted)]">
                    <p>No history available.</p>
                </div>

                <div v-else class="space-y-3">
                    <div 
                        v-for="(version, index) in versions" 
                        :key="version.id"
                        class="p-4 rounded-xl border transition-all cursor-pointer group flex gap-3 relative"
                        :class="selectedVersion?.id === version.id ? 'bg-[var(--surface-elevated)] border-[var(--interactive-primary)] shadow-sm' : 'bg-[var(--surface-primary)] border-[var(--border-default)] hover:border-[var(--border-strong)] hover:bg-[var(--surface-subtle)]'"
                        @click="openPreview(version)"
                    >
                        <!-- Avatar -->
                        <div class="shrink-0">
                            <div class="w-9 h-9 rounded-full bg-[var(--surface-muted)] flex items-center justify-center overflow-hidden border border-[var(--border-subtle)]">
                                 <img v-if="version.author?.avatar_url" :src="version.author.avatar_url" class="w-full h-full object-cover">
                                 <User v-else class="w-4 h-4 text-[var(--text-muted)]" />
                            </div>
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs font-medium text-[var(--text-secondary)]">
                                    {{ version.author?.name || 'Unknown' }}
                                </span>
                                <span v-if="index === 0 && currentPage === 1" class="text-[10px] bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)] px-1.5 py-0.5 rounded font-medium">Latest</span>
                            </div>
                            
                            <h4 class="text-sm font-semibold text-[var(--text-primary)] mb-1.5 truncate leading-tight">
                                {{ version.title || 'Untitled Version' }}
                            </h4>
                            
                            <div class="flex items-center gap-2 text-xs text-[var(--text-secondary)]">
                                <Calendar class="w-3 h-3" />
                                {{ format(new Date(version.created_at), "MMM d, yyyy • h:mm a") }}
                            </div>
                        </div>

                         <!-- Active Indicator -->
                         <div v-if="selectedVersion?.id === version.id" class="absolute right-0 top-0 bottom-0 w-1 bg-[var(--interactive-primary)] rounded-r-xl"></div>
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="p-3 border-t border-[var(--border-default)] bg-[var(--surface-elevated)] shrink-0">
                <div class="flex items-center justify-between gap-2">
                    <Button 
                        variant="outline" 
                        size="sm" 
                        :disabled="currentPage <= 1"
                        @click="fetchVersions(currentPage - 1)"
                        class="h-8 flex-1"
                    >
                        Prev
                    </Button>
                    <span class="text-xs text-[var(--text-secondary)] font-medium">
                        {{ currentPage }} / {{ totalPages }}
                    </span>
                    <Button 
                        variant="outline" 
                        size="sm" 
                        :disabled="currentPage >= totalPages"
                        @click="fetchVersions(currentPage + 1)"
                        class="h-8 flex-1"
                    >
                        Next
                    </Button>
                </div>
            </div>
        </div>
        
        <!-- Right Panel: Preview (2 Cols) -->
        <div class="lg:col-span-2 flex flex-col h-full overflow-hidden bg-[var(--surface-primary)]">
            <div v-if="selectedVersion" class="flex-1 flex flex-col h-full">
                <!-- Preview Header -->
                <div class="p-4 border-b border-[var(--border-default)] flex items-center justify-between bg-[var(--surface-elevated)] shrink-0">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-[var(--surface-muted)] flex items-center justify-center overflow-hidden text-[var(--text-muted)] border border-[var(--border-subtle)]">
                             <img v-if="selectedVersion.author?.avatar_url" :src="selectedVersion.author.avatar_url" class="w-full h-full object-cover">
                             <User v-else class="w-5 h-5" />
                        </div>
                        <div>
                            <p class="font-medium text-[var(--text-primary)]">
                                {{ selectedVersion.author?.name || 'Unknown' }}
                            </p>
                            <p class="text-xs text-[var(--text-secondary)]">
                                Archived on {{ format(new Date(selectedVersion.created_at), "PPp") }}
                            </p>
                        </div>
                    </div>
                    <Button @click="handleRestore" class="bg-amber-600 hover:bg-amber-700 text-white shadow-sm">
                        <RotateCcw class="w-4 h-4 mr-2" />
                        Restore This Version
                    </Button>
                </div>

                <!-- Preview Content (Scrollable) -->
                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    <div v-if="previewLoading" class="flex justify-center py-12">
                         <div class="animate-spin h-8 w-8 border-2 border-[var(--primary)] border-b-transparent rounded-full"></div>
                    </div>
                    <div v-else class="max-w-3xl mx-auto space-y-8">
                        <!-- Title -->
                        <div>
                            <h4 class="text-xs uppercase font-bold text-[var(--text-muted)] mb-2 tracking-wider">Title</h4>
                            <div class="text-2xl font-bold text-[var(--text-primary)] leading-tight">{{ selectedVersion.title }}</div>
                        </div>

                        <!-- Tags -->
                        <div v-if="selectedVersion.tags && selectedVersion.tags.length">
                             <h4 class="text-xs uppercase font-bold text-[var(--text-muted)] mb-2 tracking-wider">Tags</h4>
                             <div class="flex flex-wrap gap-2">
                                <span v-for="tag in selectedVersion.tags" :key="tag" class="px-2.5 py-1 rounded-full bg-[var(--surface-secondary)] border border-[var(--border-default)] text-xs font-medium text-[var(--text-secondary)]">
                                    {{ tag }}
                                </span>
                             </div>
                        </div>
                        
                         <!-- Body -->
                        <div class="prose max-w-none dark:prose-invert">
                            <h4 class="text-xs uppercase font-bold text-[var(--text-muted)] mb-3 tracking-wider">Content</h4>
                            <div class="p-6 border border-[var(--border-default)] rounded-xl bg-[var(--surface-elevated)] shadow-sm text-[var(--text-primary)] min-h-[200px]" v-html="selectedVersion.content"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Empty State -->
            <div v-else class="flex-1 flex flex-col items-center justify-center p-8 text-center text-[var(--text-muted)]">
                <div class="w-16 h-16 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mb-4">
                    <Eye class="w-8 h-8 opacity-50" />
                </div>
                <h3 class="text-lg font-medium text-[var(--text-primary)] mb-1">Select a version</h3>
                <p class="max-w-xs mx-auto">Click on a version from the history list to preview its content and details.</p>
            </div>
        </div>

    </div>
</template>
