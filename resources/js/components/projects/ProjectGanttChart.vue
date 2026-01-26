<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import Gantt from "frappe-gantt";
import "@/../css/frappe-gantt.css"; // Relative from components/projects to resources/css? No, alias @ is resources/js usually.
import { Button } from "@/components/ui";
import { Calendar, ChevronLeft, ChevronRight } from "lucide-vue-next";

const props = defineProps<{
    tasks: any[];
}>();

const emit = defineEmits(["task-click"]);

const ganttRef = ref<HTMLElement | null>(null);
const ganttInstance = ref<any>(null);
const currentViewMode = ref("Week");

const viewModes = ["Quarter Day", "Half Day", "Day", "Week", "Month"];

onMounted(() => {
    initGantt();
});

watch(
    () => props.tasks,
    () => {
        initGantt();
    },
    { deep: true }
);

watch(currentViewMode, (newMode) => {
    if (ganttInstance.value) {
        ganttInstance.value.change_view_mode(newMode);
    }
});

const initGantt = () => {
    if (!ganttRef.value || !props.tasks.length) return;

    // Transform tasks for frappe-gantt
    const ganttTasks = props.tasks.map((task) => {
        const startDate = task.started_at
            ? new Date(task.started_at)
            : task.created_at
            ? new Date(task.created_at)
            : new Date();
            
        let endDate = task.due_date ? new Date(task.due_date) : null;
        
        // If no end date, default to start date + 1 day or similar
        // Or if start > end, clamp
        if (!endDate || endDate < startDate) {
             const nextDay = new Date(startDate);
             nextDay.setDate(nextDay.getDate() + 1);
             endDate = nextDay;
        }

        // Calculate progress based on status if not explicit
        let progress = 0;
        if (task.status === "completed") progress = 100;
        else if (task.status === "in_progress") progress = 50;
        else if (task.status === "in_qa") progress = 80;
        // Or use checklist completion if available
        
        // Ensure valid date strings YYYY-MM-DD
        const startStr = startDate.toISOString().split('T')[0];
        const endStr = endDate.toISOString().split('T')[0];

        return {
            id: task.public_id || task.id,
            name: task.title,
            start: startStr,
            end: endStr,
            progress: progress,
            dependencies: task.parent_id ? String(task.parent_id) : "",
            custom_class: `gantt-task-${task.status?.value?.replace('_', '-') || 'default'}`, // for custom styling per status
        };
    });

    ganttInstance.value = new Gantt(ganttRef.value, ganttTasks, {
        header_height: 50,
        column_width: 30,
        step: 24,
        view_modes: viewModes,
        bar_height: 25,
        bar_corner_radius: 3,
        arrow_curve: 5,
        padding: 18,
        view_mode: currentViewMode.value,
        date_format: "YYYY-MM-DD",
        custom_popup_html: function (task: any) {
            // Function to return custom tooltip HTML
            return `
                <div class="gantt-tooltip-content p-3 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 min-w-[200px]">
                    <div class="font-bold text-sm text-gray-900 dark:text-gray-100 mb-1">${task.name}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        ${task.start} - ${task.end}
                    </div>
                    <div class="mt-2 flex items-center justify-between">
                         <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Progress</span>
                         <span class="text-xs font-bold text-blue-600 dark:text-blue-400">${task.progress}%</span>
                    </div>
                </div>
            `;
        },
        on_click: (task: any) => {
            emit("task-click", task);
        },
        on_date_change: (task: any, start: Date, end: Date) => {
            console.log(task, start, end);
            // Optional: emit event to update task dates
        },
        on_progress_change: (task: any, progress: number) => {
            console.log(task, progress);
            // Optional: emit event to update progress
        },
        on_view_change: (mode: string) => {
            currentViewMode.value = mode;
        },
    });
};
</script>

<template>
    <div class="space-y-4">
        <!-- Gantt Controls -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2 bg-[var(--surface-secondary)] p-1 rounded-lg border border-[var(--border-default)]">
                <Button
                    v-for="mode in viewModes"
                    :key="mode"
                    variant="ghost"
                    size="sm"
                    class="px-3 h-8 text-xs font-medium transition-all"
                    :class="{ 
                        'bg-white dark:bg-[var(--surface-tertiary)] shadow-sm text-[var(--interactive-primary)]': currentViewMode === mode,
                        'text-[var(--text-secondary)] hover:text-[var(--text-primary)]': currentViewMode !== mode
                    }"
                    @click="currentViewMode = mode"
                >
                    {{ mode }}
                </Button>
            </div>
        </div>

        <!-- Gantt Container -->
        <div class="overflow-x-auto rounded-xl border border-[var(--border-default)] bg-[var(--surface-primary)]">
            <div ref="ganttRef" class="w-full min-w-[800px] min-h-[400px]"></div>
        </div>
        
        <div v-if="!tasks.length" class="text-center py-12 text-[var(--text-muted)]">
             <Calendar class="w-12 h-12 mx-auto mb-3 opacity-20" />
             <p>No tasks to display in Gantt chart</p>
        </div>
    </div>
</template>

<style>
/* Basic adjustments for converting frappe-gantt styles to match theme vars if needed */
.gantt .bar-label {
    fill: var(--text-primary) !important;
    font-weight: 500;
}
.gantt .bar-wrapper.gantt-task-completed .bar {
    fill: #10b981 !important;
}
.gantt .bar-wrapper.gantt-task-in-progress .bar {
    fill: #3b82f6 !important;
}
.gantt .bar-wrapper.gantt-task-in-qa .bar {
    fill: #8b5cf6 !important;
}
.gantt .bar-wrapper.gantt-task-default .bar {
    fill: #6b7280 !important;
}
.gantt .bar-wrapper:hover .bar {
    opacity: 0.8;
}

/* Dark mode variable overrides for frappe-gantt */
:root.dark, .dark {
    --g-header-background: var(--surface-secondary); 
    --g-row-color: var(--surface-primary);
    --g-row-border-color: var(--border-default);
    --g-actions-background: var(--surface-secondary);
    --g-border-color: var(--border-default);
    --g-tick-color: var(--border-default);
    --g-tick-color-thick: var(--border-default);
    --g-text-dark: var(--text-primary);
    --g-text-light: var(--text-inverse);
    --g-text-muted: var(--text-secondary);
    --g-arrow-color: var(--text-secondary);
    --g-today-highlight: var(--interactive-primary);
    --g-weekend-highlight-color: rgba(255, 255, 255, 0.02);
}

/* Specific SVG fixes that vars might not catch if library hardcodes them or structure differs */
.dark .gantt .grid-row {
    fill: var(--surface-primary);
}
.dark .gantt .grid-row:nth-child(even) {
    fill: var(--surface-secondary);
}
.dark .gantt .grid-header {
    background-color: var(--surface-secondary);
    fill: var(--surface-secondary);
}
.dark .gantt text {
    fill: var(--text-secondary);
}
.dark .gantt .upper-text {
    fill: var(--text-primary);
}
.dark .gantt .lower-text {
    fill: var(--text-secondary);
}
.dark .gantt-container {
    background-color: var(--surface-primary);
}
</style>
