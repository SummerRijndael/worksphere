import { ref } from 'vue';
import FingerprintJS from '@fingerprintjs/fingerprintjs';

const CONSENT_KEY = 'cookie_consent';
const FINGERPRINT_KEY = 'visitor_fingerprint';

// Cached fingerprint for the session
const cachedFingerprint = ref<string | null>(null);
const isLoading = ref(false);

/**
 * Check if user has given consent for fingerprinting
 */
const hasConsent = (): boolean => {
    const consent = localStorage.getItem(CONSENT_KEY);
    return consent === 'all';
};

/**
 * Get the visitor fingerprint
 * Only generates if consent has been given
 */
const getFingerprint = async (): Promise<string | null> => {
    // If already cached, return immediately
    if (cachedFingerprint.value) {
        return cachedFingerprint.value;
    }

    // Check sessionStorage first
    const stored = sessionStorage.getItem(FINGERPRINT_KEY);
    if (stored) {
        cachedFingerprint.value = stored;
        return stored;
    }

    // Only generate fingerprint if consent given
    if (!hasConsent()) {
        console.log('[Fingerprint] Skipped: No consent given (must be "all")');
        return null;
    }

    isLoading.value = true;
    try {
        const fp = await FingerprintJS.load();
        const result = await fp.get();
        const visitorId = result.visitorId;

        console.log('[Fingerprint] Generated visitor ID:', visitorId);

        // Cache in sessionStorage (cleared on browser close)
        sessionStorage.setItem(FINGERPRINT_KEY, visitorId);
        cachedFingerprint.value = visitorId;

        return visitorId;
    } catch (error) {
        console.error('Failed to generate fingerprint:', error);
        return null;
    } finally {
        isLoading.value = false;
    }
};

/**
 * Clear cached fingerprint (useful when consent is revoked)
 */
const clearFingerprint = () => {
    cachedFingerprint.value = null;
    sessionStorage.removeItem(FINGERPRINT_KEY);
};

// Global event listener setup (only once)
let isListenerSetup = false;
const setupConsentListener = () => {
    if (isListenerSetup) return;
    if (typeof window === 'undefined') return;

    window.addEventListener('consent-updated', (event: any) => { // Type cast to any to avoid CustomEvent issues
        if (event.detail !== 'all') {
            clearFingerprint();
        }
    });
    isListenerSetup = true;
};

/**
 * Composable for fingerprint functionality
 */
export function useFingerprint() {
    // Attempt to base the listener on lifecycle if inside component, otherwise just run it globally?
    // Actually, `window.addEventListener` doesn't need to be in `onMounted` if we just want it to run.
    // But we want to clean it up? No, this is a global store-like composable.
    
    // Simplest fix: Just allow the listener to be set up lazily when the composable is accessed, 
    // but OUTSIDE of onMounted if we want it to work in router. 
    // However, strictly speaking, we only need to listen for consent updates if we are active.
    
    setupConsentListener();

    return {
        getFingerprint,
        hasConsent,
        clearFingerprint,
        isLoading,
        cachedFingerprint,
    };
}

export default useFingerprint;
