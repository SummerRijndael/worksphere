<script setup lang="ts">
import { ref } from "vue";
import Modal from "@/components/ui/Modal.vue";
import Button from "@/components/ui/Button.vue";

import Checkbox from "@/components/ui/Checkbox.vue";

const props = defineProps<{
    show: boolean;
}>();

const emit = defineEmits(["close", "export"]);

// _includeSearch removed
const selectedStatuses = ref<string[]>([
    "open",
    "in_progress",
    "resolved",
    "closed",
]);

const statuses = [
    { value: "open", label: "Open" },
    { value: "in_progress", label: "In Progress" },
    { value: "resolved", label: "Resolved" },
    { value: "closed", label: "Closed" },
];

const toggleStatus = (value: string) => {
    if (selectedStatuses.value.includes(value)) {
        selectedStatuses.value = selectedStatuses.value.filter(
            (s) => s !== value
        );
    } else {
        selectedStatuses.value.push(value);
    }
};

const handleExport = () => {
    emit("export", {
        status: selectedStatuses.value.join(","),
        // If includeSearch is false, we might want to tell parent to ignore its filters?
        // Composable handles merging. If user wants to "Ignore Global Filters", that's complex.
        // Usually "Export" means "Export what I see" or "Export everything".
        // Let's keep it simple: "Export based on current filters" is implicit.
        // Modal is just for EXTRA filtering (e.g. limit status).
    });
    emit("close");
};
</script>

<template>
    <Modal
        :show="show"
        @close="$emit('close')"
        title="Export Ticket Report"
        maxWidth="md"
    >
        <div class="space-y-6 py-2">
            <div>
                <label
                    class="mb-2 block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                    >Status to Include</label
                >
                <div class="space-y-2">
                    <div
                        v-for="status in statuses"
                        :key="status.value"
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :checked="selectedStatuses.includes(status.value)"
                            @update:checked="toggleStatus(status.value)"
                            :id="`status-${status.value}`"
                        />
                        <label
                            :for="`status-${status.value}`"
                            class="text-sm text-zinc-700 dark:text-zinc-300 cursor-pointer"
                        >
                            {{ status.label }}
                        </label>
                    </div>
                </div>
            </div>

            <div
                class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg text-sm text-blue-700 dark:text-blue-300"
            >
                <p>
                    The export will include tickets matching the selected
                    statuses and any active date or search filters.
                </p>
                <p class="mt-1 font-semibold">Format: Excel (.xlsx)</p>
            </div>
        </div>

        <template #footer>
            <div class="flex justify-end gap-3">
                <Button variant="ghost" @click="$emit('close')">Cancel</Button>
                <Button @click="handleExport">Generate Export</Button>
            </div>
        </template>
    </Modal>
</template>
