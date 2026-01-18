<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const slides = [
    {
        id: 1,
        title: 'Streamline Your Workflow',
        description: 'Powerful tools designed to help you work smarter, not harder. Experience seamless collaboration.',
        image: 'https://images.unsplash.com/photo-1551434678-e076c223a692?w=1200&h=800&fit=crop',
        gradient: 'from-blue-600/20 to-purple-600/20',
    },
    {
        id: 2,
        title: 'Real-time Analytics',
        description: 'Get insights that matter. Track performance metrics and make data-driven decisions.',
        image: 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?w=1200&h=800&fit=crop',
        gradient: 'from-green-600/20 to-teal-600/20',
    },
    {
        id: 3,
        title: 'Secure & Reliable',
        description: 'Enterprise-grade security with 99.9% uptime. Your data is always safe and accessible.',
        image: 'https://images.unsplash.com/photo-1563986768609-322da13575f3?w=1200&h=800&fit=crop',
        gradient: 'from-orange-600/20 to-red-600/20',
    },
    {
        id: 4,
        title: 'Team Collaboration',
        description: 'Connect with your team anywhere in the world. Real-time updates and seamless communication.',
        image: 'https://images.unsplash.com/photo-1522071820081-009f0129c71c?w=1200&h=800&fit=crop',
        gradient: 'from-purple-600/20 to-pink-600/20',
    },
];

const currentIndex = ref(0);
const isTransitioning = ref(false);
let autoPlayInterval = null;

const currentSlide = computed(() => slides[currentIndex.value]);

function nextSlide() {
    if (isTransitioning.value) return;
    isTransitioning.value = true;
    currentIndex.value = (currentIndex.value + 1) % slides.length;
    setTimeout(() => {
        isTransitioning.value = false;
    }, 500);
}

function prevSlide() {
    if (isTransitioning.value) return;
    isTransitioning.value = true;
    currentIndex.value = (currentIndex.value - 1 + slides.length) % slides.length;
    setTimeout(() => {
        isTransitioning.value = false;
    }, 500);
}

function goToSlide(index) {
    if (isTransitioning.value || index === currentIndex.value) return;
    isTransitioning.value = true;
    currentIndex.value = index;
    setTimeout(() => {
        isTransitioning.value = false;
    }, 500);
    resetAutoPlay();
}

function startAutoPlay() {
    autoPlayInterval = setInterval(nextSlide, 5000);
}

function resetAutoPlay() {
    if (autoPlayInterval) {
        clearInterval(autoPlayInterval);
    }
    startAutoPlay();
}

onMounted(() => {
    startAutoPlay();
});

onUnmounted(() => {
    if (autoPlayInterval) {
        clearInterval(autoPlayInterval);
    }
});
</script>

<template>
    <div class="absolute inset-0 overflow-hidden">
        <!-- Slides -->
        <TransitionGroup name="carousel">
            <div
                v-for="(slide, index) in slides"
                v-show="index === currentIndex"
                :key="slide.id"
                class="absolute inset-0"
            >
                <!-- Background Image -->
                <div class="absolute inset-0">
                    <img
                        :src="slide.image"
                        :alt="slide.title"
                        class="h-full w-full object-cover"
                    />
                    <!-- Gradient Overlay -->
                    <div :class="['absolute inset-0 bg-gradient-to-br', slide.gradient]" />
                    <div class="absolute inset-0 bg-black/40" />
                </div>

                <!-- Content -->
                <div class="absolute inset-0 flex flex-col items-center justify-center p-12 text-center text-white">
                    <div class="max-w-2xl space-y-6">
                        <h2 class="text-4xl font-bold tracking-tight lg:text-5xl">
                            {{ slide.title }}
                        </h2>
                        <p class="text-lg text-white/80 lg:text-xl">
                            {{ slide.description }}
                        </p>
                    </div>
                </div>
            </div>
        </TransitionGroup>

        <!-- Navigation Arrows -->
        <button
            class="absolute left-6 top-1/2 -translate-y-1/2 flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-sm transition-all hover:bg-white/20"
            @click="prevSlide"
        >
            <ChevronLeft class="h-6 w-6" />
        </button>
        <button
            class="absolute right-6 top-1/2 -translate-y-1/2 flex h-12 w-12 items-center justify-center rounded-full bg-white/10 text-white backdrop-blur-sm transition-all hover:bg-white/20"
            @click="nextSlide"
        >
            <ChevronRight class="h-6 w-6" />
        </button>

        <!-- Dots -->
        <div class="absolute bottom-8 left-1/2 -translate-x-1/2 flex gap-2">
            <button
                v-for="(slide, index) in slides"
                :key="slide.id"
                :class="[
                    'h-2 rounded-full transition-all duration-300',
                    index === currentIndex
                        ? 'w-8 bg-white'
                        : 'w-2 bg-white/40 hover:bg-white/60'
                ]"
                @click="goToSlide(index)"
            />
        </div>
    </div>
</template>

<style scoped>
.carousel-enter-active,
.carousel-leave-active {
    transition: all 0.5s ease-out;
}

.carousel-enter-from {
    opacity: 0;
    transform: translateX(50px);
}

.carousel-leave-to {
    opacity: 0;
    transform: translateX(-50px);
}
</style>
