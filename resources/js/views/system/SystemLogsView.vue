<script setup>
import { ref } from 'vue';
import FileLogViewer from './FileLogViewer.vue';
import AuditLogTable from './AuditLogTable.vue';

const activeTab = ref('audit'); // 'audit' or 'application'

const tabs = [
    { id: 'audit', label: 'Audit Logs' },
    { id: 'application', label: 'Application Logs' }
];
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">System Logs</h1>
                <p class="text-[var(--text-secondary)]">View and analyze system activity and error logs.</p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="border-b border-[var(--border-default)]">
            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    :class="[
                        activeTab === tab.id
                            ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                            : 'border-transparent text-[var(--text-secondary)] hover:border-[var(--border-hover)] hover:text-[var(--text-primary)]',
                        'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors'
                    ]"
                    :aria-current="activeTab === tab.id ? 'page' : undefined"
                >
                    {{ tab.label }}
                </button>
            </nav>
        </div>

        <!-- Content -->
        <div>
            <Transition name="fade" mode="out-in">
                <AuditLogTable v-if="activeTab === 'audit'" />
                <FileLogViewer v-else-if="activeTab === 'application'" />
            </Transition>
        </div>
    </div>
</template>
