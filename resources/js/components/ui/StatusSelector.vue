<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { usePresence } from '@/composables/usePresence.ts';
import { Check, Wifi, WifiOff } from 'lucide-vue-next';
import { Dropdown, DropdownItem, DropdownSeparator, DropdownLabel } from '@/components/ui';

const props = defineProps({
    size: {
        type: String,
        default: 'md',
        validator: (v) => ['sm', 'md', 'lg'].includes(v),
    },
});

const { currentStatus, preferredStatus, setStatus, isOnline } = usePresence();

const statusOptions = [
    { value: 'online', label: 'Online', color: 'bg-emerald-500', ringColor: 'ring-emerald-500/20', textColor: 'text-emerald-800 dark:text-emerald-400' },
    { value: 'busy', label: 'Busy', color: 'bg-rose-500', ringColor: 'ring-rose-500/20', textColor: 'text-rose-800 dark:text-rose-400' },
    { value: 'away', label: 'Away', color: 'bg-amber-500', ringColor: 'ring-amber-500/20', textColor: 'text-amber-800 dark:text-amber-400' },
    { value: 'invisible', label: 'Invisible', color: 'bg-slate-400', ringColor: 'ring-slate-400/20', textColor: 'text-slate-800 dark:text-slate-400' },
];

const currentOption = computed(() => 
    statusOptions.find(opt => opt.value === currentStatus.value) || statusOptions[0]
);

async function handleStatusChange(status) {
    if (!isOnline.value) return;
    const result = await setStatus(status);
    if (!result.success) {
        console.error('Failed to update status:', result.error);
    }
}
</script>

<template>
    <Dropdown align="start" :side-offset="8">
        <template #trigger>
            <button class="v3-status-trigger group flex items-center gap-2 px-2 py-1 rounded-lg hover:bg-[var(--surface-tertiary)] transition-colors">
                 <span 
                    class="h-2.5 w-2.5 rounded-full ring-2 ring-[var(--surface-primary)]"
                    :class="currentOption.color"
                />
                <span class="text-xs font-medium text-[var(--text-secondary)] group-hover:text-[var(--text-primary)]">
                    {{ currentOption.label }}
                </span>
            </button>
        </template>

        <div class="w-48 p-1">
             <DropdownLabel class="text-xs text-[var(--text-muted)] font-normal px-2 py-1.5">
                Set Status
             </DropdownLabel>
             
            <DropdownItem
                v-for="option in statusOptions"
                :key="option.value"
                @select="handleStatusChange(option.value)"
                class="flex items-center gap-2 cursor-pointer"
            >
                <div class="relative flex h-3 w-3 items-center justify-center">
                    <span 
                        class="h-2 w-2 rounded-full"
                        :class="option.color"
                    />
                </div>
                <span :class="preferredStatus === option.value ? 'font-medium text-[var(--text-primary)]' : 'text-[var(--text-secondary)]'">
                    {{ option.label }}
                </span>
                <Check 
                    v-if="preferredStatus === option.value"
                    class="ml-auto h-3.5 w-3.5 text-[var(--brand)]" 
                />
            </DropdownItem>

            <DropdownSeparator class="my-1" />

            <div class="px-2 py-1.5 flex items-center gap-2 text-xs">
                <template v-if="isOnline">
                    <Wifi class="h-3 w-3 text-emerald-500" />
                    <span class="text-[var(--text-secondary)]">Connected</span>
                </template>
                <template v-else>
                    <WifiOff class="h-3 w-3 text-rose-500 animate-pulse" />
                    <span class="text-[var(--text-muted)]">Reconnecting...</span>
                </template>
            </div>
        </div>
    </Dropdown>
</template>
