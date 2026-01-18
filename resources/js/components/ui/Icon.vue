<script setup>
import { computed, defineAsyncComponent } from 'vue';
import * as icons from 'lucide-vue-next';
import { cn } from '@/lib/utils';

const props = defineProps({
    name: {
        type: String,
        required: true,
    },
    size: {
        type: [String, Number],
        default: 20,
    },
    strokeWidth: {
        type: Number,
        default: 2,
    },
});

// Convert kebab-case to PascalCase for lucide icons
function toPascalCase(str) {
    return str
        .split('-')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join('');
}

const iconComponent = computed(() => {
    const pascalName = toPascalCase(props.name);
    return icons[pascalName] || icons['HelpCircle'];
});

const iconSize = computed(() => {
    if (typeof props.size === 'number') return props.size;
    const sizes = {
        xs: 14,
        sm: 16,
        md: 20,
        lg: 24,
        xl: 32,
    };
    return sizes[props.size] || 20;
});
</script>

<template>
    <component
        :is="iconComponent"
        :size="iconSize"
        :stroke-width="strokeWidth"
        class="shrink-0"
    />
</template>
