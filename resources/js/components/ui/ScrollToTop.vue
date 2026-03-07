<script setup lang="ts">
import { ref, onMounted, onUnmounted } from "vue";
import { ArrowUp } from "lucide-vue-next";

const isVisible = ref(false);
const scrollProgress = ref(0);
const scrollThreshold = 300;

function handleScroll() {
    const scrollY = window.scrollY;
    isVisible.value = scrollY > scrollThreshold;

    // Calculate scroll progress percentage
    const height = document.documentElement.scrollHeight - window.innerHeight;
    if (height > 0) {
        scrollProgress.value = (scrollY / height) * 100;
    } else {
        scrollProgress.value = 0;
    }
}

function scrollToTop() {
    window.scrollTo({
        top: 0,
        behavior: "smooth",
    });
}

onMounted(() => {
    window.addEventListener("scroll", handleScroll, { passive: true });
    handleScroll(); // Check initial state
});

onUnmounted(() => {
    window.removeEventListener("scroll", handleScroll);
});
</script>

<template>
    <Transition name="scale-fade">
        <button
            v-if="isVisible"
            @click="scrollToTop"
            class="fixed bottom-28 sm:bottom-20 right-4 z-50 h-10 w-10 sm:h-12 sm:w-12 rounded-full bg-(--surface-elevated) text-(--interactive-primary) shadow-lg border border-(--border-default) hover:scale-110 active:scale-95 transition-all duration-200 flex items-center justify-center group"
            aria-label="Scroll to top"
        >
            <!-- Progress Ring -->
            <svg
                class="absolute inset-0 -rotate-90 h-full w-full p-1"
                viewBox="0 0 40 40"
            >
                <!-- Background Circle -->
                <circle
                    cx="20"
                    cy="20"
                    r="18"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-opacity="0.1"
                />
                <!-- Progress Circle -->
                <circle
                    cx="20"
                    cy="20"
                    r="18"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-dasharray="113.1"
                    :stroke-dashoffset="113.1 - (scrollProgress / 100) * 113.1"
                    class="transition-[stroke-dashoffset] duration-75"
                />
            </svg>

            <ArrowUp
                class="h-5 w-5 relative z-10 group-hover:-translate-y-0.5 transition-transform"
            />
        </button>
    </Transition>
</template>
