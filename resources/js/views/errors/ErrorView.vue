<script setup>
import { computed, ref, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { 
    AlertCircle, 
    FileQuestion, 
    ShieldAlert, 
    Settings, 
    Search,
    Home,
    ArrowLeft,
    RefreshCw,
    Mail,
    Lock
} from 'lucide-vue-next';
import { Button } from '@/components/ui';
import { animate, stagger } from 'animejs';

const props = defineProps({
    code: {
        type: [String, Number],
        default: '404'
    }
});

const router = useRouter();
const searchQuery = ref('');
const isHovering = ref(false);

// Typewriter effect state
const typedMessage = ref('');
const fullMessage = ref('');

const typeText = async (text) => {
    typedMessage.value = '';
    for (let i = 0; i < text.length; i++) {
        typedMessage.value += text[i];
        await new Promise(r => setTimeout(r, 30)); // 30ms typing speed
    }
};

const config = computed(() => {
    switch (String(props.code)) {
        case '403':
            return {
                title: 'Access Restricted',
                message: 'You don\'t have the necessary permissions to view this area.',
                icon: Lock,
                color: 'text-orange-500',
                bg: 'bg-orange-500/10',
                action: 'request_access',
                codeColor: 'bg-orange-500',
                animation: 'animate-pulse' // Pulse for lock
            };
        case '500':
            return {
                title: 'System Malfunction',
                message: 'Something broke inside our machine. We are fixing it.',
                icon: Settings,
                color: 'text-red-500',
                bg: 'bg-red-500/10',
                action: 'reload',
                codeColor: 'bg-red-500',
                animation: 'animate-grind' // Custom grinding gear
            };
        case '503':
            return {
                title: 'Under Maintenance',
                message: 'Tuning the engine. We will be back shortly.',
                icon: Settings,
                color: 'text-blue-500',
                bg: 'bg-blue-500/10',
                action: 'status',
                codeColor: 'bg-blue-500',
                animation: 'animate-spin-slow' // Smooth spin
            };
        case '404':
        default:
            return {
                title: 'Page Not Found',
                message: 'The page you are looking for has vanished into the digital void.',
                icon: FileQuestion,
                color: 'text-purple-500',
                bg: 'bg-purple-500/10',
                action: 'search',
                codeColor: 'bg-purple-500',
                animation: 'animate-float'
            };
    }
});

// Trigger typing when message changes or component mounts
watch(() => config.value.message, (newMsg) => {
    fullMessage.value = newMsg;
    if (['404', '403'].includes(String(props.code))) {
        typeText(newMsg);
    } else {
        typedMessage.value = newMsg;
    }
}, { immediate: true });

const handleSearch = () => {
    if (searchQuery.value.trim()) {
        console.log('Searching for:', searchQuery.value); 
        // Implement actual search routing here if search page exists
        // router.push({ name: 'search', query: { q: searchQuery.value } });
    }
};

const reloadPage = () => {
    window.location.reload();
};

const contactSupport = () => {
    window.location.href = 'mailto:support@coresync.com';
};

// Refs for animation
const errorContainerRef = ref(null);

onMounted(() => {
    // Animate icon entrance
    animate('.error-icon-container', {
        scale: [0, 1],
        rotate: [180, 0],
        opacity: [0, 1],
        duration: 800,
        easing: 'easeOutElastic(1, 0.5)',
    });

    // Animate code badge
    animate('.error-code-badge', {
        scale: [0, 1],
        opacity: [0, 1],
        duration: 500,
        delay: 300,
        easing: 'easeOutBack',
    });

    // Animate title
    animate('.error-title', {
        opacity: [0, 1],
        translateY: [30, 0],
        duration: 600,
        delay: 400,
        easing: 'easeOutExpo',
    });

    // Animate buttons
    animate('.error-buttons button', {
        opacity: [0, 1],
        translateY: [20, 0],
        duration: 500,
        delay: stagger(100, { start: 700 }),
        easing: 'easeOutExpo',
    });

    // Animate background blobs
    animate('.error-blob', {
        translateX: [-20, 20],
        translateY: [-15, 15],
        duration: 4000,
        easing: 'easeInOutSine',
        alternate: true,
        loop: true,
    });
});
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-[var(--surface-primary)] p-4 overflow-hidden relative selection:bg-[var(--interactive-primary)] selection:text-white">
        <!-- Background Elements -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="error-blob absolute top-[20%] left-[20%] w-[500px] h-[500px] rounded-full bg-[var(--interactive-primary)]/5 blur-[100px]"></div>
            <div class="error-blob absolute bottom-[20%] right-[20%] w-[500px] h-[500px] rounded-full bg-[var(--color-error)]/5 blur-[100px]" style="animation-delay: 2s;"></div>
        </div>

        <div class="relative z-10 w-full max-w-lg flex flex-col items-center text-center">
            <!-- Icon Animation Container -->
            <div 
                class="error-icon-container mb-10 relative inline-flex items-center justify-center group opacity-0"
                @mouseenter="isHovering = true"
                @mouseleave="isHovering = false"
            >
                <!-- Ripple Effect -->
                <div 
                    class="absolute inset-0 rounded-[2.5rem] animate-ping opacity-10 duration-1000"
                    :class="config.bg"
                ></div>
                
                <!-- Main Icon Bubble -->
                <div 
                    class="h-40 w-40 rounded-[2.5rem] flex items-center justify-center transition-all duration-500 ease-spring shadow-2xl relative z-10 backdrop-blur-sm"
                    :class="[config.bg, isHovering ? 'scale-105 shadow-[var(--interactive-primary)]/20' : 'scale-100', isHovering && props.code === '503' ? 'rotate-180' : '']"
                >
                    <component 
                        :is="config.icon" 
                        class="h-20 w-20 transition-all duration-500 drop-shadow-lg" 
                        :class="[config.color, config.animation]"
                    />
                </div>

                <!-- Floating Code Badge - Centered visually on top-right corner -->
                <div class="error-code-badge absolute -top-4 -right-4 z-20 hover:-translate-y-1 transition-transform cursor-default opacity-0">
                    <div class="bg-[var(--surface-elevated)] px-3 py-1 rounded-full shadow-lg border border-[var(--border-muted)] flex items-center gap-2 ring-4 ring-[var(--surface-primary)]">
                        <div class="h-1.5 w-1.5 rounded-full animate-pulse" :class="config.codeColor"></div>
                        <span class="text-sm font-bold font-mono tracking-tight text-[var(--text-primary)]">{{ code }}</span>
                    </div>
                </div>
            </div>
            
            <!-- Content Transition -->
            <Transition 
                appear 
                enter-active-class="transition-all duration-700 ease-out delay-150"
                enter-from-class="opacity-0 translate-y-8"
                enter-to-class="opacity-100 translate-y-0"
            >
                <div class="space-y-8 w-full">
                    <div class="space-y-4">
                        <h1 class="error-title opacity-0 text-4xl sm:text-5xl font-extrabold text-[var(--text-primary)] tracking-tight">
                            {{ config.title }}
                        </h1>
                        <div class="h-12 flex items-start justify-center">
                            <p class="text-[var(--text-secondary)] text-lg leading-relaxed max-w-md mx-auto font-medium opacity-80">
                                {{ typedMessage }}<span v-if="['404', '403'].includes(String(code)) && typedMessage.length < fullMessage.length" class="animate-pulse">|</span>
                            </p>
                        </div>
                    </div>

                    <!-- Interactive Section -->
                    <div class="flex items-center justify-center w-full min-h-[3.5rem]">
                        <!-- 404 Search -->
                        <div v-if="config.action === 'search'" class="w-full max-w-xs relative group">
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Search for page..."
                                class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-[var(--border-muted)] bg-[var(--surface-secondary)]/50 backdrop-blur text-[var(--text-primary)] focus:ring-2 focus:ring-[var(--interactive-primary)]/20 focus:border-[var(--interactive-primary)] focus:bg-[var(--surface-elevated)] transition-all shadow-sm text-sm"
                                @keyup.enter="handleSearch"
                            />
                            <Search class="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-[var(--text-muted)] group-focus-within:text-[var(--interactive-primary)] transition-colors" />
                        </div>

                        <!-- 500 Reload -->
                        <div v-else-if="config.action === 'reload'" class="flex justify-center">
                            <Button size="lg" variant="outline" @click="reloadPage" class="rounded-xl border-[var(--border-muted)] hover:bg-[var(--surface-secondary)]">
                                <RefreshCw class="mr-2 h-4 w-4" />
                                Try Again
                            </Button>
                        </div>
                        
                         <!-- 503 Status Button -->
                        <div v-else-if="config.action === 'status'" class="flex justify-center">
                             <div class="flex items-center gap-2 text-sm text-[var(--text-muted)] bg-[var(--surface-secondary)] px-4 py-2 rounded-full border border-[var(--border-muted)]">
                                <span class="relative flex h-2.5 w-2.5">
                                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-[var(--color-warning)] opacity-75"></span>
                                  <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-[var(--color-warning)]"></span>
                                </span>
                                Maintenance in progress
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Buttons -->
                    <div class="error-buttons flex flex-col sm:flex-row gap-3 justify-center items-center">
                        <Button 
                            variant="ghost" 
                            @click="router.back()"
                            class="text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] rounded-lg"
                        >
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Go Back
                        </Button>
                        
                        <Button 
                            @click="router.push('/')" 
                            class="rounded-lg shadow-lg shadow-[var(--interactive-primary)]/20 hover:shadow-[var(--interactive-primary)]/40 transition-all hover:-translate-y-0.5"
                        >
                            <Home class="mr-2 h-4 w-4" />
                            Return Home
                        </Button>

                        <Button 
                            v-if="['500', '403'].includes(String(code))" 
                            variant="ghost" 
                            class="text-[var(--text-muted)] hover:text-[var(--text-primary)] rounded-lg" 
                            @click="contactSupport"
                        >
                            <Mail class="mr-2 h-4 w-4" />
                            Report
                        </Button>
                    </div>
                </div>
            </Transition>
        </div>
    </div>
</template>

<style scoped>
.ease-spring {
    transition-timing-function: cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.animate-spin-slow {
    animation: spin 8s linear infinite;
}
.animate-grind {
    animation: grind 2s ease-in-out infinite;
}
.animate-float {
    animation: float 6s ease-in-out infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@keyframes grind {
    0% { transform: rotate(0deg); }
    20% { transform: rotate(-10deg); }
    40% { transform: rotate(5deg); }
    60% { transform: rotate(-5deg); }
    80% { transform: rotate(2deg); }
    100% { transform: rotate(0deg); }
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}
</style>
