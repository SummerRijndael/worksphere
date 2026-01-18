import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ref, readonly, type Ref, type DeepReadonly } from 'vue';
import api from '@/lib/api';

// Type declarations
type ConnectionState = 'disconnected' | 'connecting' | 'connected' | 'reconnecting' | 'unavailable' | 'failed' | 'error';
type ConnectionListener = (state: ConnectionState, error?: Error | null) => void;

// PusherConnection interface removed as it's no longer used explicitly

interface EchoInstance extends Echo<'reverb'> {
    connector: any; // Relaxed type to avoid strict interface mismatch with laravel-echo
}

// Extend Window interface
declare global {
    interface Window {
        Pusher?: typeof Pusher;
        Echo?: EchoInstance;
    }
}

// Make Pusher available globally for Laravel Echo
window.Pusher = Pusher;

// Reactive connection state tracking
const connectionState: Ref<ConnectionState> = ref('disconnected');
const connectionError: Ref<Error | null> = ref(null);
let reconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 10;
let reconnectTimer: ReturnType<typeof setTimeout> | null = null;

// Event listeners for external components
const connectionListeners = new Set<ConnectionListener>();

// Global window events map
const GLOBAL_EVENTS = {
    CONNECTED: 'echo:connected',
    DISCONNECTED: 'echo:disconnected',
    ERROR: 'echo:error',
    RECONNECTING: 'echo:reconnecting'
} as const;

// Echo instance (may be null if configuration is missing)
let echo: EchoInstance | null = null;

/**
 * Check if Reverb configuration is available.
 */
function isReverbConfigured(): boolean {
    const key = import.meta.env.VITE_REVERB_APP_KEY;
    return Boolean(key && key !== 'undefined' && key !== '');
}

/**
 * Notify all listeners of connection state change and dispatch global event.
 */
function notifyListeners(state: ConnectionState, error: Error | null = null): void {
    // 1. Notify internal listeners
    connectionListeners.forEach(listener => {
        try {
            listener(state, error);
        } catch (e) {
            console.warn('[Echo] Listener error:', e);
        }
    });

    // 2. Dispatch global window events
    let eventName: string | null = null;
    const detail: { state: ConnectionState; timestamp: number; error?: Error | null } = { 
        state, 
        timestamp: Date.now() 
    };

    switch (state) {
        case 'connected':
            eventName = GLOBAL_EVENTS.CONNECTED;
            break;
        case 'disconnected':
        case 'unavailable':
        case 'failed':
            eventName = GLOBAL_EVENTS.DISCONNECTED;
            detail.error = error;
            break;
        case 'connecting':
        case 'reconnecting':
             // Optional: dispatch connecting event if needed
            break;
        case 'error':
            eventName = GLOBAL_EVENTS.ERROR;
            detail.error = error;
            break;
    }

    if (eventName) {
        window.dispatchEvent(new CustomEvent(eventName, { detail }));
    }
}

/**
 * Initialize Laravel Echo with Reverb.
 * Returns null if configuration is missing.
 */
function initializeEcho(): EchoInstance | null {
    if (!isReverbConfigured()) {
        console.debug('[Echo] Reverb not configured, WebSocket features disabled');
        return null;
    }

    try {
        const echoInstance = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: (import.meta.env.VITE_REVERB_HOST === '0.0.0.0' || import.meta.env.VITE_REVERB_HOST === '127.0.0.1') 
                ? window.location.hostname 
                : (import.meta.env.VITE_REVERB_HOST || 'localhost'),
            wsPort: Number(import.meta.env.VITE_REVERB_PORT) || 9000,
            wssPort: Number(import.meta.env.VITE_REVERB_PORT) || 9000,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            // Custom authorizer to use our API instance (Axios)
            // This ensures we use the correct XSRF-TOKEN from the cookie and handle 419s via interceptors
            authorizer: (channel: any, _options: any) => {
                return {
                    authorize: (socketId: string, callback: (error: boolean, data?: any) => void) => {
                        api.post('/api/broadcasting/auth', {
                            socket_id: socketId,
                            channel_name: channel.name
                        })
                        .then(response => {
                            callback(false, response.data);
                        })
                        .catch(error => {
                            callback(true, error);
                        });
                    }
                };
            },
        }) as EchoInstance;

        // Listen for connection events
        if (echoInstance.connector?.pusher) {
            const pusher = echoInstance.connector.pusher;

            pusher.connection.bind('connected', () => {
                connectionState.value = 'connected';
                connectionError.value = null;
                reconnectAttempts = 0;
                if (reconnectTimer) {
                    clearTimeout(reconnectTimer);
                    reconnectTimer = null;
                }
                console.debug('[Echo] Connected to WebSocket server');
                notifyListeners('connected');
            });

            pusher.connection.bind('disconnected', () => {
                connectionState.value = 'disconnected';
                console.debug('[Echo] Disconnected from WebSocket server');
                notifyListeners('disconnected');
                // Auto-reconnect managed by Pusher client, but we track state
            });

            pusher.connection.bind('connecting', () => {
                connectionState.value = 'connecting';
                console.debug('[Echo] Connecting to WebSocket server...');
                notifyListeners('connecting');
            });

            pusher.connection.bind('unavailable', () => {
                connectionState.value = 'unavailable';
                console.debug('[Echo] WebSocket server unavailable (waiting for recovery...)');
                notifyListeners('unavailable');
                // Pusher handles backoff internally for 'unavailable' state.
                // We do NOT manual reconnect here to avoid fighting the library.
            });

            pusher.connection.bind('failed', () => {
                connectionState.value = 'failed';
                console.debug('[Echo] WebSocket connection failed');
                notifyListeners('failed');
                attemptReconnect();
            });

            pusher.connection.bind('error', (error: any) => {
                connectionState.value = 'error';
                connectionError.value = error as Error;
                console.warn('[Echo] Connection error:', error);
                notifyListeners('error', error as Error);
            });

            pusher.connection.bind('state_change', (states: any) => {
                const current = states?.current as ConnectionState;
                if (current) {
                    connectionState.value = current;
                    notifyListeners(current);
                }
            });
        }

        return echoInstance;
    } catch (error) {
        console.warn('[Echo] Failed to initialize:', (error as Error).message);
        connectionError.value = error as Error;
        return null;
    }
}

/**
 * Attempt to reconnect with exponential backoff.
 * 1s -> 2s -> 4s -> 8s ... max 30s
 */
function attemptReconnect(): void {
    if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
        console.warn('[Echo] Max reconnect attempts reached, giving up.');
        return;
    }

    // Clear existing timer if any
    if (reconnectTimer) clearTimeout(reconnectTimer);

    reconnectAttempts++;
    // Exponential backoff: 1s, 2s, 4s, 8s, 16s, 30s(cap)
    const delay = Math.min(1000 * Math.pow(2, reconnectAttempts - 1), 30000);

    console.debug(`[Echo] Reconnecting in ${delay}ms (Attempt ${reconnectAttempts}/${MAX_RECONNECT_ATTEMPTS})`);
    
    notifyListeners('reconnecting');
    window.dispatchEvent(new CustomEvent(GLOBAL_EVENTS.RECONNECTING, { 
        detail: { attempt: reconnectAttempts, delay } 
    }));

    reconnectTimer = setTimeout(() => {
        if (echo?.connector?.pusher) {
            echo.connector.pusher.connect();
        }
    }, delay);
}

// Initialize Echo (wrapped in function, not auto-invoked)
export function startEcho(): EchoInstance | null {
    if (echo) {
        console.debug('[Echo] Already initialized');
        return echo;
    }

    echo = initializeEcho();

    // Expose globally for debugging and legacy support
    if (echo) {
        window.Echo = echo;
    }
    
    return echo;
}


/**
 * Stop Echo connection and cleanup.
 */
export function stopEcho(): void {
    if (echo) {
        console.debug('[Echo] Stopping Echo...');
        disconnect(); // Uses internal disconnect helper
        echo = null;
        window.Echo = undefined;
        connectionState.value = 'disconnected';
    }
}


/**
 * Get current connection state (reactive).
 */
export function getConnectionState(): DeepReadonly<Ref<ConnectionState>> {
    return readonly(connectionState);
}

/**
 * Get current connection error (reactive).
 */
export function getConnectionError(): DeepReadonly<Ref<Error | null>> {
    return readonly(connectionError);
}

/**
 * Check if connected.
 */
export function isConnected(): boolean {
    return connectionState.value === 'connected';
}

/**
 * Check if Echo is available.
 */
export function isEchoAvailable(): boolean {
    return echo !== null;
}

/**
 * Add a connection state listener.
 */
export function onConnectionChange(callback: ConnectionListener): () => void {
    connectionListeners.add(callback);
    // Immediately call with current state
    callback(connectionState.value, connectionError.value);
    // Return unsubscribe function
    return () => connectionListeners.delete(callback);
}

/**
 * Force reconnect immediately (resets attempts).
 */
export function reconnect(): void {
    if (reconnectTimer) clearTimeout(reconnectTimer);
    reconnectAttempts = 0;
    
    if (echo?.connector?.pusher) {
        echo.connector.pusher.disconnect();
        setTimeout(() => {
            echo?.connector?.pusher?.connect();
        }, 100);
    }
}

/**
 * Disconnect from server.
 */
export function disconnect(): void {
    if (reconnectTimer) clearTimeout(reconnectTimer);
    
    if (echo?.connector?.pusher) {
        echo.connector.pusher.disconnect();
    }
}

// Export a Proxy that delegates to the lazy-loaded instance
const echoProxy = new Proxy({} as EchoInstance, {
    get(target, prop) {
        if (!echo) return undefined;
        const value = Reflect.get(echo, prop);
        // Bind methods to the original instance to preserve 'this' context
        return typeof value === 'function' ? value.bind(echo) : value;
    }
});

export default echoProxy;
