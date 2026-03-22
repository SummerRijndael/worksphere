<script setup>
import { computed } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { Check, ChevronDown } from 'lucide-vue-next';
import { Dropdown, DropdownItem, DropdownSeparator, DropdownLabel, Avatar } from '@/components/ui';
import { getSupportStatusColor, getSupportStatusLabel } from '@/composables/usePresence';

const props = defineProps({
    collapsed: {
        type: Boolean,
        default: false,
    },
});

const authStore = useAuthStore();

const statusOptions = [
    { value: 'available', description: 'Ready for new chats' },
    { value: 'break', description: 'Short break' },
    { value: 'lunch', description: 'Out for lunch' },
    { value: 'acw', description: 'After Call Work' },
    { value: 'bio', description: 'Away briefly' },
    { value: 'unavailable', description: 'Not taking chats' },
];

const currentStatus = computed(() => {
    return authStore.user?.support_status || 'available';
});

const currentOption = computed(() => {
    const opt = statusOptions.find(opt => opt.value === currentStatus.value) || statusOptions[0];
    return {
        ...opt,
        label: getSupportStatusLabel(opt.value),
        color: getSupportStatusColor(opt.value),
    };
});

async function handleStatusChange(status) {
    await authStore.updateSupportPresence(status);
}
</script>

<template>
    <Dropdown align="start" :side-offset="8">
        <template #trigger>
            <button 
                class="group flex items-center transition-all duration-200"
                :class="[
                    collapsed 
                        ? 'p-0 justify-center' 
                        : 'gap-2 px-2 py-1.5 rounded-lg hover:bg-(--surface-tertiary) w-full'
                ]"
                :title="collapsed ? currentOption.label : ''"
            >
                <div class="relative shrink-0">
                    <Avatar
                        :src="authStore.user?.avatar_url"
                        :fallback="authStore.user?.name?.slice(0, 1).toUpperCase()"
                        size="sm"
                        class="h-8 w-8 ring-2 ring-(--surface-primary)"
                    />
                    <span 
                        class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-(--surface-primary)"
                        :class="currentOption.color"
                    />
                </div>
                
                <template v-if="!collapsed">
                    <div class="flex flex-col items-start min-w-0 flex-1">
                        <span class="text-xs font-semibold text-(--text-primary) truncate w-full">
                            {{ authStore.user?.name }}
                        </span>
                        <span class="text-[10px] text-(--text-secondary) truncate w-full">
                            {{ currentOption.label }}
                        </span>
                    </div>
                    <ChevronDown class="h-3.5 w-3.5 text-(--text-muted) group-hover:text-(--text-secondary)" />
                </template>
            </button>
        </template>

        <div class="w-56 p-1">
             <DropdownLabel class="text-[10px] text-(--text-muted) font-semibold uppercase tracking-wider px-2 py-1.5">
                Support Presence
             </DropdownLabel>
             
            <DropdownItem
                v-for="option in statusOptions"
                :key="option.value"
                @select="handleStatusChange(option.value)"
                class="flex items-center gap-3 cursor-pointer py-2"
            >
                <div class="h-2 w-2 rounded-full" :class="getSupportStatusColor(option.value)" />
                <div class="flex flex-col min-w-0">
                    <span class="text-xs font-medium" :class="currentStatus === option.value ? 'text-(--text-primary)' : 'text-(--text-secondary)'">
                        {{ getSupportStatusLabel(option.value) }}
                    </span>
                    <span class="text-[10px] text-(--text-muted) truncate">
                        {{ option.description }}
                    </span>
                </div>
                <Check 
                    v-if="currentStatus === option.value"
                    class="ml-auto h-3.5 w-3.5 text-(--brand)" 
                />
            </DropdownItem>
        </div>
    </Dropdown>
</template>
