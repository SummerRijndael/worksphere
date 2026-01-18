<script setup lang="ts">
import { computed } from 'vue';
import { Check } from 'lucide-vue-next';
import { Button } from '@/components/ui';
import type { Service } from '@/schemas/ServiceSchema';
import { cn } from '@/lib/utils'; // Assuming cn utility exists

const props = defineProps<{
    service: Service;
}>();

const emit = defineEmits<{
    (e: 'edit', id: string): void;
    (e: 'delete', id: string): void;
}>();

const isPopular = computed(() => props.service.is_popular);

// Theme-based class mapping (can be extended)
const themeClasses = computed(() => {
    if (props.service.color_theme === 'orange') {
        return {
            border: 'border-orange-500',
            badge: 'bg-orange-600 text-white',
            button: 'bg-orange-600 hover:bg-orange-700 text-white',
            check: 'text-green-500' // Keep standard green check or make it theme colored
        };
    }
    return {
        border: 'border-zinc-800',
        badge: 'bg-zinc-800 text-zinc-300',
        button: 'bg-zinc-800 hover:bg-zinc-700 text-white border border-zinc-700',
        check: 'text-green-500'
    };
});
</script>

<template>
    <div
        :class="cn(
            'relative flex flex-col rounded-2xl border p-8 shadow-sm transition-all duration-200',
            'bg-zinc-900/50 backdrop-blur-sm', // Glass effect base
            isPopular ? themeClasses.border : 'border-zinc-800 hover:border-zinc-700'
        )"
    >
        <!-- Popular Badge -->
        <div
            v-if="isPopular"
            class="absolute -top-4 left-1/2 -translate-x-1/2 rounded-full px-4 py-1 text-sm font-medium shadow-sm"
            :class="themeClasses.badge"
        >
            Most Popular
        </div>

        <!-- Header -->
        <div class="mb-8">
            <h3 class="text-xl font-semibold text-white">{{ service.name }}</h3>
            <p class="mt-2 text-sm text-zinc-400 min-h-[40px]">{{ service.description }}</p>
        </div>

        <!-- Price -->
        <div class="mb-8 flex items-baseline text-white">
            <template v-if="service.currency">
                <span class="text-3xl font-bold tracking-tight">{{ service.currency }}</span>
                <span class="text-5xl font-bold tracking-tight">{{ service.price }}</span>
                <span class="ml-2 text-sm font-medium text-zinc-400" v-if="service.interval !== 'forever'">/ {{ service.interval }}</span>
                <span class="ml-2 text-sm font-medium text-zinc-400" v-else>forever</span>
            </template>
            <template v-else>
                <span class="text-4xl font-bold tracking-tight">Custom</span>
                 <span class="ml-2 text-sm font-medium text-zinc-400" v-if="service.interval">{{ service.interval }}</span>
            </template>
        </div>

        <!-- CTA -->
        <Button
            class="w-full rounded-md py-6 text-base font-semibold shadow-sm transition-all active:scale-[0.98]"
            :class="themeClasses.button"
            @click="$emit('edit', service.id!)"
        >
            {{ service.cta_text }}
        </Button>

        <!-- Features -->
        <ul role="list" class="mt-8 space-y-4 text-sm leading-6 text-zinc-300 flex-1">
            <li v-for="(feature, index) in service.features" :key="index" class="flex gap-x-3">
                <Check class="h-6 w-5 flex-none" :class="themeClasses.check" aria-hidden="true" />
                <span class="text-left">{{ feature }}</span>
            </li>
        </ul>
        
         <!-- Admin Controls (Only visible in admin view context, logic via parent or separate slot could be better, but simplified for now) -->
        <div class="mt-6 pt-6 border-t border-zinc-800 flex justify-between items-center opacity-0 group-hover:opacity-100 transition-opacity">
            <button @click.stop="$emit('edit', service.id!)" class="text-xs text-zinc-400 hover:text-white underline">Edit</button>
             <button @click.stop="$emit('delete', service.id!)" class="text-xs text-red-400 hover:text-red-300 underline">Delete</button>
        </div>
    </div>
</template>
