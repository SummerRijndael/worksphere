<script setup lang="ts">
import { ref } from 'vue';
import { Star } from 'lucide-vue-next';
import Modal from '@/components/ui/Modal.vue';
import Button from '@/components/ui/Button.vue';
import axios from 'axios';
import { toast } from 'vue-sonner';

const props = defineProps<{
    open: boolean;
}>();

const emit = defineEmits(['update:open', 'submitted']);

const rating = ref(0);
const hoverRating = ref(0);
const comment = ref('');
const isSubmitting = ref(false);

const setRating = (val: number) => {
    rating.value = val;
};

const handleOpenChange = (val: boolean) => {
    emit('update:open', val);
    if (!val) {
        // Reset form on close
        rating.value = 0;
        comment.value = '';
    }
};

const submitReview = async () => {
    if (rating.value === 0) {
        toast.error('Please select a rating');
        return;
    }

    if (!comment.value.trim()) {
        toast.error('Please enter your feedback');
        return;
    }

    isSubmitting.value = true;
    try {
        await axios.post('/api/user/reviews', {
            rating: rating.value,
            comment: comment.value,
        });
        toast.success('Review submitted! It will appear after moderation.');
        handleOpenChange(false);
        emit('submitted');
    } catch (error: any) {
        const message = error.response?.data?.message || 'Failed to submit review';
        toast.error(message);
    } finally {
        isSubmitting.value = false;
    }
};
</script>

<template>
    <Modal
        :open="open"
        @update:open="handleOpenChange"
        title="Share your experience"
        description="Your feedback helps us improve WorkSphere for everyone."
        size="md"
    >
        <div class="space-y-6">
            <!-- Star Rating -->
            <div class="flex flex-col items-center justify-center space-y-2">
                <span class="text-sm font-medium text-(--text-secondary)">How would you rate us?</span>
                <div class="flex items-center gap-1">
                    <button
                        v-for="i in 5"
                        :key="i"
                        type="button"
                        class="p-1 transition-transform hover:scale-110 focus:outline-none"
                        @click="setRating(i)"
                        @mouseenter="hoverRating = i"
                        @mouseleave="hoverRating = 0"
                    >
                        <Star
                            :class="[
                                'h-8 w-8 transition-colors',
                                (hoverRating || rating) >= i
                                    ? 'fill-yellow-400 text-yellow-400'
                                    : 'text-(--text-secondary) opacity-40'
                            ]"
                        />
                    </button>
                </div>
            </div>

            <!-- Comment -->
            <div class="space-y-2">
                <label for="comment" class="text-sm font-medium text-(--text-primary)">
                    Care to share more?
                </label>
                <div class="relative">
                    <textarea
                        id="comment"
                        v-model="comment"
                        rows="4"
                        maxlength="1000"
                        class="w-full rounded-xl border border-(--border-default) bg-(--surface-primary) p-3 text-sm text-(--text-primary) placeholder:text-(--text-secondary)/50 focus:border-(--color-primary-500) focus:ring-2 focus:ring-(--color-primary-500)/20 focus:outline-none transition-all resize-none"
                        placeholder="What do you like? What can we do better?"
                    ></textarea>
                    <div class="absolute bottom-2 right-3 text-[10px] font-medium transition-colors" :class="comment.length > 900 ? 'text-amber-500' : 'text-(--text-tertiary)'">
                        {{ comment.length }} / 1000
                    </div>
                </div>
            </div>
        </div>

        <template #footer>
            <Button variant="ghost" @click="handleOpenChange(false)">Cancel</Button>
            <Button
                variant="primary"
                :loading="isSubmitting"
                :disabled="isSubmitting || rating === 0"
                @click="submitReview"
            >
                Submit Review
            </Button>
        </template>
    </Modal>
</template>
