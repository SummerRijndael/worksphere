<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import Gantt from "frappe-gantt";
import "@/../css/frappe-gantt.css"; // Relative from components/projects to resources/css? No, alias @ is resources/js usually.
import { Button } from "@/components/ui";
import { Calendar } from "lucide-vue-next";

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
    { deep: true },
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

        if (!endDate || endDate < startDate) {
            const nextDay = new Date(startDate);
            nextDay.setDate(nextDay.getDate() + 1);
            endDate = nextDay;
        }

        let progress = 0;
        if (task.status === "completed") progress = 100;
        else if (task.status === "in_progress") progress = 50;
        else if (task.status === "in_qa") progress = 80;

        const startStr = startDate.toISOString().split("T")[0];
        const endStr = endDate.toISOString().split("T")[0];

        // Map status to simpler identifier for CSS
        const statusKey = task.status?.value || task.status || "default";

        return {
            id: task.public_id || task.id,
            name: task.title,
            start: startStr,
            end: endStr,
            progress: progress,
            dependencies: task.parent_id ? String(task.parent_id) : "",
            custom_class: `gantt-task-${statusKey.replace("_", "-")}`,
        };
    });

    // Dynamic configuration based on view mode
    const config = {
        "Quarter Day": { column_width: 30, step: 6 },
        "Half Day": { column_width: 45, step: 12 },
        Day: { column_width: 80, step: 24 },
        Week: { column_width: 140, step: 24 },
        Month: { column_width: 250, step: 24 },
    }[currentViewMode.value] || { column_width: 140, step: 24 };

    ganttInstance.value = new Gantt(ganttRef.value, ganttTasks, {
        header_height: 60,
        column_width: config.column_width,
        step: config.step,
        view_modes: viewModes,
        bar_height: 32,
        bar_corner_radius: 6,
        arrow_curve: 8,
        padding: 20,
        view_mode: currentViewMode.value,
        date_format: "YYYY-MM-DD",
        custom_popup_html: function (task: any) {
            return `
                <div class="gantt-tooltip-content p-4 bg-[var(--surface-elevated)] backdrop-blur-md rounded-xl shadow-2xl border border-[var(--border-default)] min-w-[240px] z-[2000]">
                    <div class="font-bold text-base text-[var(--text-primary)] mb-1.5">${task.name}</div>
                    <div class="flex items-center gap-2 text-xs text-[var(--text-secondary)] mb-3">
                        <span class="px-1.5 py-0.5 bg-[var(--surface-secondary)] rounded border border-[var(--border-default)]">${task.start}</span>
                        <span>→</span>
                        <span class="px-1.5 py-0.5 bg-[var(--surface-secondary)] rounded border border-[var(--border-default)]">${task.end}</span>
                    </div>
                    <div class="space-y-2">
                         <div class="flex items-center justify-between text-xs">
                             <span class="text-[var(--text-secondary)] font-medium">Completion</span>
                             <span class="font-bold text-[var(--interactive-primary)]">${task.progress}%</span>
                         </div>
                         <div class="w-full h-1.5 bg-[var(--surface-tertiary)] rounded-full overflow-hidden">
                             <div class="h-full bg-[var(--interactive-primary)] transition-all duration-500" style="width: ${task.progress}%"></div>
                         </div>
                    </div>
                </div>
            `;
        },
        on_click: (task: any) => {
            emit("task-click", task);
        },
        on_view_change: (mode: string) => {
            currentViewMode.value = mode;
        },
    });
};
</script>

<template>
    <div class="space-y-6">
        <!-- Gantt Controls -->
        <div
            class="flex items-center justify-between px-4 py-2 bg-[var(--surface-secondary)] rounded-2xl border border-[var(--border-default)]"
        >
            <div class="flex items-center gap-3">
                <div
                    class="p-2 bg-[var(--surface-primary)] rounded-lg border border-[var(--border-default)]"
                >
                    <Calendar
                        class="w-5 h-5 text-[var(--interactive-primary)]"
                    />
                </div>
                <div>
                    <h3 class="text-sm font-bold text-[var(--text-primary)]">
                        Timeline
                    </h3>
                    <p
                        class="text-[10px] text-[var(--text-secondary)] uppercase tracking-wider font-semibold"
                    >
                        Project Schedule
                    </p>
                </div>
            </div>

            <div
                class="flex items-center gap-1.5 bg-[var(--surface-primary)] p-1.5 rounded-xl border border-[var(--border-default)] shadow-sm"
            >
                <Button
                    v-for="mode in viewModes"
                    :key="mode"
                    variant="ghost"
                    size="sm"
                    class="px-3 h-8 text-[11px] font-bold transition-all rounded-lg"
                    :class="{
                        'bg-[var(--interactive-primary)] text-white shadow-md hover:bg-[var(--interactive-primary-hover)]':
                            currentViewMode === mode,
                        'text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)]':
                            currentViewMode !== mode,
                    }"
                    @click="currentViewMode = mode"
                >
                    {{ mode }}
                </Button>
            </div>
        </div>

        <!-- Gantt Container -->
        <div
            class="relative overflow-hidden rounded-2xl border border-[var(--border-default)] bg-[var(--surface-primary)] shadow-xl lg:min-h-[500px]"
        >
            <div
                class="absolute inset-0 bg-gradient-to-br from-[var(--surface-primary)] to-[var(--surface-secondary)] opacity-50 pointer-events-none"
            ></div>
            <div class="overflow-x-auto relative z-10 custom-scrollbar">
                <div
                    ref="ganttRef"
                    class="w-full min-w-[1200px] p-6 pb-12"
                ></div>
            </div>

            <div
                v-if="!tasks.length"
                class="absolute inset-0 flex flex-col items-center justify-center bg-[var(--surface-primary)]/80 backdrop-blur-sm z-20"
            >
                <div
                    class="p-6 bg-[var(--surface-secondary)] rounded-full mb-4 border border-[var(--border-default)]"
                >
                    <Calendar
                        class="w-12 h-12 text-[var(--text-muted)] opacity-40"
                    />
                </div>
                <p class="text-lg font-bold text-[var(--text-primary)]">
                    No tasks scheduled
                </p>
                <p class="text-sm text-[var(--text-secondary)] mt-1">
                    Add tasks with due dates to see them here
                </p>
            </div>
        </div>
    </div>
</template>

<style>
/* Gantt Bar Customizations with Status Gradients */
.gantt .bar-wrapper .bar {
    stroke-width: 0 !important;
}

.gantt .bar-label {
    fill: var(--text-primary) !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.gantt .bar-progress {
    fill-opacity: 0.9;
}

/* Status-based styles */
.gantt .bar-wrapper.gantt-task-completed .bar {
    fill: #34d399 !important;
}
.gantt .bar-wrapper.gantt-task-completed .bar-progress {
    fill: #059669 !important;
}

.gantt .bar-wrapper.gantt-task-in-progress .bar {
    fill: #60a5fa !important;
}
.gantt .bar-wrapper.gantt-task-in-progress .bar-progress {
    fill: #2563eb !important;
}

.gantt .bar-wrapper.gantt-task-in-qa .bar {
    fill: #a78bfa !important;
}
.gantt .bar-wrapper.gantt-task-in-qa .bar-progress {
    fill: #7c3aed !important;
}

.gantt .bar-wrapper.gantt-task-on-hold .bar {
    fill: #fbbf24 !important;
}
.gantt .bar-wrapper.gantt-task-on-hold .bar-progress {
    fill: #d97706 !important;
}

.gantt .bar-wrapper.gantt-task-cancelled .bar {
    fill: #f87171 !important;
}
.gantt .bar-wrapper.gantt-task-cancelled .bar-progress {
    fill: #dc2626 !important;
}

.gantt .bar-wrapper .bar-label.big {
    fill: var(--text-secondary) !important;
    font-weight: 500 !important;
}

/* Grid & Layout Polishing */
.gantt .grid-header {
    fill: var(--surface-secondary) !important;
    stroke: var(--border-default) !important;
}

.gantt .upper-text {
    fill: var(--text-primary) !important;
    font-weight: 700 !important;
    font-size: 13px !important;
}

.gantt .lower-text {
    fill: var(--text-secondary) !important;
    font-weight: 500 !important;
    font-size: 11px !important;
}

.gantt .grid-row {
    fill: transparent !important;
}

.gantt .tick {
    stroke: var(--border-default) !important;
    stroke-opacity: 0.5;
}

.gantt .today-highlight {
    fill: var(--interactive-primary) !important;
    fill-opacity: 0.05 !important;
}

/* Dark mode variable overrides for frappe-gantt */
:root.dark,
.dark {
    --g-header-background: var(--surface-secondary);
    --g-row-color: transparent;
    --g-row-border-color: var(--border-default);
    --g-actions-background: var(--surface-secondary);
    --g-border-color: var(--border-default);
    --g-tick-color: var(--border-default);
    --g-tick-color-thick: var(--border-default);
    --g-text-dark: var(--text-primary);
    --g-text-light: var(--text-primary);
    --g-text-muted: var(--text-secondary);
    --g-arrow-color: var(--text-muted);
    --g-today-highlight: var(--interactive-primary);
    --g-weekend-highlight-color: rgba(255, 255, 255, 0.01);
}

.gantt .handle {
    fill: rgba(255, 255, 255, 0.3) !important;
}

.custom-scrollbar::-webkit-scrollbar {
    height: 8px;
    width: 8px;
}

.custom-scrollbar::-webkit-scrollbar-track {
    background: var(--surface-secondary);
    border-radius: 4px;
}

.custom-scrollbar::-webkit-scrollbar-thumb {
    background: var(--border-default);
    border-radius: 4px;
}

.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: var(--text-muted);
}
</style>
