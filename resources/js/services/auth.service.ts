import { BaseService } from './base.service';
import type {
  LoginRequest,
  RegisterRequest,
  AuthResponse,
  ApiResponse,
} from '@/types/api';
import type { User } from '@/types/models/user';
import { validateOrThrow } from '@/utils/validation';
import { loginSchema, registerSchema, twoFactorSchema } from '@/schemas/auth.schemas';

export class AuthService extends BaseService {
  /**
   * Get CSRF cookie
   */
  async getCsrfCookie(): Promise<void> {
    await this.api.get('/sanctum/csrf-cookie');
  }

  /**
   * Login user
   */
  async login(credentials: LoginRequest): Promise<AuthResponse> {
    try {
      // Validate input
      const validatedData = validateOrThrow(loginSchema, credentials);

      // Get CSRF cookie first
      await this.getCsrfCookie();

      // If reCAPTCHA token placeholder is present (from view), generate a fresh one
      if (credentials.recaptcha_token === 'generate_fresh') {
        // Dynamic import to avoid circular dependencies if any, though standard import is fine usually.
        // But useRecaptcha is a composable, usually used in setup().
        // Using it in a service class is tricky because composables rely on injection context (Vue instance).
        // However, useRecaptcha implementation I saw earlier relies on `window.grecaptcha` and ref/computed.
        // It DOES NOT seem to rely on `provide/inject` for core logic, just for lifecycle `onMounted`.
        // Let's check `useRecaptcha.ts` again. content showed:
        // `import { ref, onMounted, type Ref } from 'vue';`
        // `onMounted` causes warning if outside component.
        // BUT we can extract the `executeRecaptcha` logic or use it carefully.
        
        // Better approach: changing architecture slightly.
        // The service is a singleton class. defineStore `auth.ts` uses it.
        // Composables should typically be used in components or stores (if pinia supports it, which it does).
        
        // Wait, `useRecaptcha` calls `onMounted`. If I call `useRecaptcha()` here, it will try to register a hook and fail/warn 
        // if not inside `setup()`.
        
        // Let's look at `useRecaptcha.ts` again.
        
        // It exports `useRecaptcha` function. Inside: `const isLoaded = ref(false); ... onMounted(...)`.
        // Be careful. 
        // Maybe I should refactor `useRecaptcha` to separate the "load/execute" logic from the "composable lifecycle" logic?
        // Or simply directly interact with `window.grecaptcha` here?
        
        // Direct interaction with `window.grecaptcha` is safest in a non-setup context.
        // However, `useRecaptcha` handles the loading.
        
        // I will implement a safer way:
        // 1. Check if `window.grecaptcha` exists.
        // 2. If so, execute.
        // 3. Since the view already initialized `useRecaptcha`, the script SHOULD be loaded.
        
        if (typeof window !== 'undefined' && window.grecaptcha && window.grecaptcha.execute) {
             const siteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY;
             if (siteKey) {
                 const token = await window.grecaptcha.execute(siteKey, { action: 'login' });
                 validatedData.recaptcha_token = token;
             }
        }
      } else if (credentials.recaptcha_token) {
          // If a token was passed explicitly (legacy or retry), use it.
          validatedData.recaptcha_token = credentials.recaptcha_token;
      }

      const response = await this.api.post<ApiResponse<AuthResponse>>(
        '/api/login',
        validatedData
      );

      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Register user
   */
  async register(data: RegisterRequest): Promise<AuthResponse> {
    console.log('[AuthService] register called');
    try {
      console.log('[AuthService] Validating registration data');
      const validatedData = validateOrThrow(registerSchema, data);
      console.log('[AuthService] Validation passed');

      console.log('[AuthService] Getting CSRF cookie');
      await this.getCsrfCookie();

      // If reCAPTCHA token placeholder is present (from view), generate a fresh one
      if (data.recaptcha_token === 'generate_fresh') {
          if (typeof window !== 'undefined' && window.grecaptcha && window.grecaptcha.execute) {
               const siteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY;
               if (siteKey) {
                   const token = await window.grecaptcha.execute(siteKey, { action: 'register' });
                   validatedData.recaptcha_token = token;
               }
          }
      } else if (data.recaptcha_token) {
          validatedData.recaptcha_token = data.recaptcha_token;
      }

      console.log('[AuthService] Sending POST request to /api/register');
      const response = await this.api.post<ApiResponse<AuthResponse>>(
        '/api/register',
        validatedData
      );

      console.log('[AuthService] Response received:', {
        status: response.status,
        hasData: !!response.data,
        dataKeys: response.data ? Object.keys(response.data) : [],
      });

      const extractedData = this.extractData(response);
      console.log('[AuthService] Data extracted successfully');
      return extractedData;
    } catch (error) {
      console.error('[AuthService] Registration error caught:', error);
      return this.handleError(error);
    }
  }

  /**
   * Verify 2FA code - returns full response including user data
   */
  async verify2FA(code: string, method: string = 'totp'): Promise<{ message: string; redirect: string; user: User }> {
    try {
      const validatedData = validateOrThrow(twoFactorSchema, { code, method });

      await this.getCsrfCookie();

      const response = await this.api.post<ApiResponse<{ message: string; redirect: string; user: User }>>(
        '/api/two-factor-challenge',
        validatedData
      );

      // Refresh CSRF cookie after successful 2FA verification
      // Session regeneration on backend creates new CSRF token, 
      // we need to fetch it before subsequent API calls
      await this.getCsrfCookie();

      // The response from TwoFactorController is NOT wrapped in a 'data' property
      // It returns { message, redirect, user } directly.
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Logout user
   */
  async logout(): Promise<void> {
    try {
      await this.api.post('/api/logout');
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Fetch current user
   */
  async fetchUser(): Promise<User> {
    try {
      const response = await this.api.get<ApiResponse<User>>(`/api/user?_=${Date.now()}`);
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Send password reset link
   */
  async forgotPassword(email: string): Promise<{ message: string }> {
    try {
      await this.getCsrfCookie();

      const response = await this.api.post<ApiResponse<{ message: string }>>(
        '/api/forgot-password',
        { email }
      );

      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }
}

// Export singleton instance
export const authService = new AuthService();
