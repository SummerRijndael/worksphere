<script setup>
import { ref, onMounted, watch } from 'vue';
import { 
    CheckCircleIcon, 
    XCircleIcon, 
    UserIcon,
    ChatBubbleLeftEllipsisIcon,
    StarIcon,
    ArrowPathIcon,
    MagnifyingGlassIcon,
    ChevronLeftIcon,
    ChevronRightIcon
} from '@heroicons/vue/24/outline';
import { StarIcon as StarSolidIcon } from '@heroicons/vue/24/solid';
import api from '@/lib/api';
import { toast } from 'vue-sonner';
import debounce from 'lodash/debounce';

const reviews = ref([]);
const loading = ref(true);
const moderatingId = ref(null);
const search = ref('');
const perPage = ref(20);
const currentPage = ref(1);
const totalPages = ref(1);
const totalItems = ref(0);

const fetchReviews = async (page = 1) => {
    loading.value = true;
    currentPage.value = page;
    try {
        const response = await api.get('/api/admin/reviews', {
            params: {
                page: currentPage.value,
                per_page: perPage.value,
                search: search.value || undefined
            }
        });
        
        const { data, meta } = response.data;
        reviews.value = data || [];
        totalPages.value = meta?.last_page || 1;
        totalItems.value = meta?.total || 0;
    } catch (error) {
        console.error('Failed to fetch reviews for moderation', error);
        toast.error('Failed to load reviews');
    } finally {
        loading.value = false;
    }
};

const debouncedFetch = debounce(() => fetchReviews(1), 300);

watch(search, debouncedFetch);
watch(perPage, () => fetchReviews(1));

const togglePublished = async (review) => {
    moderatingId.value = review.id;
    const newStatus = !review.is_published;
    
    try {
        await api.put(`/api/admin/reviews/${review.id}/status`, {
            is_published: newStatus
        });
        
        review.is_published = newStatus;
        toast.success(newStatus ? 'Review approved and published' : 'Review unpublished');
        
        // Emit event to refresh parent sentiment stats if needed
        const event = new CustomEvent('review-status-updated');
        window.dispatchEvent(event);
    } catch (error) {
        console.error('Failed to update review status', error);
        toast.error('Failed to update review status');
    } finally {
        moderatingId.value = null;
    }
};

onMounted(() => {
    fetchReviews();
});

defineExpose({ refresh: fetchReviews });
</script>

<template>
    <div class="bg-(--surface-card) rounded-2xl shadow-sm border border-(--border-default) overflow-hidden flex flex-col min-h-[400px] max-h-[800px]">
        <!-- Header -->
        <div class="p-6 border-b border-(--border-default) space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-(--text-primary) flex items-center gap-2">
                    <ChatBubbleLeftEllipsisIcon class="w-5 h-5 text-indigo-500" />
                    Review Management
                </h3>
                <div class="flex items-center gap-2">
                    <span class="text-sm text-(--text-tertiary)">{{ totalItems }} total reviews</span>
                    <button 
                        @click="fetchReviews(currentPage)" 
                        class="p-2 hover:bg-(--surface-secondary) rounded-lg transition-colors"
                        :disabled="loading"
                    >
                        <ArrowPathIcon class="w-5 h-5 text-(--text-tertiary)" :class="{ 'animate-spin': loading }" />
                    </button>
                </div>
            </div>

            <!-- Search and Per Page -->
            <div class="flex flex-col sm:flex-row gap-4">
                <div class="relative flex-1">
                    <MagnifyingGlassIcon class="w-5 h-5 absolute left-3 top-1/2 -translate-y-1/2 text-(--text-tertiary)" />
                    <input 
                        v-model="search"
                        type="text" 
                        placeholder="Search reviews by content, name or email..."
                        class="w-full pl-10 pr-4 py-2 rounded-xl bg-(--surface-secondary) border border-(--border-subtle) text-sm focus:outline-none focus:border-indigo-500 transition-colors"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-xs font-medium text-(--text-secondary) whitespace-nowrap">View:</label>
                    <select 
                        v-model="perPage"
                        class="bg-(--surface-secondary) border border-(--border-subtle) rounded-lg py-1 px-2 text-sm focus:outline-none focus:border-indigo-500"
                    >
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Scrollable Content -->
        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <div v-if="loading && reviews.length === 0" class="p-12 flex flex-col items-center justify-center space-y-3">
                <ArrowPathIcon class="w-8 h-8 animate-spin text-(--interactive-primary)" />
                <p class="text-(--text-secondary)">Loading reviews...</p>
            </div>

            <div v-else-if="reviews.length === 0" class="p-12 text-center">
                <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-(--surface-secondary) mb-4">
                    <CheckCircleIcon class="w-6 h-6 text-(--text-tertiary)" />
                </div>
                <p class="text-(--text-primary) font-medium">No reviews to moderate</p>
                <p class="text-(--text-secondary) text-sm mt-1">New user feedback will appear here.</p>
            </div>

            <div v-else class="divide-y divide-(--border-subtle)">
                <div v-for="review in reviews" :key="review.id" class="p-6 hover:bg-(--surface-secondary)/30 transition-colors">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-4 flex-1">
                            <div class="w-10 h-10 rounded-full bg-violet-500/10 flex items-center justify-center shrink-0">
                                <UserIcon class="w-6 h-6 text-violet-500" />
                            </div>
                            <div class="space-y-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-(--text-primary) truncate">{{ review.user.name }}</span>
                                    <span class="text-xs text-(--text-tertiary) px-2 py-0.5 rounded-full bg-(--surface-tertiary)">
                                        {{ new Date(review.created_at).toLocaleDateString() }}
                                    </span>
                                </div>
                                
                                <div class="flex items-center gap-0.5 mb-2">
                                    <template v-for="i in 5" :key="i">
                                        <StarSolidIcon v-if="i <= review.rating" class="w-4 h-4 text-amber-400" />
                                        <StarIcon v-else class="w-4 h-4 text-zinc-600" />
                                    </template>
                                </div>

                                <p class="text-(--text-secondary) text-sm leading-relaxed italic border-l-2 border-(--border-subtle) pl-3">
                                    "{{ review.comment }}"
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-3 shrink-0">
                            <span 
                                class="text-xs font-bold uppercase tracking-wider px-2 py-1 rounded"
                                :class="review.is_published ? 'bg-emerald-500/10 text-emerald-500' : 'bg-amber-500/10 text-amber-500'"
                            >
                                {{ review.is_published ? 'Published' : 'Pending' }}
                            </span>
                            
                            <button
                                @click="togglePublished(review)"
                                class="btn btn-sm flex items-center gap-2"
                                :class="review.is_published ? 'btn-danger' : 'btn-primary'"
                                :disabled="moderatingId === review.id"
                            >
                                <template v-if="moderatingId === review.id">
                                    <ArrowPathIcon class="w-4 h-4 animate-spin" />
                                </template>
                                <template v-else>
                                    <CheckCircleIcon v-if="!review.is_published" class="w-4 h-4" />
                                    <XCircleIcon v-else class="w-4 h-4" />
                                </template>
                                {{ review.is_published ? 'Unpublish' : 'Approve' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Pagination Footer -->
        <div class="p-4 bg-(--surface-secondary)/50 border-t border-(--border-default) flex items-center justify-between">
            <div class="text-xs text-(--text-tertiary)">
                Page {{ currentPage }} of {{ totalPages }}
            </div>
            <div class="flex items-center gap-1">
                <button 
                    @click="fetchReviews(currentPage - 1)"
                    :disabled="currentPage === 1 || loading"
                    class="p-1 px-3 rounded-lg hover:bg-(--surface-card) disabled:opacity-30 transition-colors flex items-center gap-1 text-sm font-medium border border-(--border-subtle) bg-(--surface-card)"
                >
                    <ChevronLeftIcon class="w-4 h-4" />
                    Prev
                </button>
                <button 
                    @click="fetchReviews(currentPage + 1)"
                    :disabled="currentPage === totalPages || loading"
                    class="p-1 px-3 rounded-lg hover:bg-(--surface-card) disabled:opacity-30 transition-colors flex items-center gap-1 text-sm font-medium border border-(--border-subtle) bg-(--surface-card)"
                >
                    Next
                    <ChevronRightIcon class="w-4 h-4" />
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: var(--border-default);
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: var(--text-tertiary);
}
</style>
