import { defineStore } from "pinia";
import { ref } from "vue";

export const useFaqStore = defineStore(
    "faq",
    () => {
        // View State
        const activeTab = ref("categories");
        const viewMode = ref("list");
        const showAuthorColumn = ref(false);
        const perPage = ref(20);

        // Sorting State
        const categorySort = ref({
            field: "order",
            direction: "asc",
        });

        const articleSort = ref({
            field: "created_at",
            direction: "desc",
        });
        
        // Date Range Filters
        const categoryDateRange = ref({
            from: null as string | null,
            to: null as string | null,
        });
        
        const articleDateRange = ref({
            from: null as string | null,
            to: null as string | null,
        });

        // Actions
        const setSort = (type: string, field: string) => {
            const currentSort = type === 'categories' ? categorySort : articleSort;
            
            if (currentSort.value.field === field) {
                // Toggle direction
                currentSort.value.direction = currentSort.value.direction === 'asc' ? 'desc' : 'asc';
            } else {
                // New field, default to asc for text/numbers, desc for dates usually, but let's stick to simple default
                currentSort.value.field = field;
                currentSort.value.direction = 'asc';
            }
        };
        
        const clearDateRange = (type: string) => {
            if (type === 'categories') {
                categoryDateRange.value = { from: null, to: null };
            } else {
                articleDateRange.value = { from: null, to: null };
            }
        };

        return {
            activeTab,
            viewMode,
            showAuthorColumn,
            perPage,
            categorySort,
            articleSort,
            categoryDateRange,
            articleDateRange,
            setSort,
            clearDateRange,
        };
    },
    {
        persist: true,
    }
);
