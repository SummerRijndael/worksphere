<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useTicketReport } from '@/composables/useTicketReport';
import { useThemeStore } from '@/stores/theme';
import WorkloadChart from '@/components/reports/WorkloadChart.vue';
import StatusDistributionChart from '@/components/reports/StatusDistributionChart.vue';
import TicketListCard from '@/components/reports/TicketListCard.vue';
import TicketDataTable from '@/components/reports/TicketDataTable.vue';
import ExportModal from '@/components/reports/ExportModal.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import { Download, Search, TrendingUp, Clock, CheckCircle2, AlertTriangle } from 'lucide-vue-next';

const { stats, workload, filters, fetchStats, fetchWorkload, exportReport } = useTicketReport();
const themeStore = useThemeStore();
const { isDark } = storeToRefs(themeStore);

const showExportModal = ref(false);
let debounceTimer: any = null;

const debouncedFetch = () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        fetchStats();
        fetchWorkload();
    }, 500);
};

onMounted(() => {
    fetchStats();
    fetchWorkload();
});

watch(filters, () => {
    debouncedFetch();
}, { deep: true });
</script>

<template>
  <div class="min-h-screen bg-[var(--surface-base)] pb-12">
    <!-- Page Header -->
    <div class="bg-[var(--surface-elevated)] border-b border-[var(--border-default)] shadow-sm">
      <div class="px-6 py-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 class="text-3xl font-bold text-[var(--text-primary)] tracking-tight">Ticket Reports</h1>
            <p class="text-[var(--text-secondary)] mt-2">
              Comprehensive overview of ticket performance and team workload metrics
            </p>
          </div>
          <Button
            @click="showExportModal = true"
            variant="outline"
            class="shrink-0 shadow-sm hover:shadow"
          >
            <Download class="w-4 h-4 mr-2" />
            Export Report
          </Button>
        </div>
      </div>
    </div>

    <div class="px-6 py-8 space-y-8">
      <!-- Filters Card -->
      <Card class="shadow-sm border border-[var(--border-default)]" padding="lg">
        <div class="flex flex-col lg:flex-row gap-4">
          <!-- Search -->
          <div class="flex-1">
            <label class="block text-xs font-semibold text-[var(--text-secondary)] uppercase tracking-wide mb-2.5">
              Search Tickets
            </label>
            <div class="relative">
              <Search class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4.5 h-4.5 text-[var(--text-muted)] pointer-events-none" />
              <Input
                v-model="filters.search"
                placeholder="Search by ID, title, or assignee..."
                class="pl-11 pr-10 h-11 bg-[var(--surface-base)] border-[var(--border-default)] focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
              />
              <button
                v-if="filters.search"
                @click="filters.search = ''"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--text-muted)] hover:text-[var(--text-primary)] transition-colors"
                title="Clear search"
              >
                <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
            <div v-if="filters.search" class="mt-2 flex items-center gap-2 text-sm">
              <Badge variant="info" size="sm">
                <Search class="w-3 h-3 mr-1" />
                Active Search
              </Badge>
              <span class="text-[var(--text-secondary)]">
                Filtering by: <span class="font-medium text-[var(--text-primary)]">"{{ filters.search }}"</span>
              </span>
            </div>
          </div>

          <!-- Date Range -->
          <div class="flex items-end gap-3">
            <div class="min-w-[150px]">
              <label class="block text-xs font-semibold text-[var(--text-secondary)] uppercase tracking-wide mb-2.5">
                Date From
              </label>
              <Input
                type="date"
                v-model="filters.date_from"
                class="h-11 bg-[var(--surface-base)] border-[var(--border-default)]"
              />
            </div>
            <div class="flex items-center pb-2 text-[var(--text-muted)]">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
              </svg>
            </div>
            <div class="min-w-[150px]">
              <label class="block text-xs font-semibold text-[var(--text-secondary)] uppercase tracking-wide mb-2.5">
                Date To
              </label>
              <Input
                type="date"
                v-model="filters.date_to"
                class="h-11 bg-[var(--surface-base)] border-[var(--border-default)]"
              />
            </div>
            <button
              v-if="filters.date_from || filters.date_to"
              @click="filters.date_from = ''; filters.date_to = ''"
              class="mb-1.5 px-3 py-2.5 text-sm font-medium bg-[var(--surface-secondary)] text-[var(--text-secondary)] border border-[var(--border-default)] rounded-lg hover:bg-[var(--surface-tertiary)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/20 transition-colors whitespace-nowrap"
            >
              Clear Dates
            </button>
          </div>
        </div>

        <!-- Active Filters Summary -->
        <div v-if="filters.search || filters.date_from || filters.date_to" class="mt-4 pt-4 border-t border-[var(--border-default)] flex items-center gap-2 flex-wrap">
          <span class="text-sm font-medium text-[var(--text-secondary)]">Active Filters:</span>
          <Badge v-if="filters.search" variant="info" size="sm">
            Search: "{{ filters.search }}"
          </Badge>
          <Badge v-if="filters.date_from" variant="neutral" size="sm">
            From: {{ filters.date_from }}
          </Badge>
          <Badge v-if="filters.date_to" variant="neutral" size="sm">
            To: {{ filters.date_to }}
          </Badge>
        </div>
      </Card>

      <!-- Stats Overview -->
      <div v-if="stats" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
        <!-- Total Tickets -->
        <Card class="relative overflow-hidden border border-blue-200 dark:border-blue-800/50 shadow-sm hover:shadow-md transition-shadow" padding="lg">
          <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-blue-500/10 to-blue-600/5 rounded-full -mr-16 -mt-16"></div>
          <div class="relative">
            <div class="flex items-center justify-between mb-3">
              <div class="p-2.5 bg-blue-100 dark:bg-blue-900/30 rounded-xl">
                <TrendingUp class="w-5 h-5 text-blue-600 dark:text-blue-400" />
              </div>
              <Badge variant="info" size="sm">Total</Badge>
            </div>
            <h3 class="text-sm font-semibold text-blue-700 dark:text-blue-300 uppercase tracking-wide mb-1">
              Total Tickets
            </h3>
            <p class="text-3xl font-bold text-[var(--text-primary)] tabular-nums">
              {{ stats.total || 0 }}
            </p>
          </div>
        </Card>

        <!-- Resolved -->
        <Card class="relative overflow-hidden border border-emerald-200 dark:border-emerald-800/50 shadow-sm hover:shadow-md transition-shadow" padding="lg">
          <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-emerald-500/10 to-emerald-600/5 rounded-full -mr-16 -mt-16"></div>
          <div class="relative">
            <div class="flex items-center justify-between mb-3">
              <div class="p-2.5 bg-emerald-100 dark:bg-emerald-900/30 rounded-xl">
                <CheckCircle2 class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
              </div>
              <Badge variant="success" size="sm">Completed</Badge>
            </div>
            <h3 class="text-sm font-semibold text-emerald-700 dark:text-emerald-300 uppercase tracking-wide mb-1">
              Resolved
            </h3>
            <p class="text-3xl font-bold text-[var(--text-primary)] tabular-nums">
              {{ stats.resolved || 0 }}
            </p>
          </div>
        </Card>

        <!-- In Progress -->
        <Card class="relative overflow-hidden border border-amber-200 dark:border-amber-800/50 shadow-sm hover:shadow-md transition-shadow" padding="lg">
          <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-amber-500/10 to-amber-600/5 rounded-full -mr-16 -mt-16"></div>
          <div class="relative">
            <div class="flex items-center justify-between mb-3">
              <div class="p-2.5 bg-amber-100 dark:bg-amber-900/30 rounded-xl">
                <Clock class="w-5 h-5 text-amber-600 dark:text-amber-400" />
              </div>
              <Badge variant="warning" size="sm">Active</Badge>
            </div>
            <h3 class="text-sm font-semibold text-amber-700 dark:text-amber-300 uppercase tracking-wide mb-1">
              In Progress
            </h3>
            <p class="text-3xl font-bold text-[var(--text-primary)] tabular-nums">
              {{ stats.in_progress || 0 }}
            </p>
          </div>
        </Card>

        <!-- Unassigned -->
        <Card class="relative overflow-hidden border border-rose-200 dark:border-rose-800/50 shadow-sm hover:shadow-md transition-shadow" padding="lg">
          <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-rose-500/10 to-rose-600/5 rounded-full -mr-16 -mt-16"></div>
          <div class="relative">
            <div class="flex items-center justify-between mb-3">
              <div class="p-2.5 bg-rose-100 dark:bg-rose-900/30 rounded-xl">
                <AlertTriangle class="w-5 h-5 text-rose-600 dark:text-rose-400" />
              </div>
              <Badge variant="error" size="sm">Pending</Badge>
            </div>
            <h3 class="text-sm font-semibold text-rose-700 dark:text-rose-300 uppercase tracking-wide mb-1">
              Unassigned
            </h3>
            <p class="text-3xl font-bold text-[var(--text-primary)] tabular-nums">
              {{ stats.unassigned || 0 }}
            </p>
          </div>
        </Card>
      </div>

      <!-- Charts Section -->
      <div>
        <div class="flex items-center gap-2.5 mb-5">
          <div class="p-2 bg-[var(--accent)]/10 rounded-lg">
            <TrendingUp class="w-5 h-5 text-[var(--accent)]" />
          </div>
          <h2 class="text-xl font-bold text-[var(--text-primary)]">Analytics Overview</h2>
        </div>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <Card class="border border-[var(--border-default)] shadow-sm" padding="lg">
            <div class="flex items-center justify-between mb-6">
              <div>
                <h3 class="text-base font-bold text-[var(--text-primary)]">Team Workload</h3>
                <p class="text-sm text-[var(--text-secondary)] mt-1">Tickets assigned per team member</p>
              </div>
              <Badge variant="neutral" size="sm">Bar Chart</Badge>
            </div>
            <div class="min-h-[320px] bg-[var(--surface-base)] rounded-lg p-4 border border-[var(--border-default)]">
              <WorkloadChart :data="workload" :key="isDark ? 'dark' : 'light'" />
            </div>
          </Card>

          <Card class="border border-[var(--border-default)] shadow-sm" padding="lg">
            <div class="flex items-center justify-between mb-6">
              <div>
                <h3 class="text-base font-bold text-[var(--text-primary)]">Status Distribution</h3>
                <p class="text-sm text-[var(--text-secondary)] mt-1">Breakdown by ticket status</p>
              </div>
              <Badge variant="neutral" size="sm">Pie Chart</Badge>
            </div>
            <div class="min-h-[320px] bg-[var(--surface-base)] rounded-lg p-4 border border-[var(--border-default)] flex items-center justify-center">
              <StatusDistributionChart v-if="stats" :stats="stats" :key="isDark ? 'dark' : 'light'" />
              <div v-else class="text-[var(--text-muted)] flex items-center gap-2">
                <svg class="w-5 h-5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Loading statistics...
              </div>
            </div>
          </Card>
        </div>
      </div>

      <!-- Quick Lists Section -->
      <div>
        <div class="flex items-center gap-2.5 mb-5">
          <div class="p-2 bg-[var(--accent)]/10 rounded-lg">
            <svg class="w-5 h-5 text-[var(--accent)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
            </svg>
          </div>
          <h2 class="text-xl font-bold text-[var(--text-primary)]">Quick Access Lists</h2>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <TicketListCard
            title="Unassigned Tickets"
            :filters="{ assigned_to: 'unassigned', status: 'open,in_progress' }"
            :global-filters="filters"
          />
          <TicketListCard
            title="Open Tickets"
            :filters="{ status: 'open' }"
            :global-filters="filters"
          />
          <TicketListCard
            title="In Progress"
            :filters="{ status: 'in_progress' }"
            :global-filters="filters"
          />
        </div>
      </div>

      <!-- Data Table Section -->
      <div>
        <div class="flex items-center gap-2.5 mb-5">
          <div class="p-2 bg-[var(--accent)]/10 rounded-lg">
            <svg class="w-5 h-5 text-[var(--accent)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
          </div>
          <h2 class="text-xl font-bold text-[var(--text-primary)]">All Tickets</h2>
        </div>
        <TicketDataTable :filters="filters" />
      </div>
    </div>

    <ExportModal :show="showExportModal" @close="showExportModal = false" @export="exportReport" />
  </div>
</template>
