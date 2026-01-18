<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { ChevronDown, Check, X, Search } from 'lucide-vue-next';
import { cn } from '@/lib/utils';
import Input from './Input.vue';

const props = defineProps({
    modelValue: {
        type: [String, Number, Object],
        default: null,
    },
    options: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: 'Select option...',
    },
    searchPlaceholder: {
        type: String,
        default: 'Search...',
    },
    disabled: Boolean,
    loading: Boolean,
    valueKey: {
        type: String,
        default: 'value',
    },
    labelKey: {
        type: String,
        default: 'label',
    },
    imageKey: {
        type: String, // Optional key to display an image/avatar
        default: null,
    }
});

const emit = defineEmits(['update:modelValue', 'search']);

const isOpen = ref(false);
const searchQuery = ref('');
const containerRef = ref(null);

const filteredOptions = computed(() => {
    if (!searchQuery.value) return props.options;
    
    const query = searchQuery.value.toLowerCase();
    return props.options.filter(option => {
        const label = getOptionLabel(option).toLowerCase();
        return label.includes(query);
    });
});

const selectedLabel = computed(() => {
    if (!props.modelValue) return props.placeholder;
    
    // If modelValue is an object and matches an option
    const selected = props.options.find(o => getOptionValue(o) === (typeof props.modelValue === 'object' ? getOptionValue(props.modelValue) : props.modelValue));
    
    if (selected) return getOptionLabel(selected);
    
    // Fallback if option not found but modelValue provided
    return typeof props.modelValue === 'object' ? getOptionLabel(props.modelValue) : props.modelValue;
});

function getOptionValue(option) {
    if (typeof option === 'object') {
        return option[props.valueKey];
    }
    return option;
}

function getOptionLabel(option) {
    if (typeof option === 'object') {
        return option[props.labelKey];
    }
    return option;
}

function getOptionImage(option) {
    if (props.imageKey && typeof option === 'object') {
        return option[props.imageKey];
    }
    return null;
}

function toggleOpen() {
    if (props.disabled) return;
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        // Focus search input on open
    }
}

function selectOption(option) {
    emit('update:modelValue', getOptionValue(option));
    isOpen.value = false;
    searchQuery.value = '';
}

function handleClickOutside(event) {
    if (containerRef.value && !containerRef.value.contains(event.target)) {
        isOpen.value = false;
    }
}

watch(searchQuery, (newQuery) => {
    emit('search', newQuery);
});

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div ref="containerRef" class="relative group">
        <!-- Trigger -->
        <div
            @click="toggleOpen"
            :class="cn(
                'flex items-center justify-between w-full px-3 py-2 text-sm text-left rounded-lg border bg-[var(--surface-elevated)] cursor-pointer transition-all',
                isOpen ? 'ring-2 ring-[var(--interactive-primary)]/20 border-[var(--interactive-primary)]' : 'border-[var(--border-default)] hover:border-[var(--border-strong)]',
                disabled && 'opacity-50 cursor-not-allowed bg-[var(--surface-secondary)]'
            )"
        >
            <span :class="!modelValue ? 'text-[var(--text-muted)]' : 'text-[var(--text-primary)]'">
                {{ selectedLabel }}
            </span>
            <ChevronDown class="w-4 h-4 text-[var(--text-muted)] transition-transform" :class="{ 'rotate-180': isOpen }" />
        </div>

        <!-- Dropdown -->
        <div
            v-if="isOpen"
            class="absolute z-50 w-full mt-1 bg-[var(--surface-elevated)] border border-[var(--border-default)] rounded-lg shadow-lg overflow-hidden py-1 max-h-60 flex flex-col"
        >
            <!-- Search -->
            <div class="px-2 py-1 border-b border-[var(--border-default)]">
                <div class="relative">
                    <Search class="absolute left-2 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text-muted)]" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        :placeholder="searchPlaceholder"
                        class="w-full pl-8 pr-2 py-1.5 text-sm bg-transparent border-none focus:outline-none text-[var(--text-primary)] placeholder-[var(--text-muted)]"
                        @click.stop
                    />
                </div>
            </div>

            <!-- Options -->
            <div class="overflow-y-auto max-h-[200px] p-1 space-y-0.5">
                <div v-if="loading" class="px-3 py-2 text-sm text-[var(--text-muted)] text-center">
                    Loading...
                </div>
                <div v-else-if="filteredOptions.length === 0" class="px-3 py-2 text-sm text-[var(--text-muted)] text-center">
                    No results found
                </div>
                <button
                    v-else
                    v-for="option in filteredOptions"
                    :key="getOptionValue(option)"
                    @click="selectOption(option)"
                    class="flex items-center w-full px-2 py-1.5 text-sm text-left rounded-md hover:bg-[var(--surface-secondary)] transition-colors group"
                    :class="{ 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]': modelValue === getOptionValue(option) }"
                >
                    <img 
                        v-if="getOptionImage(option)" 
                        :src="getOptionImage(option)" 
                        class="w-5 h-5 rounded-full mr-2 object-cover"
                        alt=""
                    />
                    <span class="flex-1 truncate">{{ getOptionLabel(option) }}</span>
                    <Check 
                        v-if="modelValue === getOptionValue(option)" 
                        class="w-4 h-4 text-[var(--interactive-primary)]" 
                    />
                </button>
            </div>
        </div>
    </div>
</template>
