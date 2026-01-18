<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { ChevronDown, X, Search, Plus, Loader2, Mail } from 'lucide-vue-next';
import { cn } from '@/lib/utils';
import Avatar from './Avatar.vue';

const props = defineProps({
    modelValue: {
        type: Array, // Array of { type: 'user'|'email', id?: number, email?: string, name?: string, avatar?: string }
        default: () => [],
    },
    fetchUrl: {
        type: String,
        default: '/api/users',
    },
    placeholder: {
        type: String,
        default: 'Search people or enter email...',
    },
    disabled: Boolean,
    initialLimit: {
        type: Number,
        default: 30,
    },
    max: {
        type: Number,
        default: 0, // 0 means unlimited
    },
    allowExternal: {
        type: Boolean,
        default: true,
    },
    excludedIds: {
        type: Array,
        default: () => [],
    },
});

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
const searchQuery = ref('');
const containerRef = ref(null);
const inputRef = ref(null);
const isLoading = ref(false);
const options = ref([]);
const hasMore = ref(false);
const currentPage = ref(1);

// Email validation regex
const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const isValidEmail = computed(() => emailRegex.test(searchQuery.value.trim()));

const isEmailAlreadyAdded = computed(() => {
    const email = searchQuery.value.trim().toLowerCase();
    return props.modelValue.some(p => p.email?.toLowerCase() === email);
});

const isMaxReached = computed(() => {
    return props.max > 0 && props.modelValue.length >= props.max;
});

const canAddAsEmail = computed(() => {
    return props.allowExternal && isValidEmail.value && !isEmailAlreadyAdded.value && !isMaxReached.value;
});

// Fetch users with pagination
async function fetchOptions(query = '', page = 1) {
    isLoading.value = true;
    try {
        const params = new URLSearchParams({
            search: query,
            page: page.toString(),
            per_page: props.initialLimit.toString(),
        });
        
        const response = await fetch(`${props.fetchUrl}?${params}`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'include',
        });
        
        const data = await response.json();
        const users = data.data || data;
        
        // Transform to our format
        const transformed = users.map(u => ({
            type: 'user',
            id: u.id || u.public_id,
            name: u.name,
            email: u.email,
            avatar: u.avatar_url,
        }));
        
        if (page === 1) {
            options.value = transformed;
        } else {
            options.value = [...options.value, ...transformed];
        }
        
        hasMore.value = data.next_page_url !== null || (data.meta?.current_page < data.meta?.last_page);
        currentPage.value = page;
    } catch (e) {
        console.warn('Failed to fetch participants:', e);
    } finally {
        isLoading.value = false;
    }
}

// Filter options to exclude already selected and excludedIds
const filteredOptions = computed(() => {
    const selectedIds = props.modelValue
        .filter(p => p.type === 'user')
        .map(p => p.id);
    
    // Combine selected IDs with explicitly excluded IDs
    const allExcludedIds = [...selectedIds, ...props.excludedIds];

    return options.value.filter(o => !allExcludedIds.includes(o.id));
});

function toggleOpen() {
    if (props.disabled) return;
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        fetchOptions('', 1);
        setTimeout(() => inputRef.value?.focus(), 50);
    }
}

function selectOption(option) {
    if (isMaxReached.value) return;
    const newValues = [...props.modelValue, option];
    emit('update:modelValue', newValues);
}

function addEmail() {
    if (!canAddAsEmail.value || isMaxReached.value) return;
    
    const email = searchQuery.value.trim();
    const newParticipant = {
        type: 'email',
        email: email,
        name: email, // Use email as display name
    };
    
    const newValues = [...props.modelValue, newParticipant];
    emit('update:modelValue', newValues);
    searchQuery.value = '';
}

function removeParticipant(index) {
    const newValues = [...props.modelValue];
    newValues.splice(index, 1);
    emit('update:modelValue', newValues);
}

function handleKeydown(e) {
    if (e.key === 'Enter' && canAddAsEmail.value) {
        e.preventDefault();
        addEmail();
    }
}

function handleClickOutside(event) {
    if (containerRef.value && !containerRef.value.contains(event.target)) {
        isOpen.value = false;
    }
}

function loadMore() {
    if (!isLoading.value && hasMore.value) {
        fetchOptions(searchQuery.value, currentPage.value + 1);
    }
}

// Debounced search
let searchTimeout = null;
watch(searchQuery, (newQuery) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchOptions(newQuery, 1);
    }, 300);
});

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    clearTimeout(searchTimeout);
});
</script>

<template>
    <div ref="containerRef" class="relative">
        <!-- Selected Tags + Input Trigger -->
        <div
            @click="toggleOpen"
            :class="cn(
                'min-h-[42px] w-full p-2 rounded-lg border bg-[var(--surface-elevated)] cursor-text transition-all',
                isOpen ? 'ring-2 ring-[var(--interactive-primary)]/20 border-[var(--interactive-primary)]' : 'border-[var(--border-default)] hover:border-[var(--border-strong)]',
                disabled && 'opacity-50 cursor-not-allowed bg-[var(--surface-secondary)]'
            )"
        >
            <div class="flex flex-wrap gap-1.5">
                <!-- Selected Tags -->
                <div
                    v-for="(participant, index) in modelValue"
                    :key="participant.id || participant.email"
                    class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full text-xs font-medium transition-colors"
                    :class="participant.type === 'email' 
                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300' 
                        : 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]'"
                >
                    <Avatar 
                        v-if="participant.type === 'user'" 
                        :src="participant.avatar" 
                        :fallback="participant.name?.charAt(0)" 
                        size="xs" 
                    />
                    <Mail v-else class="w-3 h-3" />
                    <span class="max-w-[120px] truncate">{{ participant.name || participant.email }}</span>
                    <button
                        @click.stop="removeParticipant(index)"
                        class="p-0.5 rounded-full hover:bg-black/10 transition-colors"
                    >
                        <X class="w-3 h-3" />
                    </button>
                </div>
                
                <!-- Placeholder when empty -->
                <span 
                    v-if="modelValue.length === 0 && !isOpen" 
                    class="text-sm text-[var(--text-muted)] py-1"
                >
                    {{ placeholder }}
                </span>
            </div>
            </div>
            
            <!-- Max reached warning -->
            <div v-if="isMaxReached" class="absolute -top-3 right-0 bg-amber-100 dark:bg-amber-900/50 text-amber-600 dark:text-amber-400 text-[10px] px-1.5 py-0.5 rounded font-medium border border-amber-200 dark:border-amber-800">
                Max {{ max }} participants
            </div>


        <!-- Dropdown -->
        <div
            v-if="isOpen"
            class="absolute z-50 w-full mt-1 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg shadow-lg overflow-hidden"
        >
            <!-- Search Input -->
            <div class="p-2 border-b border-[var(--border-default)]">
                <div class="relative">
                    <Search class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text-muted)]" />
                    <input
                        ref="inputRef"
                        v-model="searchQuery"
                        type="text"
                        :placeholder="placeholder"
                        class="w-full pl-9 pr-3 py-2 text-sm bg-[var(--surface-secondary)] border border-[var(--border-default)] rounded-md focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/30 focus:border-[var(--interactive-primary)] text-[var(--text-primary)] placeholder-[var(--text-muted)]"
                        @click.stop
                        @keydown="handleKeydown"
                        :disabled="isMaxReached"
                        :class="{'cursor-not-allowed opacity-50': isMaxReached}"
                    />
                </div>
                <div v-if="isMaxReached" class="mt-2 text-xs text-amber-600 dark:text-amber-400 text-center font-medium bg-amber-50 dark:bg-amber-900/20 py-1.5 rounded">
                    Maximum of {{ max }} participants reached
                </div>
                
                <!-- Add Email Hint -->
                <button
                    v-if="canAddAsEmail"
                    @click="addEmail"
                    class="mt-2 w-full flex items-center gap-2 px-3 py-2 text-sm text-left rounded-md bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/30 transition-colors"
                >
                    <Plus class="w-4 h-4" />
                    <span>Add external: <strong>{{ searchQuery.trim() }}</strong></span>
                </button>
            </div>

            <!-- Options -->
            <div class="max-h-[240px] overflow-y-auto p-1">
                <div v-if="isLoading && options.length === 0" class="px-3 py-4 text-sm text-[var(--text-muted)] text-center">
                    <Loader2 class="w-5 h-5 animate-spin mx-auto mb-2" />
                    Loading...
                </div>
                
                <div v-else-if="filteredOptions.length === 0 && !canAddAsEmail" class="px-3 py-4 text-sm text-[var(--text-muted)] text-center">
                    No results found
                </div>
                
                <button
                    v-for="option in filteredOptions"
                    :key="option.id"
                    @click="selectOption(option)"
                    class="flex items-center gap-3 w-full px-3 py-2 text-sm text-left rounded-md hover:bg-[var(--surface-secondary)] transition-colors"
                >
                    <Avatar :src="option.avatar" :fallback="option.name?.charAt(0)" size="sm" />
                    <div class="flex-1 min-w-0">
                        <div class="font-medium text-[var(--text-primary)] truncate">{{ option.name }}</div>
                        <div class="text-xs text-[var(--text-muted)] truncate">{{ option.email }}</div>
                    </div>
                </button>
                
                <!-- Load More -->
                <button
                    v-if="hasMore && !isLoading"
                    @click="loadMore"
                    class="w-full px-3 py-2 text-sm text-center text-[var(--interactive-primary)] hover:bg-[var(--surface-secondary)] rounded-md transition-colors"
                >
                    Load more...
                </button>
                
                <div v-if="isLoading && options.length > 0" class="px-3 py-2 text-center">
                    <Loader2 class="w-4 h-4 animate-spin mx-auto text-[var(--text-muted)]" />
                </div>
            </div>
        </div>
    </div>
</template>
