import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import type { Ref, ComputedRef } from 'vue';
import { authService } from '@/services';
import api from '@/lib/api';
import type { User } from '@/types/models/user';
import type { LoginRequest, RegisterRequest } from '@/types/api';

interface UserHints {
  hasVisited: boolean;
  publicId: string | null;
  nameHint: string | null;
  lastLoginAt: string | null;
}

interface FetchedHints {
  maskedEmail: string | null;
  avatarUrl: string | null;
  initials: string | null;
}

interface LoginResult {
  success: boolean;
  error?: string;
  requires_2fa?: boolean;
  requires_challenge?: boolean;
  methods?: string[];
}

interface AuthResult {
  success: boolean;
  error?: string;
  message?: string;
}

interface StatusChangeEvent {
  user_id: number;
  public_id: string;
  status: 'active' | 'blocked' | 'suspended' | 'disabled';
  reason: string | null;
  suspended_until: string | null;
  changed_by: {
    id: number;
    name: string;
  } | null;
}

interface RoleChangeEvent {
  user_id: number;
  public_id: string;
  from_role: string;
  to_role: string;
  reason: string;
  changed_by: {
    id: number;
    name: string;
  };
}

interface TwoFactorEnforcementEvent {
  user_id: number;
  public_id: string;
  enforced: boolean;
  allowed_methods: string[];
  enforced_by: {
    id: number;
    name: string;
  } | null;
}

export const useAuthStore = defineStore('auth', () => {
  // State
  const user: Ref<User | null> = ref(null);
  const currentTeamId: Ref<string | null> = ref(null);
  const isLoading = ref(false);

  // Real-time status change state
  const statusChangeEvent: Ref<StatusChangeEvent | null> = ref(null);
  const showBlockedModal = ref(false);
  const isSessionVerified = ref(false);

  // Real-time role change state
  const roleChangeEvent: Ref<RoleChangeEvent | null> = ref(null);
  const showRoleChangeModal = ref(false);

  // Real-time 2FA enforcement state
  const twoFactorEnforcementEvent: Ref<TwoFactorEnforcementEvent | null> = ref(null);
  
  const requires2FASetup = computed(() => {
    // Check real-time event first
    if (twoFactorEnforcementEvent.value?.enforced) {
        // Even with real-time event, check if user already has 2FA configured
        if (user.value?.has_2fa_enabled) {
            return false;
        }
        return true;
    }
    
    // Use backend-computed field which checks BOTH user-level AND role-level enforcement
    return user.value?.requires_2fa_setup ?? false;
  });

  const allowed2FAMethods = computed(() => {
    // Check real-time event first
    if (twoFactorEnforcementEvent.value?.enforced) {
        return twoFactorEnforcementEvent.value.allowed_methods;
    }
    
    // Check user object
    if (user.value?.two_factor_enforced) {
        return user.value.two_factor_allowed_methods || [];
    }
    
    return [];
  });

  // User identity hints - ONLY stores public_id (no PII)
  const userHints: Ref<UserHints> = ref({
    hasVisited: false,
    publicId: null,
    nameHint: null,
    lastLoginAt: null,
  });

  // Fetched hints from backend (not persisted)
  const fetchedHints: Ref<FetchedHints> = ref({
    maskedEmail: null,
    avatarUrl: null,
    initials: null,
  });

  // Computed
  const isAuthenticated: ComputedRef<boolean> = computed(() => !!user.value);

  const displayName: ComputedRef<string> = computed(() => {
    if (user.value?.name) {
      return user.value.name.split(' ')[0];
    }
    if (userHints.value.nameHint) return userHints.value.nameHint;
    return 'User';
  });

  const avatarUrl: ComputedRef<string | null> = computed(() => {
    return user.value?.avatar_url || null;
  });

  const initials: ComputedRef<string> = computed(() => {
    const name = user.value?.name || userHints.value.nameHint || 'U';
    return name
      .split(' ')
      .map(n => n[0])
      .join('')
      .toUpperCase()
      .slice(0, 2);
  });

  const maskedEmailHint: ComputedRef<string | null> = computed(() => fetchedHints.value.maskedEmail);
  const avatarHint: ComputedRef<string | null> = computed(() => fetchedHints.value.avatarUrl);
  const initialsHint: ComputedRef<string | null> = computed(() => fetchedHints.value.initials);

  const isPasswordSet: ComputedRef<boolean> = computed(() => user.value?.is_password_set || false);
  const passwordLastUpdatedAt: ComputedRef<string | null> = computed(() => user.value?.password_last_updated_at || null);

  const currentTeam = computed(() => {
    if (!user.value?.teams || user.value.teams.length === 0) return null;
    
    // If we have a specific team selected, try to find it
    if (currentTeamId.value) {
        const selected = user.value.teams.find(t => t.public_id === currentTeamId.value);
        if (selected) return selected;
    }
    
    // Fallback: Default to first team if no selection or invalid selection
    // Side effect: Update currentTeamId to match fallback
    if (user.value.teams.length > 0) {
        const defaultTeam = user.value.teams[0];
        // Only update if actually different to avoid side-effect loops in strict mode, 
        // though in computed it's generally safe for ref updates if careful.
        // Better pattern: Just return it here, and ensure actions set the ID.
        // For 'get', we return the first one.
        return defaultTeam;
    }
    
    return null;
  });

  // Actions
  function switchTeam(publicId: string): void {
    if (user.value?.teams?.some(t => t.public_id === publicId)) {
        currentTeamId.value = publicId;
    }
  }

  function setUserHints(hints: Partial<UserHints>): void {
    userHints.value = {
      ...userHints.value,
      ...hints,
      hasVisited: true,
    };
  }

  async function fetchUserHints(): Promise<boolean> {
    if (!userHints.value.publicId) return false;

    try {
      const response = await api.get(`/api/auth/hint/${userHints.value.publicId}`);
      fetchedHints.value = {
        maskedEmail: response.data.masked_email,
        avatarUrl: response.data.avatar_url,
        initials: response.data.initials,
      };
      // Also update nameHint if provided
      if (response.data.name) {
        userHints.value.nameHint = response.data.name;
      }
      return true;
    } catch (error: any) {
      // If user not found, clear hints
      if (error.response?.status === 404) {
        clearHints();
      }
      return false;
    }
  }

  async function login(credentials: LoginRequest): Promise<LoginResult> {
    isLoading.value = true;
    try {
      // Build full credentials with hints if needed
      const fullCredentials: LoginRequest = {
        ...credentials,
        public_id: credentials.public_id || userHints.value.publicId || undefined,
      };

      const response = await authService.login(fullCredentials);



      // Check if 2FA is required BEFORE accessing user
      if (response.requires_2fa) {
        return {
          success: false,
          requires_2fa: true,
          methods: response.methods || ['totp'],
        };
      }

      user.value = response.user;
      
      // Initialize currentTeamId if needed
      if ((!currentTeamId.value || !user.value.teams?.some(t => t.public_id === currentTeamId.value)) && (user.value.teams?.length ?? 0) > 0) {
          currentTeamId.value = user.value.teams![0].public_id;
      }

      // Sync theme from user preferences
      if (user.value?.preferences) {
          try {
              const { useThemeStore } = await import('@/stores/theme');
              const themeStore = useThemeStore();
              themeStore.syncFromUser(user.value.preferences);
          } catch (e) {
              console.warn('[Auth] Failed to sync theme preferences:', e);
          }
      }

      // Store hints for future visits (only public_id and first name)
      setUserHints({
        publicId: user.value.public_id,
        nameHint: user.value.name?.split(' ')[0],
        lastLoginAt: new Date().toISOString(),
      });

      isSessionVerified.value = true;

      return { success: true };
    } catch (error: any) {
      if (error.response?.data?.requires_challenge) {
          return {
              success: false,
              requires_challenge: true,
              error: error.response?.data?.message || 'Security check required.',
          };
      }
      
      return {
        success: false,
        error: error.message || 'Login failed. Please try again.',
      };
    } finally {
      isLoading.value = false;
    }
  }

  async function verify2FA(code: string, method: string = 'totp', _recoveryCode: string | null = null): Promise<AuthResult> {
    isLoading.value = true;
    try {
      // verify2FA now returns user data directly from backend to avoid session race condition
      const result = await authService.verify2FA(code, method);
      
      // Use the user data returned from the 2FA verification response
      if (result && typeof result === 'object' && 'user' in result) {
        user.value = (result as any).user;
      } else {
        // Fallback to fetch if user not returned (shouldn't happen with updated backend)
        await fetchUser();
      }

      console.log('[Auth] verify2FA successful', { user: user.value?.id });
      // Mark session as verified to prevent router guard from triggering fetchUser
      // This fixes the redirect loop after 2FA verification
      isSessionVerified.value = true;
      console.log('[Auth] isSessionVerified set to true');

      // Store hints after 2FA success too
      if (user.value) {
        setUserHints({
          publicId: user.value.public_id,
          nameHint: user.value.name?.split(' ')[0],
          lastLoginAt: new Date().toISOString(),
        });
      }

      return { success: true };
    } catch (error: any) {
      return {
        success: false,
        error: error.message || 'Verification failed.',
      };
    } finally {
      isLoading.value = false;
    }
  }

  async function register(data: RegisterRequest & { confirmPassword?: string }): Promise<AuthResult> {
    isLoading.value = true;
    console.log('[AuthStore] register called with data:', {
      name: data.name,
      email: data.email,
      hasPassword: !!data.password,
      hasPasswordConfirmation: !!(data.confirmPassword || data.password_confirmation),
      hasRecaptchaToken: !!data.recaptcha_token,
    });

    try {
      const payload = {
        name: data.name,
        email: data.email,
        password: data.password,
        password_confirmation: data.confirmPassword || data.password_confirmation || data.password,
        recaptcha_token: data.recaptcha_token,
      };
      console.log('[AuthStore] Calling authService.register');

      const response = await authService.register(payload);
      console.log('[AuthStore] Registration response received:', { hasUser: !!response.user });

      user.value = response.user;
      
      // Initialize currentTeamId
      if (user.value?.teams && user.value.teams.length > 0) {
          currentTeamId.value = user.value.teams[0].public_id;
      }

      // Sync theme from user preferences
      if (user.value?.preferences) {
          try {
              const { useThemeStore } = await import('@/stores/theme');
              const themeStore = useThemeStore();
              themeStore.syncFromUser(user.value.preferences);
          } catch (e) {
              console.warn('[Auth] Failed to sync theme preferences:', e);
          }
      }

      setUserHints({
        publicId: user.value.public_id,
        nameHint: user.value.name?.split(' ')[0],
        lastLoginAt: new Date().toISOString(),
      });

      console.log('[AuthStore] Registration successful');
      return { success: true };
    } catch (error: any) {
      console.error('[AuthStore] Registration error:', error);
      console.error('[AuthStore] Error details:', {
        message: error.message,
        errors: error.errors,
        response: error.response?.data,
        status: error.response?.status,
      });

      const errors = error.errors;
      let message = 'Registration failed. Please try again.';

      if (errors) {
        // Get first error message from validation errors
        const firstError = Object.values(errors)[0];
        if (Array.isArray(firstError)) {
          message = firstError[0];
        }
      } else if (error.message) {
        message = error.message;
      }

      return { success: false, error: message };
    } finally {
      isLoading.value = false;
    }
  }

  async function forgotPassword(email: string): Promise<AuthResult> {
    isLoading.value = true;
    try {
      const response = await authService.forgotPassword(email);

      return { success: true, message: response.message };
    } catch (error: any) {
      return {
        success: false,
        error: error.message || 'Failed to send reset link. Please try again.',
      };
    } finally {
      isLoading.value = false;
    }
  }

  async function logout(): Promise<void> {
    isLoading.value = true;
    try {
      // Clean up real-time listeners before logout
      try {
        const { useNotificationsStore } = await import('@/stores/notifications');
        const notificationsStore = useNotificationsStore();
        await notificationsStore.stopRealtimeListeners();
      } catch (cleanupError) {
        console.warn('[Auth] Failed to cleanup listeners:', cleanupError);
      }

      // Disconnect Echo/WebSocket
      try {
        const { stopEcho } = await import('@/echo');
        stopEcho();
      } catch (echoError) {
        console.warn('[Auth] Failed to disconnect Echo:', echoError);
      }

      // Clear Chat State (prevent data leak between sessions)
      try {
        const { useChatStore } = await import('@/stores/chat');
        const chatStore = useChatStore();
        chatStore.clearState();
      } catch (chatError) {
        console.warn('[Auth] Failed to clear chat state:', chatError);
      }

      await authService.logout();
    } catch (error) {
      console.error('Logout error:', error);
    } finally {
      user.value = null;
      currentTeamId.value = null;
      isLoading.value = false;
      isSessionVerified.value = false;
    }
  }

  async function fetchUser(): Promise<User | null> {
    try {
      console.log('[Auth] fetchUser called');
      user.value = await authService.fetchUser();
      console.log('[Auth] fetchUser success', { id: user.value?.id });
      
      // Initialize currentTeamId if needed
      if ((!currentTeamId.value || !user.value.teams?.some(t => t.public_id === currentTeamId.value)) && (user.value.teams?.length ?? 0) > 0) {
          currentTeamId.value = user.value.teams![0].public_id;
      }

      // Sync theme from user preferences
      if (user.value?.preferences) {
          try {
              const { useThemeStore } = await import('@/stores/theme');
              const themeStore = useThemeStore();
              themeStore.syncFromUser(user.value.preferences);
          } catch (e) {
              console.warn('[Auth] Failed to sync theme preferences:', e);
          }
      }

      // Update identity hints for returning user flow
      if (user.value) {
        setUserHints({
          publicId: user.value.public_id,
          nameHint: user.value.name?.split(' ')[0],
          lastLoginAt: new Date().toISOString(),
        });
        isSessionVerified.value = true;
      }

      return user.value;
    } catch (error) {
      user.value = null;
      return null;
    }
  }

  function clearHints(): void {
    userHints.value = {
      hasVisited: false,
      publicId: null,
      nameHint: null,
      lastLoginAt: null,
    };
    fetchedHints.value = {
      maskedEmail: null,
      avatarUrl: null,
      initials: null,
    };
  }

  function updatePresence(status: 'online' | 'offline' | 'away' | 'busy'): void {
    if (user.value) {
      user.value.presence = status;
    }
  }

  // Permission change listener
  let permissionChannel: any = null;

  async function startPermissionListener(): Promise<void> {
    if (!user.value?.public_id) return;

    try {
      const { default: echo, isEchoAvailable } = await import('@/echo');
      if (!isEchoAvailable()) {
        console.debug('[Auth] Echo not available, skipping permission listener');
        return;
      }

      // Subscribe to user's private channel for permission updates
      permissionChannel = echo.private(`App.Models.User.${user.value.public_id}`);

      permissionChannel.listen('.permissions.updated', async (event: any) => {
        console.log('[Auth] Permissions updated:', event);

        // Refetch user data to get updated roles/permissions
        await fetchUser();

        // Also refetch navigation since it may have changed
        try {
          const { useNavigationStore } = await import('@/stores/navigation');
          const navStore = useNavigationStore();
          await navStore.fetchNavigation();
        } catch (e) {
          console.warn('[Auth] Failed to refetch navigation:', e);
        }

        // Dispatch event for any other components that need to react
        window.dispatchEvent(new CustomEvent('permissions-updated', { detail: event }));
      });

      console.log('[Auth] Permission listener started');
    } catch (error) {
      console.warn('[Auth] Failed to start permission listener:', error);
    }
  }

  function stopPermissionListener(): void {
    if (permissionChannel) {
      permissionChannel.stopListening('.permissions.updated');
      permissionChannel = null;
      console.log('[Auth] Permission listener stopped');
    }
  }

  // Account status change listener
  let statusChannel: any = null;

  async function startStatusListener(): Promise<void> {
    if (!user.value?.public_id) return;

    try {
      const { default: echo, isEchoAvailable } = await import('@/echo');
      if (!isEchoAvailable()) {
        console.debug('[Auth] Echo not available, skipping status listener');
        return;
      }

      // Subscribe to user's private channel for status updates
      statusChannel = echo.private(`App.Models.User.${user.value.public_id}`);

      // Listen for status changes (blocked, suspended, etc.)
      statusChannel.listen('.status.changed', (event: StatusChangeEvent) => {
        console.log('[Auth] Status changed:', event);

        statusChangeEvent.value = event;

        // If blocked or suspended, show the modal
        if (['blocked', 'suspended'].includes(event.status)) {
          showBlockedModal.value = true;
        }

        // Dispatch global event
        window.dispatchEvent(new CustomEvent('user-status-changed', { detail: event }));
      });

      // Listen for role changes
      statusChannel.listen('.role.changed', async (event: RoleChangeEvent) => {
        console.log('[Auth] Role changed:', event);

        roleChangeEvent.value = event;
        showRoleChangeModal.value = true;

        // Refetch user data to get updated role
        await fetchUser();

        // Refetch navigation since permissions may have changed
        try {
          const { useNavigationStore } = await import('@/stores/navigation');
          const navStore = useNavigationStore();
          await navStore.fetchNavigation();
        } catch (e) {
          console.warn('[Auth] Failed to refetch navigation:', e);
        }

        // Dispatch global event
        window.dispatchEvent(new CustomEvent('user-role-changed', { detail: event }));
      });

      // Listen for 2FA enforcement changes
      statusChannel.listen('.2fa.enforcement.changed', (event: TwoFactorEnforcementEvent) => {
        console.log('[Auth] 2FA enforcement changed:', event);

        twoFactorEnforcementEvent.value = event;

        // Dispatch global event
        window.dispatchEvent(new CustomEvent('2fa-enforcement-changed', { detail: event }));
      });

      console.log('[Auth] Status listener started');
    } catch (error) {
      console.warn('[Auth] Failed to start status listener:', error);
    }
  }

  function stopStatusListener(): void {
    if (statusChannel) {
      statusChannel.stopListening('.status.changed');
      statusChannel.stopListening('.role.changed');
      statusChannel.stopListening('.2fa.enforcement.changed');
      statusChannel = null;
      console.log('[Auth] Status listener stopped');
    }
  }

  // Combined listener management
  async function startAllListeners(): Promise<void> {
    // Only connect if fully authenticated and verified
    if (!user.value) return;
    
    // Check Email Verification (if required)
    if (!user.value.email_verified_at) {
        console.debug('[Auth] Echo connection deferred: Email verification required');
        return;
    }
    
    // Check 2FA (using the computed property logic but accessible here)
    if (requires2FASetup.value) {
        console.debug('[Auth] Echo connection deferred: 2FA setup required');
        return;
    }

    // Start Echo
    try {
        const { startEcho } = await import('@/echo');
        startEcho();
    } catch (e) {
        console.warn('[Auth] Failed to start Echo:', e);
    }

    await Promise.all([
      startPermissionListener(),
      startStatusListener(),
    ]);
  }

  function stopAllListeners(): void {
    stopPermissionListener();
    stopStatusListener();
  }

  // Modal control functions
  function dismissRoleChangeModal(): void {
    showRoleChangeModal.value = false;
    roleChangeEvent.value = null;
  }

  function handleBlockedLogout(): void {
    showBlockedModal.value = false;
    statusChangeEvent.value = null;
    // Force logout
    logout();
  }

  // Clear 2FA enforcement state
  function clear2FAEnforcementState(): void {
    twoFactorEnforcementEvent.value = null;
  }

  return {
    // State
    user,
    currentTeamId,
    isLoading,
    userHints,
    fetchedHints,
    // Real-time state
    statusChangeEvent,
    showBlockedModal,
    roleChangeEvent,
    showRoleChangeModal,
    twoFactorEnforcementEvent,
    requires2FASetup,
    allowed2FAMethods,
    // Computed
    isAuthenticated,
    displayName,
    avatarUrl,
    initials,
    maskedEmailHint,
    avatarHint,
    initialsHint,
    isPasswordSet,
    passwordLastUpdatedAt,
    currentTeam,
    // Actions
    login,
    verify2FA,
    register,
    forgotPassword,
    logout,
    fetchUser,
    fetchUserHints,
    setUserHints,
    clearHints,
    updatePresence,
    switchTeam,
    // Listener management
    startPermissionListener,
    stopPermissionListener,
    startStatusListener,
    stopStatusListener,
    startAllListeners,
    stopAllListeners,
    // Modal control
    dismissRoleChangeModal,
    handleBlockedLogout,
    clear2FAEnforcementState,
    isSessionVerified,
  };
}, {
  persist: {
    key: 'coresync-auth',
    paths: ['user', 'userHints', 'currentTeamId'], // Only userHints persisted, NOT fetchedHints (loaded on demand)
  },
});
