<script setup lang="ts">
import { cn } from '@/lib/utils';

interface Props {
    orientation?: 'horizontal' | 'vertical';
    decorative?: boolean;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    orientation: 'horizontal',
    decorative: true,
});
</script>

<template>
    <div
        :class="cn(
            'shrink-0 bg-[var(--border-subtle)]',
            props.orientation === 'horizontal' ? 'h-[1px] w-full' : 'h-full w-[1px]',
            props.class
        )"
        role="separator"
        :aria-orientation="props.orientation === 'horizontal' ? 'horizontal' : 'vertical'"
    />
</template>
