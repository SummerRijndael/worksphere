<script setup lang="ts">
import { computed, ref } from "vue";
import Avatar from "@/components/ui/Avatar.vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";
import Button from "@/components/ui/Button.vue";
import { format, startOfWeek, addDays, isSameDay, parseISO } from "date-fns";
import { Filter, UserPlus, ChevronLeft, ChevronRight } from "lucide-vue-next";

const props = defineProps<{
    tasks: any[];
    members: any[];
}>();

console.log('WorkloadTab Init:', { tasks: props.tasks, members: props.members });

// Date Navigation
const today = new Date();
const currentWeekStart = ref(startOfWeek(today, { weekStartsOn: 1 })); // Monday
const viewMode = ref("Week"); // Day, Week, Month

const weekDays = computed(() => {
    return Array.from({ length: 5 }, (_, i) => addDays(currentWeekStart.value, i));
});

const formatDate = (date: Date) => format(date, "MMM d");
const formatDay = (date: Date) => format(date, "EEE");

const nextWeek = () => {
    currentWeekStart.value = addDays(currentWeekStart.value, 7);
};

const prevWeek = () => {
    currentWeekStart.value = addDays(currentWeekStart.value, -7);
};

// Workload Calculations
// Simplified: Capacity = 5 tasks per day per person
const DAILY_CAPACITY = 5;

const workloadData = computed(() => {
    return props.members.map(member => {
        const memberTasks = props.tasks.filter(t => 
            t.members?.some((m: any) => m.id === member.id || m.public_id === member.public_id) || 
            (t.assignees && t.assignees.some((m: any) => m.id === member.id))
        );

        const dailyLoad = weekDays.value.map(day => {
            const tasksOnDay = memberTasks.filter(t => {
                if (!t.due_date) return false;
                return isSameDay(parseISO(t.due_date), day);
            });
            const loadPercent = Math.min((tasksOnDay.length / DAILY_CAPACITY) * 100, 100);
            
            return {
                date: day,
                tasks: tasksOnDay,
                count: tasksOnDay.length,
                percent: loadPercent,
                status: loadPercent > 100 ? 'overloaded' : (loadPercent > 80 ? 'heavy' : 'optimal')
            };
        });

        // Total for the week (avg capacity used)
        const totalTasks = dailyLoad.reduce((acc, d) => acc + d.count, 0);
        const avgCapacity = Math.round((totalTasks / (DAILY_CAPACITY * 5)) * 100);
        
        // Status determination
        let status = 'Standard';
        if (avgCapacity > 100) status = 'Overloaded';
        else if (avgCapacity > 75) status = 'Heavy';
        else if (avgCapacity < 30) status = 'Light';
        else status = 'Optimal';

        return {
            member,
            dailyLoad,
            totalTasks,
            avgCapacity,
            status
        };
    });
});

const stats = computed(() => {
    const totalMembers = props.members.length || 1;
    const overloaded = workloadData.value.filter(m => m.avgCapacity > 100).length;
    
    // Total team capacity usage
    const totalCapacity = Math.round(workloadData.value.reduce((acc, m) => acc + m.avgCapacity, 0) / totalMembers);
    
    const unassignedTasks = props.tasks.filter(t => (!t.members || t.members.length === 0)).length;

    return {
        totalCapacity,
        overloaded,
        unassignedTasks
    };
});

const getBarColor = (percent: number) => {
    if (percent > 100) return 'bg-red-500';
    if (percent > 80) return 'bg-amber-500';
    return 'bg-blue-500';
};

const getStatusColor = (status: string) => {
    switch (status.toLowerCase()) {
        case 'overloaded': return 'text-red-500';
        case 'heavy': return 'text-amber-500';
        case 'optimal': return 'text-emerald-500';
        default: return 'text-blue-500';
    }
};

</script>

<template>
    <div class="space-y-6">
        <!-- Header Stats -->
        <div class="flex flex-col md:flex-row gap-4 mb-6">
            <div class="flex items-center justify-between flex-1">
                <div>
                    <h2 class="text-2xl font-bold text-[var(--text-primary)]">Team Workload</h2>
                    <p class="text-[var(--text-secondary)] text-sm flex items-center gap-2 mt-1">
                        <span class="inline-block w-2 h-2 rounded-full bg-[var(--text-secondary)]"></span>
                        {{ formatDate(weekDays[0]) }} - {{ formatDate(weekDays[4]) }}
                    </p>
                </div>
                <div class="flex gap-2">
                     <Button variant="outline" size="sm" class="gap-2">
                        <Filter class="w-4 h-4" /> Filter
                     </Button>
                     <!-- future: add member -->
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <Card class="p-6 bg-[var(--surface-secondary)] border-[var(--border-subtle)]">
                <h3 class="text-xs font-bold text-[var(--text-secondary)] uppercase tracking-wider mb-2">Total Capacity</h3>
                <div class="flex items-end gap-2 mb-4">
                    <span class="text-4xl font-bold text-[var(--text-primary)]">{{ stats.totalCapacity }}%</span>
                    <span class="text-sm text-emerald-500 mb-1 font-medium" v-if="stats.totalCapacity <= 80">Optimal</span>
                    <span class="text-sm text-red-500 mb-1 font-medium" v-else>High Load</span>
                </div>
                <div class="h-1.5 w-full bg-[var(--surface-tertiary)] rounded-full overflow-hidden">
                    <div class="h-full transition-all duration-500" :class="getBarColor(stats.totalCapacity)" :style="{ width: `${Math.min(stats.totalCapacity, 100)}%` }"></div>
                </div>
            </Card>

            <Card class="p-6 bg-[var(--surface-secondary)] border-[var(--border-subtle)]">
                 <h3 class="text-xs font-bold text-[var(--text-secondary)] uppercase tracking-wider mb-2">Overloaded Members</h3>
                 <div class="flex items-end gap-2 mb-1">
                    <span class="text-3xl font-bold" :class="stats.overloaded > 0 ? 'text-red-500' : 'text-[var(--text-primary)]'">
                        {{ stats.overloaded }}
                    </span>
                 </div>
                 <p class="text-xs text-[var(--text-secondary)]">
                     {{ stats.overloaded > 0 ? 'Requires immediate attention' : 'All workloads are balanced' }}
                 </p>
            </Card>

            <Card class="p-6 bg-[var(--surface-secondary)] border-[var(--border-subtle)]">
                 <h3 class="text-xs font-bold text-[var(--text-secondary)] uppercase tracking-wider mb-2">Unassigned Tasks</h3>
                 <div class="flex items-end gap-2 mb-1">
                    <span class="text-3xl font-bold text-[var(--text-primary)]">
                        {{ stats.unassignedTasks }}
                    </span>
                 </div>
                 <p class="text-xs text-[var(--text-secondary)]">Tasks in backlog</p>
            </Card>
        </div>

        <!-- Workload Table/Grid -->
        <Card class="overflow-hidden border-[var(--border-subtle)]">
            <!-- Toolbar -->
            <div class="p-4 border-b border-[var(--border-subtle)] flex items-center justify-between">
                <div class="flex gap-2">
                    <Button variant="ghost" size="icon" @click="prevWeek">
                        <ChevronLeft class="w-4 h-4" />
                    </Button>
                    <Button variant="ghost" size="icon" @click="nextWeek">
                        <ChevronRight class="w-4 h-4" />
                    </Button>
                </div>
                <!-- View Switcher -->
                <div class="flex bg-[var(--surface-tertiary)] p-1 rounded-lg">
                    <button class="px-3 py-1 text-xs font-medium rounded-md bg-[var(--interactive-primary)] text-white shadow-sm">Week</button>
                    <!-- other modes disabled for now -->
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[800px]">
                    <thead>
                        <tr class="border-b border-[var(--border-subtle)]">
                            <th class="text-left py-3 px-4 text-xs font-medium text-[var(--text-secondary)] w-1/4">TEAM MEMBER</th>
                             <th v-for="day in weekDays" :key="day.toString()" class="text-left py-3 px-4 text-xs font-medium text-[var(--text-secondary)] w-[15%]">
                                <span class="uppercase">{{ formatDay(day) }}</span> {{ formatDate(day) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border-subtle)]" v-if="workloadData.length > 0">
                        <tr v-for="data in workloadData" :key="data.member.id" class="group hover:bg-[var(--surface-tertiary)]/30 transition-colors">
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <Avatar :name="data.member.name" :src="data.member.avatar_url" />
                                    <div>
                                        <div class="font-medium text-[var(--text-primary)] text-sm mb-0.5">{{ data.member.name }}</div>
                                        <div class="text-[10px] uppercase font-bold tracking-wide" :class="getStatusColor(data.status)">
                                            {{ data.status }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <!-- Days -->
                            <td v-for="(dayData, idx) in data.dailyLoad" :key="idx" class="p-4 align-top">
                                <div class="space-y-2">
                                    <!-- Progress Bar -->
                                    <div class="flex items-center justify-between text-[10px] mb-1">
                                        <span class="text-[var(--text-secondary)] font-medium">{{ dayData.percent.toFixed(0) }}%</span> 
                                    </div>
                                    <div class="h-1.5 w-full bg-[var(--surface-tertiary)] rounded-full overflow-hidden mb-3">
                                         <div class="h-full rounded-full" :class="getBarColor(dayData.percent)" :style="{ width: `${dayData.percent}%` }"></div>
                                    </div>

                                    <!-- Tasks Preview -->
                                    <div v-if="dayData.tasks.length > 0" class="flex flex-col gap-1.5">
                                        <div v-for="task in dayData.tasks.slice(0, 2)" :key="task.id" 
                                            class="bg-[var(--surface-tertiary)] border border-[var(--border-subtle)] rounded px-2 py-1.5 text-[10px] text-[var(--text-secondary)] truncate hover:text-[var(--text-primary)] cursor-pointer hover:border-[var(--interactive-primary)]/50 transition-colors">
                                            {{ task.name }}
                                        </div>
                                        <div v-if="dayData.tasks.length > 2" class="text-[10px] text-[var(--text-muted)] pl-1">
                                            +{{ dayData.tasks.length - 2 }} more
                                        </div>
                                    </div>
                                    <div v-else class="h-6 border-2 border-dashed border-[var(--border-subtle)]/30 rounded flex items-center justify-center">
                                       <!-- Empty slot placeholder -->
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tbody v-else>
                        <tr>
                            <td colspan="6" class="p-12 text-center text-[var(--text-secondary)]">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-12 h-12 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center">
                                        <UserPlus class="w-6 h-6 text-[var(--text-muted)]" />
                                    </div>
                                    <p class="font-medium">No team members found</p>
                                    <p class="text-sm text-[var(--text-muted)]">Add members to this project to see their workload.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
</template>
