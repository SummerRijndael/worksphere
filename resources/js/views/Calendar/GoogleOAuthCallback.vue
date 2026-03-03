<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-950 text-white font-sans">
        <div class="text-center space-y-4 px-8">
            <!-- Animated spinner / state icon -->
            <div class="relative mx-auto w-16 h-16">
                <svg
                    v-if="state === 'loading'"
                    class="animate-spin w-16 h-16 text-blue-500"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                </svg>

                <div v-else-if="state === 'success'" class="w-16 h-16 rounded-full bg-green-500/20 flex items-center justify-center">
                    <svg class="w-8 h-8 text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>

                <div v-else-if="state === 'error'" class="w-16 h-16 rounded-full bg-red-500/20 flex items-center justify-center">
                    <svg class="w-8 h-8 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </div>
            </div>

            <div>
                <p v-if="state === 'loading'" class="text-gray-300 text-sm">Connecting to Google Calendar...</p>
                <p v-else-if="state === 'success'" class="text-green-400 font-medium">Connected! Closing window...</p>
                <p v-else-if="state === 'error'" class="text-red-400 text-sm">{{ errorMessage }}</p>
            </div>

            <button
                v-if="state === 'error'"
                @click="window.close()"
                class="mt-4 px-4 py-2 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm rounded-lg transition-colors"
            >
                Close Window
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '@/lib/api';

const state = ref('loading'); // 'loading' | 'success' | 'error'
const errorMessage = ref('Failed to connect. Please close and try again.');

const TARGET_ORIGIN = window.location.origin;

onMounted(async () => {
    const params = new URLSearchParams(window.location.search);
    const code = params.get('code');
    const error = params.get('error');

    if (error) {
        state.value = 'error';
        errorMessage.value = 'Google access was denied.';
        notifyOpener({ success: false, error: 'access_denied' });
        return;
    }

    if (!code) {
        state.value = 'error';
        errorMessage.value = 'Missing authorization code.';
        notifyOpener({ success: false, error: 'no_code' });
        return;
    }

    try {
        await api.post('/api/calendar/oauth/connect', { code });

        state.value = 'success';
        notifyOpener({ success: true });

        // Auto-close after brief success display
        setTimeout(() => {
            window.close();
        }, 1200);
    } catch (err) {
        console.error('[GoogleOAuthCallback] Connect failed:', err);
        state.value = 'error';
        errorMessage.value = err?.response?.data?.message || 'Connection failed. Please try again.';
        notifyOpener({ success: false, error: 'api_error' });
    }
});

function notifyOpener(payload) {
    if (window.opener && !window.opener.closed) {
        window.opener.postMessage(
            { type: 'google-calendar-oauth', ...payload },
            TARGET_ORIGIN
        );
    }
}
</script>
