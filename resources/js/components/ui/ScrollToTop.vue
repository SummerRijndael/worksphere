<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { ArrowUp } from 'lucide-vue-next';

const isVisible = ref(false);
const scrollThreshold = 300;

function handleScroll() {
    isVisible.value = window.scrollY > scrollThreshold;
}

function scrollToTop() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    });
}

onMounted(() => {
    window.addEventListener('scroll', handleScroll, { passive: true });
    handleScroll(); // Check initial state
});

onUnmounted(() => {
    window.removeEventListener('scroll', handleScroll);
});
</script>

<template>
    <Transition name="scale-fade">
        <button
            v-if="isVisible"
            @click="scrollToTop"
            class="fixed bottom-24 right-6 z-50 p-3 rounded-full bg-[var(--interactive-primary)] text-white shadow-lg shadow-[var(--color-primary-500)]/30 hover:bg-[var(--interactive-primary-hover)] hover:scale-110 active:scale-95 transition-all duration-200"
            aria-label="Scroll to top"
        >
            <ArrowUp class="h-5 w-5" />
        </button>
    </Transition>
</template>
