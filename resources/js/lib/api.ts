import axios, { AxiosInstance, InternalAxiosRequestConfig, AxiosError } from 'axios';

// Extend InternalAxiosRequestConfig for retry mechanism
interface ExtendedAxiosRequestConfig extends InternalAxiosRequestConfig {
    _retry?: boolean;
}

const api: AxiosInstance = axios.create({
    baseURL: '/',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    withCredentials: true,
    withXSRFToken: true,
});

// Request interceptor
api.interceptors.request.use(
    (config: InternalAxiosRequestConfig) => {
        // Get CSRF token from meta tag
        // REMOVED: Rely on Axios withXSRFToken: true to specific X-XSRF-TOKEN header from cookie.
        // This prevents stale meta tags from overriding fresh cookies.
        /*
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (token) {
            config.headers['X-CSRF-TOKEN'] = token;
        }
        */
        
        // Get socket ID from Echo for Laravel's toOthers() to work
        // This allows the backend to exclude the sender from broadcasts
        const socketId = (window as Window & { Echo?: { socketId?: () => string } }).Echo?.socketId?.();
        if (socketId) {
            config.headers['X-Socket-ID'] = socketId;
        }
        
        return config;
    },
    (error: AxiosError) => {
        return Promise.reject(error);
    }
);

// Response interceptor
// Queue for pending requests during CSRF refresh
let isRefreshing = false;
let failedQueue: Array<{
    resolve: (value?: unknown) => void;
    reject: (reason?: any) => void;
}> = [];

const processQueue = (error: any = null) => {
    failedQueue.forEach((prom) => {
        if (error) {
            prom.reject(error);
        } else {
            prom.resolve();
        }
    });
    failedQueue = [];
};

api.interceptors.response.use(
    (response) => response,
    async (error: AxiosError) => {
        const originalRequest = error.config as ExtendedAxiosRequestConfig;

        // Handle 401 Unauthorized
        if (error.response?.status === 401) {
            console.warn('[API] 401 Unauthorized - clearing auth and redirecting');
            
            // Don't clear auth or redirect if we're on auth pages (login, 2FA challenge, etc.)
            // This prevents race conditions during 2FA verification flow
            if (window.location.pathname.startsWith("/auth")) {
                console.debug('[API] Skipping 401 redirect - on auth page');
                return Promise.reject(error);
            }
            
            // Give a small grace period for any "user blocked/suspended" events to arrive via Echo
            await new Promise((resolve) => setTimeout(resolve, 1000));

            try {
                const { useAuthStore } = await import("@/stores/auth");
                const authStore = useAuthStore();
                if (authStore.showBlockedModal) {
                    return Promise.reject(error);
                }
            } catch (e) {
                console.error("Failed to check auth store during 401 handling:", e);
            }

            localStorage.removeItem("coresync-auth");

            if (!window.location.pathname.startsWith("/auth")) {
                window.location.href = "/auth/login";
            }
            return Promise.reject(error);
        }

        // Handle 403 Forbidden - Enforce 2FA
        if (
            error.response?.status === 403 &&
            error.response?.data?.action === "setup_2fa"
        ) {
            if (window.location.pathname !== "/auth/setup-2fa") {
                window.location.href = "/auth/setup-2fa";
            }
            return Promise.reject(error);
        }

        // Handle 419 CSRF token mismatch
        if (error.response?.status === 419 && originalRequest) {
            if (originalRequest._retry) {
                // If it fails again after retry, reject it
                return Promise.reject(error);
            }

            if (isRefreshing) {
                // If refresh is already happening, queue this request
                return new Promise((resolve, reject) => {
                    failedQueue.push({ resolve, reject });
                })
                    .then(() => {
                        return api(originalRequest);
                    })
                    .catch((err) => {
                        return Promise.reject(err);
                    });
            }

            originalRequest._retry = true;
            isRefreshing = true;

            return new Promise((resolve, reject) => {
                axios
                    .get("/sanctum/csrf-cookie", { withCredentials: true })
                    .then(() => {
                        processQueue(null); // Resolve all queued requests
                        resolve(api(originalRequest)); // Retry current request
                    })
                    .catch((err) => {
                        processQueue(err); // Reject all queued requests
                        reject(err);
                    })
                    .finally(() => {
                        isRefreshing = false;
                    });
            });
        }

        return Promise.reject(error);
    }
);

export default api;
