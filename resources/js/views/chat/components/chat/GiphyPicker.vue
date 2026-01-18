<script setup lang="ts">
import { ref, onMounted } from "vue";
import { Icon } from "@/components/ui";
import axios from "axios";

const props = defineProps<{
    compact?: boolean;
}>();

const emit = defineEmits(["select", "close"]);

const searchQuery = ref("");
const gifs = ref<any[]>([]);
const loading = ref(false);
const showTrending = ref(true);

const fetchTrending = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get("/api/chat/giphy/trending", {
            params: { limit: 20 },
        });
        gifs.value = data.data;
        showTrending.value = true;
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

const search = async () => {
    if (!searchQuery.value.trim()) {
        fetchTrending();
        return;
    }

    loading.value = true;
    try {
        const { data } = await axios.get("/api/chat/giphy/search", {
            params: { q: searchQuery.value, limit: 20 },
        });
        gifs.value = data.data;
        showTrending.value = false;
    } catch (e) {
        console.error(e);
    } finally {
        loading.value = false;
    }
};

let debounceTimeout: any;
const handleInput = () => {
    clearTimeout(debounceTimeout);
    debounceTimeout = setTimeout(search, 500);
};

const selectGif = (gif: any) => {
    emit("select", {
        id: gif.id,
        url: gif.images.original.url,
        title: gif.title,
        width: gif.images.original.width,
        height: gif.images.original.height,
        preview: gif.images.fixed_height_small.url,
    });
};

onMounted(() => {
    fetchTrending();
});
</script>

<template>
    <div
        class="flex flex-col w-full max-w-[316px] bg-[var(--surface-elevated)] rounded-xl shadow-xl border border-[var(--border-default)] overflow-hidden"
        :class="compact ? 'h-[336px]' : 'h-[400px]'"
    >
        <!-- Header -->
        <div
            class="px-3 py-2 border-b border-[var(--border-default)] flex items-center gap-2"
        >
            <div class="relative flex-1">
                <Icon
                    name="Search"
                    class="absolute left-2 top-1/2 -translate-y-1/2 text-[var(--text-tertiary)]"
                    size="14"
                />
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search GIPHY"
                    class="w-full pl-8 pr-3 py-1.5 bg-[var(--surface-secondary)] border-0 rounded-lg text-sm focus:ring-1 focus:ring-[var(--interactive-primary)] placeholder-[var(--text-tertiary)]"
                    @input="handleInput"
                />
            </div>
            <div class="flex items-center">
                <img
                    src="https://developers.giphy.com/branch/master/static/header-logo-0fec0225d189bc0eae27dac3e3770582.gif"
                    class="h-6"
                    alt="GIPHY"
                />
            </div>
        </div>

        <!-- content -->
        <div class="flex-1 overflow-y-auto p-2 scrollbar-hide">
            <div v-if="loading" class="flex items-center justify-center h-full">
                <div
                    class="w-6 h-6 border-2 border-[var(--interactive-primary)] border-t-transparent rounded-full animate-spin"
                />
            </div>

            <div
                v-else-if="gifs.length === 0"
                class="flex flex-col items-center justify-center h-full text-[var(--text-tertiary)]"
            >
                <Icon name="Search" size="24" class="mb-2 opacity-50" />
                <span class="text-xs">No GIFs found</span>
            </div>

            <div v-else class="columns-2 gap-2 space-y-2">
                <button
                    v-for="gif in gifs"
                    :key="gif.id"
                    class="w-full block rounded-lg overflow-hidden relative group break-inside-avoid"
                    @click="selectGif(gif)"
                >
                    <img
                        :src="gif.images.fixed_width.url"
                        :alt="gif.title"
                        class="w-full h-auto object-cover bg-[var(--surface-tertiary)]"
                        loading="lazy"
                    />
                    <div
                        class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors"
                    />
                </button>
            </div>
        </div>

        <!-- Footer / Attribution -->
        <div
            class="px-2 py-1 bg-[var(--surface-secondary)] text-[10px] text-[var(--text-tertiary)] text-center border-t border-[var(--border-default)]"
        >
            Powered by GIPHY
        </div>
    </div>
</template>

<style scoped>
.scrollbar-hide::-webkit-scrollbar {
    display: none;
}
.scrollbar-hide {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
