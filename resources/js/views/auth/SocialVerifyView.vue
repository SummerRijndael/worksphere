<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '@/lib/api';
import { toast } from 'vue-sonner';
import { Loader2 } from 'lucide-vue-next';

const route = useRoute();
const router = useRouter();
const verifying = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
    const token = route.query.token as string;
    const email = route.query.email as string;

    if (!token) {
        error.value = 'Invalid verification link.';
        verifying.value = false;
        return;
    }

    try {
        await api.post('/api/auth/social/verify-link', { token, email });
        toast.success('Social account linked successfully! You can now login.');
        router.push({ name: 'login' });
    } catch (e: any) {
        error.value = e.response?.data?.message || 'Verification failed. Link may be expired.';
        verifying.value = false;
    }
});
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-[var(--surface-primary)] p-4">
        <div class="w-full max-w-md bg-[var(--surface-card)] rounded-xl shadow-lg p-8 text-center">
            <h2 class="text-2xl font-bold text-[var(--text-primary)] mb-4">Verifying Link...</h2>
            
            <div v-if="verifying" class="flex flex-col items-center justify-center space-y-4">
                <Loader2 class="w-10 h-10 animate-spin text-[var(--interactive-primary)]" />
                <p class="text-[var(--text-secondary)]">Please wait while we verify your social account link.</p>
            </div>

            <div v-else class="space-y-4">
                <div class="text-red-500 bg-red-50 dark:bg-red-900/10 p-4 rounded-lg">
                    {{ error }}
                </div>
                <router-link 
                    :to="{ name: 'login' }"
                    class="inline-block px-6 py-2 bg-[var(--interactive-primary)] text-white rounded-lg hover:bg-[var(--interactive-hover)] transition-colors"
                >
                    Back to Login
                </router-link>
            </div>
        </div>
    </div>
</template>
