<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { ChevronDown, Check, X, Search } from 'lucide-vue-next';
import { cn } from '@/lib/utils';
import Input from './Input.vue';

const props = defineProps({
    modelValue: {
        type: Array, // Array of values (IDs)
        default: () => [],
    },
    options: {
        type: Array,
        default: () => [],
    },
    placeholder: {
        type: String,
        default: 'Select options...',
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
        type: String,
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

const selectedLabels = computed(() => {
    if (!props.modelValue || props.modelValue.length === 0) return props.placeholder;
    
    // basic summary: "3 selected"
    if (props.modelValue.length > 2) {
        return `${props.modelValue.length} selected`;
    }

    const labels = props.modelValue.map(val => {
        const opt = props.options.find(o => getOptionValue(o) === val);
        return opt ? getOptionLabel(opt) : val;
    });
    
    return labels.join(', ');
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
}

function toggleOption(option) {
    const val = getOptionValue(option);
    const newValues = [...props.modelValue];
    const index = newValues.indexOf(val);

    if (index === -1) {
        newValues.push(val);
    } else {
        newValues.splice(index, 1);
    }

    emit('update:modelValue', newValues);
    // Do not close on select for multi
}

function isSelected(option) {
    return props.modelValue.includes(getOptionValue(option));
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
            <span :class="(!modelValue || modelValue.length === 0) ? 'text-[var(--text-muted)]' : 'text-[var(--text-primary)]'">
                {{ selectedLabels }}
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
                    @click="toggleOption(option)"
                    class="flex items-center w-full px-2 py-1.5 text-sm text-left rounded-md hover:bg-[var(--surface-secondary)] transition-colors group"
                    :class="{ 'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]': isSelected(option) }"
                >
                     <div class="relative mr-2 flex items-center justify-center w-5 h-5">
                       <input type="checkbox" :checked="isSelected(option)" class="pointer-events-none w-4 h-4 rounded border-gray-300 text-[var(--interactive-primary)] focus:ring-[var(--interactive-primary)]" />
                     </div>

                    <img 
                        v-if="getOptionImage(option)" 
                        :src="getOptionImage(option)" 
                        class="w-5 h-5 rounded-full mr-2 object-cover"
                        alt=""
                    />
                    <span class="flex-1 truncate">{{ getOptionLabel(option) }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
