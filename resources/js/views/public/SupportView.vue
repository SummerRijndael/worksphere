<script setup lang="ts">
import { ref } from 'vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { Card, Button, PageLoader } from '@/components/ui';
import { Send, CheckCircle2, AlertCircle } from 'lucide-vue-next';
import axios from 'axios';
import useRecaptcha from '@/composables/useRecaptcha';
import RecaptchaChallengeModal from '@/components/common/RecaptchaChallengeModal.vue';

const { executeRecaptcha } = useRecaptcha();
const showChallenge = ref(false);

const isLoading = ref(false);
const isSuccess = ref(false);
const error = ref<string | null>(null);
const result = ref<any>(null);

const form = ref({
    name: '',
    email: '',
    title: '',
    category: '', // Optional, frontend select
    description: '',
});

const submitTicket = async () => {
    isLoading.value = true;
    error.value = null;
    try {
        const token = await executeRecaptcha('support_ticket');
        if (!token) {
            error.value = 'Security check failed. Please refresh and try again.';
            return;
        }

        const response = await axios.post('/api/public/tickets', {
            ...form.value,
            recaptcha_token: token
        });
        result.value = response.data;
        isSuccess.value = true;
        // Reset form
        form.value = { name: '', email: '', title: '', category: '', description: '' };
    } catch (e: any) {
        if (e.response?.data?.requires_challenge) {
            showChallenge.value = true;
            return;
        }
        error.value = e.response?.data?.message || 'Failed to submit ticket. Please try again.';
    } finally {
        isLoading.value = false;
    }
};

const handleChallengeVerified = async (v2Token: string) => {
    showChallenge.value = false;
    isLoading.value = true;
    error.value = null;
    try {
        const response = await axios.post('/api/public/tickets', {
            ...form.value,
            recaptcha_token: 'fallback-initiated',
            recaptcha_v2_token: v2Token
        });
        result.value = response.data;
        isSuccess.value = true;
        form.value = { name: '', email: '', title: '', category: '', description: '' };
    } catch (e: any) {
        error.value = e.response?.data?.message || 'Failed to submit ticket (challenge failed).';
    } finally {
        isLoading.value = false;
    }
};
</script>

<template>
    <PublicLayout>
        <div class="max-w-2xl mx-auto px-4 py-16">
            <div class="text-center mb-12">
                <h1 class="text-3xl font-bold text-[var(--text-primary)] mb-4">Submit a Request</h1>
                <p class="text-[var(--text-secondary)]">
                    We're here to help! Fill out the form below and we'll get back to you as soon as possible.
                </p>
            </div>

            <Card padding="lg" class="relative overflow-hidden">
                <!-- Success State -->
                <div v-if="isSuccess" class="text-center py-12">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-green-100 text-green-600 mb-6">
                        <CheckCircle2 class="h-8 w-8" />
                    </div>
                    <h2 class="text-2xl font-bold text-[var(--text-primary)] mb-2">Request Submitted!</h2>
                    <p class="text-[var(--text-secondary)] mb-6">
                        Your ticket number is <span class="font-mono font-bold text-[var(--text-primary)]">#{{ result?.ticket_number }}</span>. <br>
                        We've sent a confirmation email to you.
                    </p>
                    <Button @click="isSuccess = false">Submit Another Request</Button>
                </div>

                <!-- Form State -->
                <form v-else @submit.prevent="submitTicket" class="space-y-6">
                    <div v-if="error" class="p-4 rounded-lg bg-red-50 text-red-600 flex items-center gap-2">
                        <AlertCircle class="h-5 w-5" />
                        <span>{{ error }}</span>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Your Name</label>
                            <input v-model="form.name" type="text" required class="input" placeholder="John Doe" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Email Address</label>
                            <input v-model="form.email" type="email" required class="input" placeholder="john@example.com" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Subject</label>
                        <input v-model="form.title" type="text" required class="input" placeholder="Briefly describe your issue" />
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-[var(--text-primary)] mb-1.5">Description</label>
                        <textarea v-model="form.description" required rows="5" class="input resize-none" placeholder="Please provide as much detail as possible..."></textarea>
                    </div>

                    <div class="flex justify-end">
                        <Button type="submit" :loading="isLoading" size="lg" class="w-full sm:w-auto">
                            Submit Request
                            <Send class="h-4 w-4 ml-2" />
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    </PublicLayout>
    
    <RecaptchaChallengeModal 
        :show="showChallenge" 
        @close="showChallenge = false"
        @verified="handleChallengeVerified"
    />
</template>
