<script setup lang="ts">
import { ref, computed } from "vue";
import { useAuthStore } from "@/stores/auth";
import { Button, Card } from "@/components/ui";
import { FileIcon, Trash2, UploadCloud, Download, Loader2 } from "lucide-vue-next";
import axios from "axios";
import { toast } from "vue-sonner";

const props = defineProps<{
    task: any;
}>();

const emit = defineEmits(["task-updated"]);

const authStore = useAuthStore();
const isUploading = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
const deletingId = ref<number | null>(null);

const attachments = computed(() => props.task.attachments || []);

const triggerUpload = () => {
    fileInput.value?.click();
};

const handleFileSelect = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    if (!input.files?.length) return;

    const file = input.files[0];
    await uploadFile(file);
    
    // Reset input
    input.value = "";
};

const uploadFile = async (file: File) => {
    if (!props.task) return;
    
    // Size check (e.g. 50MB)
    if (file.size > 50 * 1024 * 1024) {
        toast.error("File is too large (Max 50MB)");
        return;
    }

    isUploading.value = true;
    const formData = new FormData();
    formData.append("file", file);

    try {
        const teamId = props.task.project?.team_id || props.task.project?.team?.id;
        const projectId = props.task.project_id || props.task.project?.id;
        const taskId = props.task.public_id || props.task.id;

        await axios.post(
            `/api/teams/${teamId}/projects/${projectId}/tasks/${taskId}/files`,
            formData,
            {
                headers: {
                    "Content-Type": "multipart/form-data",
                },
            }
        );

        toast.success("File uploaded");
        emit("task-updated"); 
    } catch (error: any) {
        console.error("Upload failed", error);
        toast.error(error.response?.data?.message || "Upload failed");
    } finally {
        isUploading.value = false;
    }
};

const deleteFile = async (attachment: any) => {
    if (!confirm("Are you sure you want to delete this file?")) return;

    deletingId.value = attachment.id;
    try {
        const teamId = props.task.project?.team_id || props.task.project?.team?.id;
        const projectId = props.task.project_id || props.task.project?.id;
        const taskId = props.task.public_id || props.task.id;

        await axios.delete(
            `/api/teams/${teamId}/projects/${projectId}/tasks/${taskId}/files/${attachment.id}`
        );

        toast.success("File deleted");
        emit("task-updated");
    } catch (error: any) {
        console.error("Delete failed", error);
        toast.error(error.response?.data?.message || "Delete failed");
    } finally {
        deletingId.value = null;
    }
};

const formatSize = (bytes: number) => {
    if (bytes === 0) return "0 Bytes";
    const k = 1024;
    const sizes = ["Bytes", "KB", "MB", "GB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
};
</script>

<template>
    <div class="space-y-4">
        <!-- Header / Upload -->
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-medium text-[var(--text-secondary)]">
                Files ({{ attachments.length }})
            </h3>
            <div>
                <input
                    ref="fileInput"
                    type="file"
                    class="hidden"
                    @change="handleFileSelect"
                />
                <Button variant="outline" size="sm" @click="triggerUpload" :disabled="isUploading">
                    <Loader2 v-if="isUploading" class="w-4 h-4 mr-2 animate-spin" />
                    <UploadCloud v-else class="w-4 h-4 mr-2" />
                    Upload File
                </Button>
            </div>
        </div>

        <!-- File List -->
        <div v-if="attachments.length > 0" class="grid gap-2">
            <div
                v-for="file in attachments"
                :key="file.id"
                class="flex items-center justify-between p-3 rounded-lg border border-[var(--border-subtle)] bg-[var(--surface-primary)] hover:border-[var(--border-default)] transition-all group"
            >
                <div class="flex items-center gap-3 overflow-hidden">
                    <div class="p-2 bg-[var(--surface-secondary)] rounded-md">
                        <FileIcon class="w-5 h-5 text-[var(--text-secondary)]" />
                    </div>
                    <div class="flex flex-col min-w-0">
                        <span class="text-sm font-medium text-[var(--text-primary)] truncate">
                            {{ file.file_name }}
                        </span>
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ formatSize(file.size) }} • {{ new Date(file.created_at).toLocaleDateString() }}
                        </span>
                    </div>
                </div>

                <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                    <a
                        :href="file.url"
                        target="_blank"
                        class="p-1.5 text-[var(--text-muted)] hover:text-[var(--interactive-primary)] rounded-md hover:bg-[var(--surface-tertiary)] transition-colors"
                        title="Download"
                    >
                        <Download class="w-4 h-4" />
                    </a>
                    
                    <button
                        @click="deleteFile(file)"
                        class="p-1.5 text-[var(--text-muted)] hover:text-red-500 rounded-md hover:bg-[var(--surface-tertiary)] transition-colors"
                        title="Delete"
                        :disabled="deletingId === file.id"
                    >
                        <Loader2 v-if="deletingId === file.id" class="w-4 h-4 animate-spin" />
                        <Trash2 v-else class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>

        <div v-else class="text-center py-8 border-2 border-dashed border-[var(--border-subtle)] rounded-lg">
            <div class="flex justify-center mb-2">
                 <UploadCloud class="w-8 h-8 text-[var(--text-muted)] opacity-50" />
            </div>
            <p class="text-sm text-[var(--text-muted)]">No files attached</p>
        </div>
    </div>
</template>
