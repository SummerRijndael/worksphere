<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import { Activity, Check, ChevronDown, ClipboardList, Clock, Coffee, UserX, UtensilsCrossed } from 'lucide-vue-next';
import { Dropdown, DropdownItem, DropdownSeparator, DropdownLabel, Avatar } from '@/components/ui';
import { getSupportStatusColor, getSupportStatusLabel } from '@/composables/usePresence';

const props = defineProps({
    collapsed: {
        type: Boolean,
        default: false,
    },
    activeChatsCount: {
        type: Number,
        default: 0,
    },
    workingSinceAt: {
        type: String,
        default: null,
    },
});

const authStore = useAuthStore();
const now = ref(Date.now());
let timer = null;

onMounted(() => {
    timer = setInterval(() => {
        now.value = Date.now();
    }, 1000);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
});

const statusOptions = [
    { value: 'available', description: 'Ready for new chats' },
    { value: 'break', description: 'Short break' },
    { value: 'lunch', description: 'Out for lunch' },
    { value: 'acw', description: 'After Call Work' },
    { value: 'bio', description: 'Bio break' },
    { value: 'unavailable', description: 'Not taking chats' },
];

const currentStatus = computed(() => {
    return authStore.user?.support_status || 'available';
});

const statusAt = computed(() => authStore.user?.support_status_at);
const displayStatus = computed(() => (
    currentStatus.value === 'available' && props.activeChatsCount > 0
        ? 'working'
        : currentStatus.value
));
const displayStatusAt = computed(() => (
    displayStatus.value === 'working'
        ? props.workingSinceAt || statusAt.value
        : statusAt.value
));

const showTimer = computed(() => {
    return ['available', 'working', 'break', 'lunch', 'acw', 'bio'].includes(displayStatus.value);
});

const durationLabel = computed(() => {
    if (!displayStatusAt.value || !showTimer.value) return null;
    
    const start = new Date(displayStatusAt.value).getTime();
    if (isNaN(start)) return null;
    
    const diff = Math.floor((now.value - start) / 1000);
    if (diff < 0) return '00:00';
    
    const h = Math.floor(diff / 3600);
    const m = Math.floor((diff % 3600) / 60);
    const s = diff % 60;
    
    if (h > 0) {
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
});

const currentOption = computed(() => {
    const opt = statusOptions.find(opt => opt.value === currentStatus.value) || statusOptions[0];
    return {
        ...opt,
        label: getSupportStatusLabel(displayStatus.value),
        color: getSupportStatusColor(displayStatus.value),
    };
});

const statusIconMap = {
    available: Activity,
    working: Activity,
    break: Coffee,
    lunch: UtensilsCrossed,
    acw: ClipboardList,
    bio: Clock,
    unavailable: UserX,
};

const statusVisualClasses = computed(() => {
    const classes = {
        available: {
            shell: 'border-emerald-400/35 bg-[radial-gradient(circle_at_top_left,rgba(16,185,129,0.18),transparent_55%),linear-gradient(180deg,rgba(255,255,255,0.92),rgba(236,253,245,0.78))] shadow-[0_12px_24px_-18px_rgba(16,185,129,0.7)] dark:bg-[linear-gradient(180deg,rgba(6,78,59,0.52),rgba(6,95,70,0.18))]',
            badge: 'border-emerald-500/25 bg-emerald-500/12 text-emerald-700 dark:text-emerald-300',
            iconWrap: 'bg-emerald-500/14 text-emerald-600 ring-1 ring-emerald-500/20 dark:text-emerald-300',
        },
        working: {
            shell: 'border-sky-400/35 bg-[radial-gradient(circle_at_top_left,rgba(14,165,233,0.18),transparent_55%),linear-gradient(180deg,rgba(255,255,255,0.92),rgba(240,249,255,0.8))] shadow-[0_12px_24px_-18px_rgba(14,165,233,0.75)] dark:bg-[linear-gradient(180deg,rgba(12,74,110,0.56),rgba(8,47,73,0.18))]',
            badge: 'border-sky-500/25 bg-sky-500/12 text-sky-700 dark:text-sky-300',
            iconWrap: 'bg-sky-500/14 text-sky-600 ring-1 ring-sky-500/20 dark:text-sky-300',
        },
        break: {
            shell: 'border-amber-400/35 bg-[radial-gradient(circle_at_top_left,rgba(245,158,11,0.18),transparent_55%),linear-gradient(180deg,rgba(255,255,255,0.92),rgba(255,251,235,0.82))] shadow-[0_12px_24px_-18px_rgba(245,158,11,0.72)] dark:bg-[linear-gradient(180deg,rgba(120,53,15,0.54),rgba(69,26,3,0.18))]',
            badge: 'border-amber-500/25 bg-amber-500/12 text-amber-700 dark:text-amber-300',
            iconWrap: 'bg-amber-500/14 text-amber-600 ring-1 ring-amber-500/20 dark:text-amber-300',
        },
        lunch: {
            shell: 'border-orange-400/35 bg-[radial-gradient(circle_at_top_left,rgba(249,115,22,0.18),transparent_55%),linear-gradient(180deg,rgba(255,255,255,0.92),rgba(255,247,237,0.82))] shadow-[0_12px_24px_-18px_rgba(249,115,22,0.72)] dark:bg-[linear-gradient(180deg,rgba(124,45,18,0.54),rgba(67,20,7,0.18))]',
            badge: 'border-orange-500/25 bg-orange-500/12 text-orange-700 dark:text-orange-300',
            iconWrap: 'bg-orange-500/14 text-orange-600 ring-1 ring-orange-500/20 dark:text-orange-300',
        },
        acw: {
            shell: 'border-violet-400/35 bg-[radial-gradient(circle_at_top_left,rgba(139,92,246,0.18),transparent_55%),linear-gradient(180deg,rgba(255,255,255,0.92),rgba(245,243,255,0.82))] shadow-[0_12px_24px_-18px_rgba(139,92,246,0.72)] dark:bg-[linear-gradient(180deg,rgba(76,29,149,0.54),rgba(46,16,101,0.18))]',
            badge: 'border-violet-500/25 bg-violet-500/12 text-violet-700 dark:text-violet-300',
            iconWrap: 'bg-violet-500/14 text-violet-600 ring-1 ring-violet-500/20 dark:text-violet-300',
        },
        bio: {
            shell: 'border-fuchsia-400/35 bg-[radial-gradient(circle_at_top_left,rgba(217,70,239,0.16),transparent_55%),linear-gradient(180deg,rgba(255,255,255,0.92),rgba(253,244,255,0.82))] shadow-[0_12px_24px_-18px_rgba(217,70,239,0.68)] dark:bg-[linear-gradient(180deg,rgba(112,26,117,0.54),rgba(59,7,100,0.18))]',
            badge: 'border-fuchsia-500/25 bg-fuchsia-500/12 text-fuchsia-700 dark:text-fuchsia-300',
            iconWrap: 'bg-fuchsia-500/14 text-fuchsia-600 ring-1 ring-fuchsia-500/20 dark:text-fuchsia-300',
        },
        unavailable: {
            shell: 'border-slate-400/30 bg-[radial-gradient(circle_at_top_left,rgba(100,116,139,0.12),transparent_55%),linear-gradient(180deg,rgba(255,255,255,0.9),rgba(248,250,252,0.82))] shadow-[0_12px_24px_-18px_rgba(51,65,85,0.42)] dark:bg-[linear-gradient(180deg,rgba(30,41,59,0.6),rgba(15,23,42,0.22))]',
            badge: 'border-slate-500/20 bg-slate-500/10 text-slate-700 dark:text-slate-300',
            iconWrap: 'bg-slate-500/12 text-slate-600 ring-1 ring-slate-500/18 dark:text-slate-300',
        },
    };

    return classes[displayStatus.value] || classes.available;
});

const currentStatusIcon = computed(() => statusIconMap[displayStatus.value] || Activity);

const presenceHeadline = computed(() => currentOption.value.description);

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
                        ? 'justify-center rounded-2xl border border-(--border-default)/70 bg-(--surface-primary)/80 p-2 shadow-sm hover:border-(--border-default) hover:bg-(--surface-primary)'
                        : `w-full gap-2.5 rounded-2xl border p-2 text-left backdrop-blur ${statusVisualClasses.shell}`
                ]"
                :title="collapsed ? (durationLabel ? `${currentOption.label} (${durationLabel})` : currentOption.label) : ''"
            >
                <div class="relative shrink-0">
                    <Avatar
                        :src="authStore.user?.avatar_url"
                        :fallback="authStore.user?.name?.slice(0, 1).toUpperCase()"
                        size="sm"
                        class="h-9 w-9 rounded-xl ring-2 ring-white/80 shadow-sm dark:ring-(--surface-primary)"
                    />
                    <span 
                        class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white dark:border-(--surface-primary)"
                        :class="currentOption.color"
                    />
                </div>
                
                <template v-if="!collapsed">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <span class="block truncate text-[13px] font-semibold leading-none text-(--text-primary)">
                                    {{ authStore.user?.name }}
                                </span>
                                <p class="mt-1 truncate text-[10px] leading-none font-medium text-(--text-secondary)">
                                    {{ presenceHeadline }}
                                </p>
                            </div>
                            <div :class="['inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-xl', statusVisualClasses.iconWrap]">
                                <component :is="currentStatusIcon" class="h-3.5 w-3.5" />
                            </div>
                        </div>

                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <span :class="['inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em]', statusVisualClasses.badge]">
                                <span class="h-2 w-2 rounded-full" :class="currentOption.color"></span>
                                {{ currentOption.label }}
                            </span>

                            <span
                                v-if="durationLabel"
                                class="inline-flex w-[5.75rem] items-center justify-center gap-1 rounded-full border border-(--border-default)/70 bg-white/70 px-2.5 py-0.5 text-[10px] font-medium leading-none tabular-nums text-(--text-secondary) dark:bg-(--surface-primary)/60"
                            >
                                <Clock class="h-3 w-3" />
                                <span class="font-mono">{{ durationLabel }}</span>
                            </span>
                        </div>
                    </div>
                    <ChevronDown class="h-4 w-4 shrink-0 text-(--text-muted) transition-transform group-hover:translate-y-[1px] group-hover:text-(--text-secondary)" />
                </template>
            </button>
        </template>

        <div class="w-64 p-1.5">
             <DropdownLabel class="text-[10px] text-(--text-muted) font-semibold uppercase tracking-wider px-2 py-1.5">
                Support Presence
             </DropdownLabel>
             
            <DropdownItem
                v-for="option in statusOptions"
                :key="option.value"
                @select="handleStatusChange(option.value)"
                class="flex items-center gap-3 rounded-xl cursor-pointer py-2.5"
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
