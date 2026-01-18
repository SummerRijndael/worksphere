<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { X, Cookie, Shield } from 'lucide-vue-next';

const CONSENT_KEY = 'cookie_consent';
const showBanner = ref(false);
const consent = ref<'all' | 'necessary' | null>(null);

const checkConsent = () => {
    const stored = localStorage.getItem(CONSENT_KEY);
    if (stored) {
        consent.value = stored as 'all' | 'necessary';
        showBanner.value = false;
    } else {
        showBanner.value = true;
    }
};

const acceptAll = () => {
    consent.value = 'all';
    localStorage.setItem(CONSENT_KEY, 'all');
    document.cookie = `cookie_consent=all; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax`;
    showBanner.value = false;
    window.dispatchEvent(new CustomEvent('consent-updated', { detail: 'all' }));
};

const acceptNecessary = () => {
    consent.value = 'necessary';
    localStorage.setItem(CONSENT_KEY, 'necessary');
    document.cookie = `cookie_consent=necessary; path=/; max-age=${60 * 60 * 24 * 365}; SameSite=Lax`;
    showBanner.value = false;
    window.dispatchEvent(new CustomEvent('consent-updated', { detail: 'necessary' }));
};

onMounted(() => {
    checkConsent();
});

// Export for use in other components
defineExpose({ consent });
</script>

<template>
    <Transition name="slide-up">
        <div 
            v-if="showBanner"
            class="fixed bottom-0 left-0 right-0 z-[100] p-4 bg-[var(--surface-elevated)] border-t border-[var(--border-default)] shadow-2xl"
        >
            <div class="max-w-6xl mx-auto flex flex-col md:flex-row items-start md:items-center gap-4">
                <div class="flex items-start gap-3 flex-1">
                    <div class="p-2 rounded-lg bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)] flex-shrink-0">
                        <Cookie class="w-5 h-5" />
                    </div>
                    <div class="flex-1">
                        <h3 class="font-semibold text-[var(--text-primary)] mb-1">We value your privacy</h3>
                        <p class="text-sm text-[var(--text-secondary)] leading-relaxed">
                            We use cookies and browser fingerprinting to enhance your experience, analyze traffic, 
                            and prevent abuse. By clicking "Accept All", you consent to our use of cookies and 
                            tracking technologies as described in our 
                            <a href="/privacy" class="text-[var(--interactive-primary)] hover:underline">Privacy Policy</a>.
                        </p>
                    </div>
                </div>
                
                <div class="flex items-center gap-3 flex-shrink-0 w-full md:w-auto">
                    <button 
                        @click="acceptNecessary"
                        class="flex-1 md:flex-none px-4 py-2 text-sm font-medium rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)] text-[var(--text-primary)] hover:bg-[var(--surface-tertiary)] transition-colors"
                    >
                        Necessary Only
                    </button>
                    <button 
                        @click="acceptAll"
                        class="flex-1 md:flex-none px-4 py-2 text-sm font-medium rounded-lg bg-[var(--interactive-primary)] text-white hover:bg-[var(--interactive-primary-hover)] transition-colors flex items-center justify-center gap-2"
                    >
                        <Shield class="w-4 h-4" />
                        Accept All
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.slide-up-enter-active,
.slide-up-leave-active {
    transition: transform 0.3s ease, opacity 0.3s ease;
}

.slide-up-enter-from,
.slide-up-leave-to {
    transform: translateY(100%);
    opacity: 0;
}
</style>
