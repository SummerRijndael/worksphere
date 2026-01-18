<script setup lang="ts">
import { onMounted, computed, ref } from "vue";
import { useLinkPreview } from "@/composables/useLinkPreview";
import { Icon } from "@/components/ui";

interface Props {
    url: string;
}

const props = defineProps<Props>();
const { loading, preview, fetchPreview } = useLinkPreview();
const showWarning = ref(false);

const isInternalLink = (url: string) => {
    try {
        const urlObj = new URL(url);
        return urlObj.hostname === window.location.hostname;
    } catch {
        return false;
    }
};

onMounted(() => {
    if (isInternalLink(props.url)) {
        // For internal links, don't fetch (avoids deadlock), just show basic clickable card
        preview.value = {
            url: props.url,
            title: props.url,
            site_name: 'Internal Link'
        };
    } else {
        fetchPreview(props.url);
    }
});

const isInternal = computed(() => isInternalLink(props.url));


const formattedUrl = computed(() => {
    try {
        const urlObj = new URL(props.url);
        return urlObj.hostname;
    } catch {
        return props.url;
    }
});

const handleClick = (e: MouseEvent) => {
    if (isInternal.value) return; // Allow normal navigation
    if (preview.value?.error === 'unsafe_content_blocked') {
        e.preventDefault();
        return;
    }
    
    // External link warning
    if (!showWarning.value) {
        e.preventDefault();
        showWarning.value = true;
    }
};

const proceed = () => {
    window.open(props.url, '_blank');
    showWarning.value = false;
};
</script>

<template>
    <div class="inline-block w-full max-w-[280px]">
        <!-- Loading State -->
        <div v-if="loading" class="animate-pulse flex space-x-4 p-3 bg-gray-50 dark:bg-zinc-900 rounded-lg border dark:border-zinc-800">
            <div class="flex-1 space-y-2 py-1">
                <div class="h-2 bg-gray-200 dark:bg-zinc-700 rounded w-3/4"></div>
                <div class="h-2 bg-gray-200 dark:bg-zinc-700 rounded w-1/2"></div>
            </div>
        </div>

        <!-- Error/Unsafe State -->
        <div v-else-if="preview?.error === 'unsafe_content_blocked'" class="p-3 bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-900/30 rounded-lg flex items-center gap-3 text-red-700 dark:text-red-400">
            <Icon name="ShieldAlert" class="shrink-0" size="20" />
            <div class="text-sm font-medium">Unsafe content blocked</div>
        </div>

        <!-- Success State -->
        <a 
            v-else-if="preview" 
            :href="url" 
            @click="handleClick"
            class="block bg-white dark:bg-zinc-900 border dark:border-zinc-800 rounded-lg overflow-hidden hover:bg-gray-50 dark:hover:bg-zinc-800/50 transition-colors group no-underline"
        >
            <div class="flex">
                <div v-if="preview.image" class="w-24 h-24 shrink-0 bg-gray-100 dark:bg-zinc-800">
                    <img :src="preview.image" class="w-full h-full object-cover" :alt="preview.title" />
                </div>
                <div class="p-3 flex flex-col justify-center min-w-0">
                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-0.5 truncate uppercase font-semibold tracking-wider">
                        {{ preview.site_name || formattedUrl }}
                    </div>
                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100 line-clamp-1 mb-1 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">
                        {{ preview.title || props.url }}
                    </div>
                    <div v-if="preview.description" class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2">
                        {{ preview.description }}
                    </div>
                </div>
            </div>
        </a>
        
        <!-- Warning Modal/Overlay -->
        <div v-if="showWarning" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm">
            <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-xl max-w-sm w-full p-6 border dark:border-zinc-800">
                <div class="flex flex-col items-center text-center">
                    <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900/20 rounded-full flex items-center justify-center mb-4 text-yellow-600 dark:text-yellow-500">
                        <Icon name="ExternalLink" size="24" />
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">Leaving App</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                        You are about to visit an external website. Are you sure you want to continue to <span class="font-medium text-gray-700 dark:text-gray-300">{{ formattedUrl }}</span>?
                    </p>
                    <div class="flex gap-3 w-full">
                        <button 
                            @click="showWarning = false"
                            class="flex-1 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-zinc-800 rounded-lg hover:bg-gray-200 dark:hover:bg-zinc-700 transition-colors"
                        >
                            Cancel
                        </button>
                        <button 
                            @click="proceed"
                            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors"
                        >
                            Continue
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
