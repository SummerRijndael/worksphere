import { ref, onMounted } from 'vue';
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

/**
 * Composable for fingerprint functionality
 */
export function useFingerprint() {
    // Listen for consent updates
    onMounted(() => {
        window.addEventListener('consent-updated', (event: CustomEvent) => {
            if (event.detail !== 'all') {
                clearFingerprint();
            }
        });
    });

    return {
        getFingerprint,
        hasConsent,
        clearFingerprint,
        isLoading,
        cachedFingerprint,
    };
}

export default useFingerprint;
