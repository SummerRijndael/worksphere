<template>
    <div class="bg-(--surface-card) border border-(--border-default) rounded-xl p-6">
        <h3 class="font-medium text-(--text-primary) mb-6">Top Countries</h3>
        
        <div class="space-y-4">
            <div 
                v-for="(country, index) in topCountries" 
                :key="country.iso_code"
                class="flex items-center justify-between"
            >
                <div class="flex items-center gap-3">
                    <span class="text-(--text-tertiary) text-sm w-4">{{ index + 1 }}</span>
                    <img 
                        v-if="country.iso_code && country.iso_code.length === 2"
                        :src="`https://flagcdn.com/w20/${country.iso_code.toLowerCase()}.png`"
                        :alt="country.country"
                        class="w-5 h-auto rounded-sm shadow-sm"
                        loading="lazy"
                        @error="($event.target as HTMLImageElement).style.display = 'none'; ($event.target as HTMLImageElement).nextElementSibling?.classList.remove('hidden')"
                    />
                    <span 
                        class="w-5 text-center text-sm hidden" 
                        :class="{ '!inline': !country.iso_code || country.iso_code.length !== 2 }"
                    >🌐</span>
                    <span class="text-(--text-secondary) text-sm font-medium">{{ country.country }}</span>
                </div>
                
                <div class="flex items-center gap-4">
                    <div class="flex flex-col items-end">
                        <span class="text-(--text-primary) font-semibold text-sm">{{ formatNumber(country.count) }}</span>
                        <span class="text-(--text-tertiary) text-xs">{{ calculatePercentage(country.count) }}%</span>
                    </div>
                    
                    <div class="w-16 h-1.5 bg-(--surface-subtle) rounded-full overflow-hidden">
                        <div 
                            class="h-full bg-(--interactive-primary) rounded-full"
                            :style="{ width: `${calculatePercentage(country.count)}%` }"
                        ></div>
                    </div>
                </div>
            </div>

            <div v-if="topCountries.length === 0" class="text-center py-8 text-(--text-tertiary) text-sm">
                No country data available for this period.
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useAnalyticsStore } from '@/stores/analytics';

const store = useAnalyticsStore();

const topCountries = computed(() => {
    if (!store.geoStats?.length) return [];

    const countryMap = new Map();
    
    // Aggregate by country code to handle multiple cities in same country
    store.geoStats.forEach(stat => {
        if (!stat.iso_code) return;
        
        const existing = countryMap.get(stat.iso_code) || {
            iso_code: stat.iso_code,
            country: stat.country,
            count: 0
        };
        
        existing.count += Number(stat.count);
        countryMap.set(stat.iso_code, existing);
    });

    return Array.from(countryMap.values())
        .sort((a, b) => b.count - a.count)
        .slice(0, 5);
});

const totalVisits = computed(() => {
    return topCountries.value.reduce((sum, c) => sum + c.count, 0);
});

function calculatePercentage(count: number) {
    if (!totalVisits.value) return 0;
    // Calculate percentage against total of top countries, or total overall?
    // Usually against total visible set or total overall visits. 
    // Let's use total of displayed countries for relative comparison bar, 
    // but maybe we want global percentage? 
    // For now, let's use relative to the *max* item for the bar width visual,
    // and percentage of total visits for the text.
    
    // Simplification: % of total aggregated count in this list
    return Math.round((count / totalVisits.value) * 100);
}

function formatNumber(num: number) {
    return new Intl.NumberFormat('en-US', {
        notation: num > 10000 ? 'compact' : 'standard',
        maximumFractionDigits: 1
    }).format(num);
}
</script>
