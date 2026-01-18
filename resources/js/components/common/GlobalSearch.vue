<script setup>
import { ref, onMounted, onUnmounted, watch, nextTick, computed } from 'vue';
import { useRouter } from 'vue-router';
import { Search, Loader2, X, Command } from 'lucide-vue-next';
import axios from 'axios';
import debounce from 'lodash/debounce';
import { useNavigationStore } from '@/stores/navigation';

const navStore = useNavigationStore();
const query = ref('');
const results = ref([]);
const loading = ref(false);
const selectedIndex = ref(0);
const router = useRouter();

const open = () => {
    navStore.openSearch();
};

const close = () => {
    navStore.closeSearch();
    query.value = '';
    results.value = [];
    selectedIndex.value = 0;
};

// Watch for modal opening
const isSearchOpen = computed(() => navStore.isSearchOpen);
watch(isSearchOpen, (isOpen) => {
    if (isOpen) {
        nextTick(() => {
            setTimeout(() => {
                 document.getElementById('global-search-input')?.focus();
            }, 50); // Small delay to ensure transition has started/DOM is ready
        });
    }
});

const performSearch = debounce(async (searchQuery) => {
    if (!searchQuery) {
        results.value = [];
        return;
    }

    loading.value = true;
    try {
        const response = await axios.get('/api/search', {
            params: { query: searchQuery }
        });
        results.value = response.data.results || [];
    } catch (error) {
        console.error('Search failed:', error);
    } finally {
        loading.value = false;
    }
}, 300);

watch(query, (newQuery) => {
    if (!newQuery) return;
    performSearch(newQuery);
});

const handleKeydown = (e) => {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex.value = (selectedIndex.value + 1) % results.value.length;
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex.value = (selectedIndex.value - 1 + results.value.length) % results.value.length;
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (results.value[selectedIndex.value]) {
            navigateTo(results.value[selectedIndex.value]);
        }
    } else if (e.key === 'Escape') {
        close();
    }
};

const navigateTo = (result) => {
    close();
    router.push(result.url);
};

const onKeydown = (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        if (navStore.isSearchOpen) {
            close();
        } else {
            open();
        }
    }
};

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
});

defineExpose({ open });
</script>

<template>
    <div v-if="navStore.isSearchOpen" class="fixed inset-0 z-50 flex items-start justify-center pt-[20vh] px-4">
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="close"></div>

        <!-- Modal -->
        <div class="relative w-full max-w-2xl overflow-hidden rounded-xl border border-[var(--border-default)] bg-[var(--surface-elevated)] shadow-2xl flex flex-col max-h-[60vh]">
            <!-- Header -->
            <div class="flex items-center border-b border-[var(--border-default)] px-4 h-14 shrink-0">
                <Search class="h-5 w-5 text-[var(--text-muted)] mr-3" />
                <input
                    id="global-search-input"
                    v-model="query"
                    type="text"
                    placeholder="Search clients, teams..."
                    class="flex-1 bg-transparent text-[var(--text-primary)] placeholder-[var(--text-muted)] border-none focus:ring-0 focus:outline-none text-base h-10 px-0"
                    @keydown="handleKeydown"
                    autocomplete="off"
                />
                <button v-if="query" @click="query = ''" class="ml-2 text-[var(--text-muted)] hover:text-[var(--text-primary)]">
                    <X class="h-4 w-4" />
                </button>
                <div class="ml-4 hidden sm:flex items-center gap-1 rounded border border-[var(--border-default)] bg-[var(--surface-secondary)] px-1.5 py-0.5 text-xs text-[var(--text-muted)]">
                    <span class="text-[10px]">ESC</span>
                </div>
            </div>

            <!-- Results -->
            <div class="overflow-y-auto p-2 scrollbar-thin scrollbar-thumb-[var(--border-default)] scrollbar-track-transparent">
                <div v-if="loading" class="flex items-center justify-center py-8 text-[var(--text-muted)]">
                    <Loader2 class="h-6 w-6 animate-spin mr-2" />
                    <span>Searching...</span>
                </div>

                <div v-else>
                    <Transition
                        enter-active-class="transition-all duration-200 ease-out"
                        enter-from-class="opacity-0 translate-y-2"
                        enter-to-class="opacity-100 translate-y-0"
                        leave-active-class="transition-all duration-150 ease-in"
                        leave-from-class="opacity-100 translate-y-0"
                        leave-to-class="opacity-0 translate-y-2"
                    >
                        <div v-if="results.length > 0" class="space-y-1">
                            <div
                                v-for="(result, index) in results"
                                :key="result.id + result.type"
                                class="group flex items-center justify-between px-3 py-2.5 rounded-lg cursor-pointer transition-colors"
                                :class="[
                                    index === selectedIndex ? 'bg-[var(--interactive-primary)]/10' : 'hover:bg-[var(--surface-secondary)]'
                                ]"
                                @click="navigateTo(result)"
                                @mouseenter="selectedIndex = index"
                            >
                                <div class="flex items-center gap-3 overflow-hidden">
                                    <!-- Icon based on type -->
                                    <div class="flex-shrink-0 flex items-center justify-center w-8 h-8 rounded-full bg-[var(--surface-secondary)] text-[var(--text-secondary)]">
                                        <span v-if="result.type === 'Client'">C</span>
                                        <span v-else-if="result.type === 'Team'">T</span>
                                        <span v-else-if="result.type === 'Navigation'">
                                            <Command class="h-4 w-4" />
                                        </span>
                                        <Search v-else class="h-4 w-4" />
                                    </div>
                                    
                                    <div class="flex flex-col overflow-hidden">
                                        <span class="text-sm font-medium text-[var(--text-primary)] truncate" :class="{'text-[var(--interactive-primary)]': index === selectedIndex}">
                                            {{ result.title }}
                                        </span>
                                        <span class="text-xs text-[var(--text-muted)] truncate">
                                            {{ result.subtitle }}
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="text-[10px] bg-[var(--surface-secondary)] text-[var(--text-secondary)] px-2 py-0.5 rounded-full uppercase tracking-wider font-semibold">
                                    {{ result.type }}
                                </div>
                            </div>
                        </div>
                    </Transition>

                    <div v-if="results.length === 0 && query" class="flex flex-col items-center justify-center py-12 text-center text-[var(--text-muted)]">
                        <p>No results found for "{{ query }}"</p>
                    </div>

                    <div v-if="results.length === 0 && !query" class="flex flex-col items-center justify-center py-12 text-center text-[var(--text-muted)]">
                        <Command class="h-8 w-8 mb-2 opacity-50" />
                        <p class="text-sm">Type to search...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
