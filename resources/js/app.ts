import '../css/app.css';
// import './echo'; // Initialize WebSocket connection - MOVED to auth store on-demand
import { createApp } from 'vue';
import type { App as VueApp } from 'vue';
import { createPinia } from 'pinia';
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate';
import App from './App.vue';
import router from './router';

// Create Vue app
const app: VueApp = createApp(App);

// Create Pinia with persistence plugin
const pinia = createPinia();
pinia.use(piniaPluginPersistedstate);

// Use plugins
app.use(pinia);
app.use(router);

import ErrorBoundary from './components/common/ErrorBoundary.vue';
app.component('ErrorBoundary', ErrorBoundary);

// Global error handler to prevent SPA crashes
app.config.errorHandler = (err: unknown, instance, info: string) => {
    console.error('[Vue Error]', err);
    console.error('[Component]', instance?.$options?.name || 'Unknown');
    console.error('[Info]', info);

    // In production, you could send this to an error tracking service
    if (import.meta.env.PROD) {
        // Example: Sentry.captureException(err);
    }
};

// Global warning handler (development only)
app.config.warnHandler = (msg: string, _instance, trace: string) => {
    console.warn('[Vue Warning]', msg);
    if (trace) {
        console.warn('[Trace]', trace);
    }
};

// Mount app
app.mount('#app');
