<script setup lang="ts">
import { Icon } from "@/components/ui";

defineProps<{
    title: string;
    description: string;
}>();
</script>

<template>
    <div class="locked-feature-container">
        <div class="locked-content">
            <div class="lock-icon-wrapper">
                <Icon name="Lock" :size="48" />
            </div>
            <h2>{{ title }}</h2>
            <p>{{ description }}</p>
            <div class="impersonation-badge">
                <Icon name="UserCheck" :size="16" />
                <span>Impersonation Active</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.locked-feature-container {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    width: 100%;
    min-height: 400px;
    background: var(--surface-primary);
    color: var(--text-primary);
}

.locked-content {
    text-align: center;
    max-width: 400px;
    padding: 2rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.5rem;
}

.lock-icon-wrapper {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: var(--surface-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--interactive-primary);
    margin-bottom: 0.5rem;
}

h2 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
}

p {
    color: var(--text-secondary);
    line-height: 1.6;
    margin: 0;
}

.impersonation-badge {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--interactive-primary-subtle);
    color: var(--interactive-primary);
    border-radius: 2rem;
    font-size: 0.875rem;
    font-weight: 600;
    margin-top: 1rem;
}
</style>
