<script setup>
import { DropdownMenuItem } from 'reka-ui';
import { cn } from '@/lib/utils';

defineProps({
    disabled: Boolean,
    destructive: Boolean,
});

const emit = defineEmits(['select']);
</script>

<template>
    <DropdownMenuItem
        :disabled="disabled"
        :class="cn(
            'relative flex cursor-pointer select-none items-center gap-2 rounded-lg px-3 py-2 text-sm outline-none transition-colors',
            'data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
            destructive
                ? 'text-red-600 focus:bg-red-50 dark:focus:bg-red-900/20'
                : 'text-[var(--text-primary)] focus:bg-[var(--surface-secondary)]'
        )"
        @select="emit('select')"
    >
        <slot />
    </DropdownMenuItem>
</template>
