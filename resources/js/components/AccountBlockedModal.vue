<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '@/stores/auth';
import { Button, Card } from '@/components/ui';
import { ShieldX, Clock, LogOut, Mail } from 'lucide-vue-next';

const props = defineProps({
    status: {
        type: String,
        required: true,
        validator: (v) => ['blocked', 'suspended'].includes(v),
    },
    reason: String,
    changedBy: Object,
    suspendedUntil: String,
});

const emit = defineEmits(['logout']);

const router = useRouter();
const authStore = useAuthStore();

const countdown = ref(30);
let countdownInterval = null;

const statusConfig = computed(() => ({
    blocked: {
        title: 'Account Blocked',
        icon: ShieldX,
        iconColor: 'text-red-500',
        bgColor: 'bg-red-500/10',
        borderColor: 'border-red-500/30',
    },
    suspended: {
        title: 'Account Suspended',
        icon: Clock,
        iconColor: 'text-amber-500',
        bgColor: 'bg-amber-500/10',
        borderColor: 'border-amber-500/30',
    },
})[props.status]);

const formattedSuspendedUntil = computed(() => {
    if (!props.suspendedUntil) return null;
    return new Date(props.suspendedUntil).toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
});

function startCountdown() {
    countdownInterval = setInterval(() => {
        countdown.value--;
        if (countdown.value <= 0) {
            clearInterval(countdownInterval);
            handleLogout();
        }
    }, 1000);
}

async function handleLogout() {
    try {
        await authStore.logout();
    } catch (e) {
        // Ignore logout errors
    }
    router.push({ name: 'login' });
    emit('logout');
}

onMounted(() => {
    startCountdown();
    document.body.style.overflow = 'hidden';
});

onUnmounted(() => {
    if (countdownInterval) {
        clearInterval(countdownInterval);
    }
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/80 backdrop-blur-sm">
            <Card
                class="w-full max-w-lg mx-4 text-center border-2"
                :class="statusConfig.borderColor"
                padding="xl"
            >
                <!-- Icon -->
                <div
                    class="mx-auto w-20 h-20 rounded-full flex items-center justify-center mb-6"
                    :class="statusConfig.bgColor"
                >
                    <component
                        :is="statusConfig.icon"
                        class="w-10 h-10"
                        :class="statusConfig.iconColor"
                    />
                </div>

                <!-- Title -->
                <h1 class="text-2xl font-bold text-[var(--text-primary)] mb-2">
                    {{ statusConfig.title }}
                </h1>

                <!-- Subtitle -->
                <p class="text-[var(--text-secondary)] mb-6">
                    Your account access has been restricted.
                </p>

                <!-- Reason -->
                <div
                    v-if="reason"
                    class="bg-[var(--surface-secondary)] rounded-xl p-4 mb-6 text-left"
                >
                    <p class="text-sm text-[var(--text-muted)] mb-1">Reason:</p>
                    <p class="text-[var(--text-primary)]">{{ reason }}</p>
                </div>

                <!-- Suspended Until -->
                <div
                    v-if="status === 'suspended' && formattedSuspendedUntil"
                    class="mb-6"
                >
                    <p class="text-sm text-[var(--text-muted)]">Suspension ends:</p>
                    <p class="text-lg font-semibold text-[var(--text-primary)]">
                        {{ formattedSuspendedUntil }}
                    </p>
                </div>

                <!-- Changed By -->
                <p v-if="changedBy" class="text-sm text-[var(--text-muted)] mb-6">
                    Action taken by: <span class="font-medium text-[var(--text-secondary)]">{{ changedBy.name }}</span>
                </p>

                <!-- Countdown -->
                <div class="bg-[var(--surface-tertiary)] rounded-xl p-4 mb-6">
                    <div class="flex items-center justify-center gap-2 text-[var(--text-secondary)]">
                        <Clock class="w-5 h-5" />
                        <span>You will be logged out in</span>
                        <span class="text-2xl font-bold text-[var(--color-primary-500)]">{{ countdown }}</span>
                        <span>seconds</span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex flex-col gap-3">
                    <Button variant="outline" fullWidth @click="handleLogout">
                        <LogOut class="w-4 h-4" />
                        Log Out Now
                    </Button>
                    <a
                        href="mailto:support@worksphere.io"
                        class="flex items-center justify-center gap-2 text-sm text-[var(--text-secondary)] hover:text-[var(--interactive-primary)] transition-colors"
                    >
                        <Mail class="w-4 h-4" />
                        Contact Support
                    </a>
                </div>
            </Card>
        </div>
    </Teleport>
</template>
