<script setup lang="ts">
import { computed } from "vue";
import { Icon } from "@/components/ui";

interface Props {
    // Stats
    bitrate?: number; // kbps
    packetLoss?: number; // percentage
    rtt?: number; // ms
    score?: number; // 0 (Good) to 2 (Poor)
    compact?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    bitrate: 0,
    packetLoss: 0,
    rtt: 0,
    score: -1,
    compact: false
});

const healthInfo = computed(() => {
    switch (props.score) {
        case 0:
            return { label: 'Good Connection', color: 'text-green-500', icon: 'Signal' };
        case 1:
            return { label: 'Unstable Connection', color: 'text-yellow-500', icon: 'SignalLow' };
        case 2:
            return { label: 'Poor Connection', color: 'text-red-500', icon: 'SignalZero' };
        default:
            return { label: 'Unknown', color: 'text-gray-500', icon: 'Signal' };
    }
});
</script>

<template>
    <div class="group relative flex items-center justify-center">
        <!-- Signal Icon Container -->
        <div 
            :class="[healthInfo.color, 'hover:bg-white/10 p-1 rounded-md transition-all duration-200 cursor-help flex items-center gap-1.5']"
        >
            <Icon :name="healthInfo.icon" :size="compact ? 14 : 18" />
            <span v-if="!compact" class="text-xs font-medium">{{ healthInfo.label }}</span>
        </div>

        <!-- Tooltip -->
        <div 
            class="absolute mb-2 bg-zinc-900/95 backdrop-blur-md border border-zinc-700/50 rounded-xl p-4 shadow-2xl opacity-0 translate-y-1 invisible group-hover:opacity-100 group-hover:translate-y-0 group-hover:visible transition-all duration-300 z-100 w-52 pointer-events-none"
            :class="[compact ? 'bottom-full left-0' : 'top-full right-0']"
        >
            <div class="flex items-center gap-2 mb-3 pb-2 border-b border-zinc-800">
                <div :class="['w-2 h-2 rounded-full', score === 0 ? 'bg-green-500' : (score === 1 ? 'bg-yellow-500' : (score === 2 ? 'bg-red-500' : 'bg-zinc-500'))]"></div>
                <div class="text-[11px] font-bold text-white uppercase tracking-wider">
                    {{ healthInfo.label }}
                </div>
            </div>
            
            <div class="space-y-2.5">
                <div class="flex justify-between items-center">
                    <span class="text-[10px] text-zinc-400 font-medium uppercase tracking-tight">Bitrate</span>
                    <span class="text-xs text-zinc-100 font-mono font-bold">{{ bitrate.toFixed(0) }} kbps</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-[10px] text-zinc-400 font-medium uppercase tracking-tight">Packet Loss</span>
                    <span class="text-xs text-zinc-100 font-mono font-bold" :class="packetLoss > 5 ? 'text-red-400' : ''">{{ packetLoss.toFixed(1) }}%</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-[10px] text-zinc-400 font-medium uppercase tracking-tight">Latency</span>
                    <span class="text-xs text-zinc-100 font-mono font-bold" :class="rtt > 300 ? 'text-red-400' : ''">{{ rtt.toFixed(0) }} ms</span>
                </div>
            </div>

            <div v-if="score > 0" class="mt-3 pt-2.5 border-t border-zinc-800/50 text-[10px] text-zinc-500 italic leading-snug">
                Connection quality is unstable. Media performance may be affected.
            </div>
        </div>
    </div>
</template>
