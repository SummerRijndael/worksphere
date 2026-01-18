import { ref, computed, onMounted, onUnmounted, watch, triggerRef } from 'vue';
import type { Ref, ComputedRef } from 'vue';
import { useAuthStore } from '@/stores/auth';
import echo, { isConnected, reconnect, disconnect, isEchoAvailable } from '@/echo';
import api from '@/lib/api';

type PresenceStatus = 'online' | 'away' | 'busy' | 'offline' | 'invisible';
type ConnectionHealth = 'healthy' | 'degraded' | 'disconnected' | 'unknown';

interface PresenceUser {
    id?: number;
    public_id: string;
    name?: string;
    avatar?: string;
    status: PresenceStatus;
    lastSeen: number;
}

interface HeartbeatResult {
    success: boolean;
    throttled?: boolean;
    presence?: PresenceStatus;
    reason?: string;
}

interface StatusUpdateResult {
    success: boolean;
    error?: string;
}

interface UsePresenceOptions {
    manageLifecycle?: boolean;
}

// Global presence state (intentionally shared across components for consistency)
const currentStatus: Ref<PresenceStatus> = ref('online');
const preferredStatus: Ref<PresenceStatus> = ref('online');
const isOnline = ref(true);
const connectionHealth: Ref<ConnectionHealth> = ref('unknown');
const lastHeartbeat: Ref<number | null> = ref(null);
const presenceUsers: Ref<Map<string, PresenceUser>> = ref(new Map());
const isSyncing = ref(false);

// Heartbeat configuration
// Instance tracking for cleanup
interface PresenceInstance {
    abortController: AbortController;
    heartbeatTimer: number | null;
    gcTimer: number | null;
    visibilityHandler: (() => void) | null;
    onlineHandler: (() => void) | null;
    offlineHandler: (() => void) | null;
    unloadHandler: (() => void) | null;
    echoConnectedHandler: (() => void) | null;
    echoDisconnectedHandler: (() => void) | null;
    heartbeatRetries: number;
    isHeartbeatPaused: boolean;
}

// Active instances map
const activeInstances = new Map<symbol, PresenceInstance>();

/**
 * Composable for managing user presence with proper cleanup and AbortController support.
 */
export function usePresence(options: UsePresenceOptions = {}) {
    const { manageLifecycle = true } = options;
    const authStore = useAuthStore();

    // Create unique instance ID
    const instanceId = Symbol('presence');

    // Create instance-specific state
    const instance: PresenceInstance = {
        abortController: new AbortController(),
        heartbeatTimer: null,
        gcTimer: null,
        visibilityHandler: null,
        onlineHandler: null,
        offlineHandler: null,
        unloadHandler: null,
        echoConnectedHandler: null,
        echoDisconnectedHandler: null,
        heartbeatRetries: 0,
        isHeartbeatPaused: false,
    };

    activeInstances.set(instanceId, instance);

    // Computed values
    const isAuthenticated: ComputedRef<boolean> = computed(() => !!authStore.user);
    const userPublicId: ComputedRef<string | undefined> = computed(() => authStore.user?.public_id);

    // -------------------------------------------------------------------------
    // WHISPER & ACTIVITY LOGIC
    // -------------------------------------------------------------------------
    const WHISPER_THROTTLE_MS = 30000; // Only whisper once every 30s
    const AWAY_TIMEOUT_MS = 300000;    // Mark away after 5 minutes locally
    const SLOW_SYNC_MS = 540000;       // Send HTTP heartbeat every 9 minutes (Active persistence)

    let lastActivitySent = 0;

    /**
     * Send "I am active" whisper to other clients.
     * Throttled to avoid flooding the socket.
     */
    function broadcastActivity() {
        if (!isAuthenticated.value || !userPublicId.value || !isEchoAvailable()) return;

        const now = Date.now();
        if (now - lastActivitySent < WHISPER_THROTTLE_MS) {
            return;
        }

        const channelName = 'online-users';
        try {
            echo.join(channelName)
                .whisper('activity', {
                    public_id: userPublicId.value,
                    name: authStore.user?.name,
                    avatar: authStore.user?.avatar_url,
                    status: preferredStatus.value // Share explicit status (e.g. 'busy')
                });

            lastActivitySent = now;
            
            // Optimistically update own last seen
            updateUserPresence({ 
                public_id: userPublicId.value,
                status: preferredStatus.value === 'invisible' ? 'offline' : preferredStatus.value 
            });
        } catch (e) {
            console.warn('[Presence] Failed to whisper activity:', e);
        }
    }

    /**
     * Garbage Collector: Scan users and mark them "Away" if we haven't heard from them.
     * Runs locally every 10 seconds.
     */
    function runGarbageCollector() {
        const now = Date.now();
        console.log('[Presence GC] Running check at', new Date().toLocaleTimeString());
        
        presenceUsers.value.forEach((user, publicId) => {
            // Skip self
            if (publicId === userPublicId.value) return;
            
            // Skip if already offline or invisible
            if (user.status === 'offline' || user.status === 'invisible') return;

            // Assuming user.lastSeen is updated by Whispers
            const timeSince = now - (user.lastSeen || 0);
            console.log(`[Presence GC] Checking user ${user.name || publicId}: ${timeSince}ms idle`);

            // If silent for > 1 min -> Mark Away
            if (timeSince > AWAY_TIMEOUT_MS && user.status !== 'away' && user.status !== 'busy') {
                console.log(`[Presence GC] Marking user ${user.name || publicId} as AWAY`);
                updateUserPresence(user, 'away');
            }
        });
    }

    // -------------------------------------------------------------------------
    // HTTP FALLBACK (SLOW SYNC)
    // -------------------------------------------------------------------------

    /**
     * Send heartbeat to server to keep session alive.
     * Now only runs every 9 minutes (SLOW_SYNC_MS) instead of 30s.
     */
    async function sendHeartbeat(_reason: string = 'interval'): Promise<HeartbeatResult> {
        if (!isAuthenticated.value || !isOnline.value || instance.isHeartbeatPaused) {
            return { success: false, reason: 'not authenticated, offline, or paused' };
        }

        try {
            const response = await api.post('/api/presence/heartbeat', {}, {
                signal: instance.abortController.signal
            });

            if (response.data.status === 'ok' || response.data.throttled) {
                lastHeartbeat.value = Date.now();
                connectionHealth.value = 'healthy';
                // Server might return updated explicit status
                if (response.data.presence) {
                    currentStatus.value = response.data.presence;
                }
                return { success: true };
            }
            return { success: false, reason: 'unexpected response' };
        } catch (error: any) {
             if (error.name === 'AbortError' || error.name === 'CanceledError') return { success: false };
             // Silent fail on slow sync is fine
             return { success: false, reason: error.message };
        }
    }

    /**
     * Send offline signal on page unload.
     */
    function sendOfflineSignal(): void {
        if (!isAuthenticated.value) return;

        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!token) return;

        try {
            fetch('/api/presence/offline', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token
                },
                body: JSON.stringify({ reason: 'unload' }),
                keepalive: true
            });
        } catch (e) {
            // Ignore errors on unload
        }
    }


    function startHeartbeat(): void {
        if (instance.heartbeatTimer) clearInterval(instance.heartbeatTimer);
        if (instance.gcTimer) clearInterval(instance.gcTimer);
        
        instance.isHeartbeatPaused = false;
        if (!isAuthenticated.value) return;

        // 1. Initial Sync
        sendHeartbeat('start');

        // 2. Slow Sync Loop (Session Keep-Alive)
        instance.heartbeatTimer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                sendHeartbeat('interval');
            }
        }, SLOW_SYNC_MS);

        // 3. Garbage Collector Loop (Local Inference)
        instance.gcTimer = window.setInterval(() => {
             runGarbageCollector();
        }, 10000); // Check every 10s

        // 4. Attach Activity Listeners (Mouse/Keyboard)
        window.addEventListener('mousemove', broadcastActivity);
        window.addEventListener('keydown', broadcastActivity);
        window.addEventListener('click', broadcastActivity);
        window.addEventListener('scroll', broadcastActivity);
    }

    function stopHeartbeat(): void {
        if (instance.heartbeatTimer) {
            clearInterval(instance.heartbeatTimer);
            instance.heartbeatTimer = null;
        }
        if (instance.gcTimer) {
            clearInterval(instance.gcTimer);
            instance.gcTimer = null;
        }
        
        // Remove Activity Listeners
        window.removeEventListener('mousemove', broadcastActivity);
        window.removeEventListener('keydown', broadcastActivity);
        window.removeEventListener('click', broadcastActivity);
        window.removeEventListener('scroll', broadcastActivity);
    }

    /**
     * Updates a user's presence and timestamp.
     */
    function updateUserPresence(user: any, status: PresenceStatus | null = null): void {
        if (!user || !user.public_id) return;

        const existing = presenceUsers.value.get(user.public_id) || {} as PresenceUser;
        presenceUsers.value.set(user.public_id, {
            ...existing,
            ...user,
            status: status || user.status || existing.status || 'offline',
            lastSeen: Date.now() // Reset timer on any update/whisper
        });
        triggerRef(presenceUsers);
    }

    /**
     * Subscribe to presence channels.
     */
    function subscribeToPresence(): void {
        if (!isAuthenticated.value || !userPublicId.value || !isEchoAvailable()) return;

        echo.private(`presence.${userPublicId.value}`)
            .listen('.presence.changed', (data: any) => {
                currentStatus.value = data.status;
            });

        echo.join('online-users')
            .here((users: any[]) => {
                users.forEach(user => updateUserPresence(user, user.status || 'online'));
            })
            .joining((user: any) => {
                updateUserPresence(user, user.status || 'online');
            })
            .leaving((user: any) => {
                const existing = presenceUsers.value.get(user.public_id);
                if (existing) {
                    presenceUsers.value.set(user.public_id, { ...existing, status: 'offline' });
                }
            })
            // Listen for Client Whispers (The new "Professional" way)
            .listenForWhisper('activity', (e: any) => {
                 if (e.public_id) {
                     // Respect the status sent by the client (online, busy, away, etc.)
                     // If status is missing, assume 'online' (active)
                     const newStatus = e.status || 'online';
                     updateUserPresence({ 
                        public_id: e.public_id,
                        name: e.name,
                        avatar: e.avatar,
                     }, newStatus);
                 }
            })
            .listen('.presence.changed', (data: any) => {
                updateUserPresence(data, data.status);
            });
    }

    /**
     * Unsubscribe from presence channels.
     */
    function unsubscribeFromPresence(): void {
        if (!isEchoAvailable()) return;
        if (userPublicId.value) echo.leave(`presence.${userPublicId.value}`);
        echo.leave('online-users');
    }

    /**
     * Initialize presence system.
     */
    function initialize(): void {
        // 1. Visibility Handler
        instance.visibilityHandler = () => {
            if (document.visibilityState === 'visible') {
                // Just broadcast activity, don't spam heartbeat
                broadcastActivity();
            }
        };
        document.addEventListener('visibilitychange', instance.visibilityHandler);

        // 2. Connection Handlers
        instance.onlineHandler = () => {
             isOnline.value = true;
             connectionHealth.value = 'healthy';
             instance.isHeartbeatPaused = false;
             startHeartbeat();
             reconnect();
        };
        instance.offlineHandler = () => {
            isOnline.value = false;
            connectionHealth.value = 'disconnected';
            stopHeartbeat();
        };
        window.addEventListener('online', instance.onlineHandler);
        window.addEventListener('offline', instance.offlineHandler);

        // 3. Unload Handler
        instance.unloadHandler = () => sendOfflineSignal();
        window.addEventListener('beforeunload', instance.unloadHandler);
        window.addEventListener('pagehide', instance.unloadHandler);

        // 4. Global Echo Events
        instance.echoConnectedHandler = () => {
            instance.heartbeatRetries = 0;
            instance.isHeartbeatPaused = false;
            startHeartbeat();
            subscribeToPresence();
            refreshData();
        };
        instance.echoDisconnectedHandler = () => {
            console.debug('[Presence] WebSocket Disconnected - Pausing Heartbeat');
            stopHeartbeat();
        };
        window.addEventListener('echo:connected', instance.echoConnectedHandler);
        window.addEventListener('echo:disconnected', instance.echoDisconnectedHandler);

        // 5. Initial State
        isOnline.value = navigator.onLine;
        if (isAuthenticated.value) {
            fetchMyPresence();
            startHeartbeat();
            subscribeToPresence();
        }
    }

    /**
     * Cleanup listeners and timers.
     */
    function cleanup(): void {
        instance.abortController.abort();
        stopHeartbeat();
        unsubscribeFromPresence();

        if (instance.visibilityHandler) document.removeEventListener('visibilitychange', instance.visibilityHandler);
        if (instance.onlineHandler) window.removeEventListener('online', instance.onlineHandler);
        if (instance.offlineHandler) window.removeEventListener('offline', instance.offlineHandler);
        if (instance.unloadHandler) {
            window.removeEventListener('beforeunload', instance.unloadHandler);
            window.removeEventListener('pagehide', instance.unloadHandler);
        }
        if (instance.echoConnectedHandler) window.removeEventListener('echo:connected', instance.echoConnectedHandler);
        if (instance.echoDisconnectedHandler) window.removeEventListener('echo:disconnected', instance.echoDisconnectedHandler);

        activeInstances.delete(instanceId);
    }

    /**
     * Update user status.
     */
    async function setStatus(status: PresenceStatus): Promise<StatusUpdateResult> {
        if (!isAuthenticated.value) return { success: false, error: 'Not authenticated' };
        try {
            const response = await api.put('/api/presence/status', { status }, {
                signal: instance.abortController.signal
            });
            if (response.data.status === 'ok') {
                preferredStatus.value = response.data.preference;
                currentStatus.value = response.data.presence;
                // Force broadcast activity with new status immediately
                lastActivitySent = 0; 
                broadcastActivity();
                return { success: true };
            }
            return { success: false, error: 'Unexpected response' };
        } catch (error: any) {
            if (error.name === 'AbortError' || error.name === 'CanceledError') {
                return { success: false, error: 'Aborted' };
            }
            console.error('[Presence] Failed to update status:', error);
            return { success: false, error: error.message };
        }
    }

    /**
     * Fetch current user's presence.
     */
    async function fetchMyPresence(): Promise<void> {
        if (!isAuthenticated.value) return;
        try {
            const response = await api.get('/api/presence/me', {
                signal: instance.abortController.signal
            });
            currentStatus.value = response.data.status;
            preferredStatus.value = response.data.preference;
        } catch (error: any) {
             // Silent fail
        }
    }

    /**
     * Fetch multiple users' presence.
     */
    async function fetchUsersPresence(publicIds: string[]): Promise<PresenceUser[]> {
        if (!publicIds?.length) return [];
        try {
            const response = await api.get('/api/presence/users', {
                params: { public_ids: publicIds },
                signal: instance.abortController.signal
            });
            response.data.users?.forEach((user: any) => updateUserPresence(user));
            return response.data.users || [];
        } catch (error: any) {
            return [];
        }
    }

    /**
     * Refresh presence data after reconnection.
     */
    async function refreshData(): Promise<void> {
        isSyncing.value = true;
        try {
            await api.get('/api/presence/me').then(response => {
                 currentStatus.value = response.data.status;
                 preferredStatus.value = response.data.preference;
            }).catch(() => {});

            window.dispatchEvent(new CustomEvent('app:refresh', {
                detail: { source: 'presence-reconnect' }
            }));
        } finally {
            setTimeout(() => { isSyncing.value = false; }, 500);
        }
    }

    // Watch for auth changes
    watch(isAuthenticated, (authenticated) => {
        if (authenticated) {
            initialize();
        } else {
            cleanup();
            currentStatus.value = 'offline';
        }
    });

    if (manageLifecycle) {
        onMounted(() => {
            initialize();
        });

        onUnmounted(() => {
            cleanup();
        });
    }

    return {
        currentStatus,
        preferredStatus,
        isOnline,
        connectionHealth,
        lastHeartbeat,
        presenceUsers,
        isConnected: computed(() => isConnected()),
        setStatus,
        sendHeartbeat,
        fetchMyPresence,
        fetchUsersPresence,
        initialize,
        cleanup,
        reconnect,
        disconnect,
        refreshData,
        isSyncing: computed(() => isSyncing.value),
        updateUserPresence, // Export for debug tool
    };
}

/**
 * Get user presence status from cache.
 */
export function getUserPresence(publicId: string): PresenceUser | undefined {
    return presenceUsers.value.get(publicId);
}

/**
 * Get status color class.
 */
export function getStatusColor(status: PresenceStatus): string {
    const colors: Record<PresenceStatus, string> = {
        online: 'bg-green-500',
        away: 'bg-yellow-500',
        busy: 'bg-red-500',
        offline: 'bg-gray-400',
        invisible: 'bg-gray-400',
    };
    return colors[status] || colors.offline;
}

/**
 * Get status label.
 */
export function getStatusLabel(status: PresenceStatus): string {
    const labels: Record<PresenceStatus, string> = {
        online: 'Online',
        away: 'Away',
        busy: 'Busy',
        offline: 'Offline',
        invisible: 'Invisible',
    };
    return labels[status] || 'Unknown';
}
