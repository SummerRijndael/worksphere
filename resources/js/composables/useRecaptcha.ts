import { ref, onMounted, type Ref } from 'vue';

const RECAPTCHA_SCRIPT_ID = 'recaptcha-script';

declare global {
  interface Window {
    grecaptcha: {
      ready: (callback: () => void) => void;
      execute: (siteKey: string, options: { action: string }) => Promise<string>;
      render: (elementId: string, options: { sitekey: string, callback: (token: string) => void, theme?: string }) => void;
      reset: () => void;
    };
  }
}

interface UseRecaptchaReturn {
  executeRecaptcha: (action: string) => Promise<string | null>;
  renderV2: (elementId: string, callback: (token: string) => void) => void;
  resetV2: () => void;
  isLoaded: Ref<boolean>;
  isEnabled: Ref<boolean>;
  error: Ref<string | null>;
  siteKey: Ref<string>;
  siteKeyV2: Ref<string>;
}

/**
 * Composable for Google reCAPTCHA v3 integration.
 *
 * Usage:
 * ```ts
 * const { executeRecaptcha, isLoaded, error } = useRecaptcha();
 *
 * const handleSubmit = async () => {
 *     const token = await executeRecaptcha('login');
 *     if (token) {
 *         await api.post('/api/auth/login', { ...formData, recaptcha_token: token });
 *     }
 * };
 * ```
 */
export function useRecaptcha(): UseRecaptchaReturn {
  const isLoaded = ref(false);
  const error = ref<string | null>(null);
  const siteKey = ref(import.meta.env.VITE_RECAPTCHA_SITE_KEY || '');
  const isEnabled = ref(!!import.meta.env.VITE_RECAPTCHA_SITE_KEY);
  const siteKeyV2 = ref(import.meta.env.VITE_RECAPTCHA_V2_SITE_KEY || '');

  /**
   * Load the reCAPTCHA script if not already loaded.
   */
  const loadScript = (): Promise<void> => {
    return new Promise((resolve, reject) => {
      // If already loaded, resolve immediately
      if (window.grecaptcha && window.grecaptcha.render) {
        isLoaded.value = true;
        resolve();
        return;
      }

      // Check if script is already being loaded
      if (document.getElementById(RECAPTCHA_SCRIPT_ID)) {
        // Wait for it to load
        const checkLoaded = setInterval(() => {
          if (window.grecaptcha && window.grecaptcha.render) {
            clearInterval(checkLoaded);
            isLoaded.value = true;
            resolve();
          }
        }, 100);
        return;
      }

      // Create and append script
      const script = document.createElement('script');
      script.id = RECAPTCHA_SCRIPT_ID;
      // Load api.js without render parameter to load full library (needed for v2)
      // We append the render=siteKey for v3, but for v2 we need the library to be able to render widgets
      // Actually, loading with render=siteKey works for v3, but we might need explicit render for v2 widget
      // The best practice for hybrid is loading api.js?render=v3_site_key
      script.src = `https://www.google.com/recaptcha/api.js?render=${siteKey.value}`;
      script.async = true;
      script.defer = true;

      script.onload = (): void => {
        // Wait for grecaptcha to be ready
        window.grecaptcha.ready(() => {
          isLoaded.value = true;
          resolve();
        });
      };

      script.onerror = (): void => {
        error.value = 'Failed to load reCAPTCHA';
        reject(new Error('Failed to load reCAPTCHA script'));
      };

      document.head.appendChild(script);
    });
  };

  /**
   * Execute reCAPTCHA and get a token for the specified action.
   * 
   * @param action - The action name (e.g., 'login', 'register')
   * @returns The reCAPTCHA token or null if failed/disabled
   */
  const executeRecaptcha = async (action: string): Promise<string | null> => {
    // If reCAPTCHA is not enabled, return null (backend will skip validation)
    if (!isEnabled.value) {
      return null;
    }

    try {
      error.value = null;

      // Ensure script is loaded
      if (!isLoaded.value) {
        await loadScript();
      }

      // Execute reCAPTCHA
      const token = await window.grecaptcha.execute(siteKey.value, { action });
      return token;
    } catch (e) {
      console.error('reCAPTCHA execution failed:', e);
      error.value = 'reCAPTCHA verification failed. Please try again.';
      return null;
    }
  };

  /**
   * Render reCAPTCHA v2 widget
   */
  const renderV2 = (elementId: string, callback: (token: string) => void) => {
      if (!window.grecaptcha || !window.grecaptcha.render) {
          console.error('reCAPTCHA not loaded');
          return;
      }
      
      try {
        window.grecaptcha.render(elementId, {
            sitekey: siteKeyV2.value,
            callback: callback,
            theme: 'light'
        });
      } catch (e) {
          console.error('Failed to render reCAPTCHA v2', e);
      }
  };

  /**
   * Reset reCAPTCHA v2 widget
   */
  const resetV2 = () => {
      if (window.grecaptcha && window.grecaptcha.reset) {
          window.grecaptcha.reset();
      }
  };

  // Auto-load script on mount if enabled
  onMounted(() => {
    if (isEnabled.value && siteKey.value) {
      loadScript().catch(console.error);
    }
  });

  return {
    executeRecaptcha,
    renderV2,
    resetV2,
    isLoaded,
    isEnabled,
    error,
    siteKey,
    siteKeyV2
  };
}

export default useRecaptcha;
