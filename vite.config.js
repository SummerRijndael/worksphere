import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
import os from 'node:os';

export default defineConfig(({ mode, command }) => ({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.ts',
                'resources/css/call.css',
                'resources/js/call.ts',
            ],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            util: fileURLToPath(new URL('./node_modules/util/util.js', import.meta.url)),
            process: fileURLToPath(new URL('./node_modules/process/browser.js', import.meta.url)),
            buffer: fileURLToPath(new URL('./node_modules/buffer/index.js', import.meta.url)),
            events: fileURLToPath(new URL('./node_modules/events/events.js', import.meta.url)),
        },
    },
    server: {
        host: '0.0.0.0',
        hmr: {
            host: (() => {
                const interfaces = os.networkInterfaces();
                for (const name of Object.keys(interfaces)) {
                    for (const iface of interfaces[name]) {
                        if (iface.family === 'IPv4' && !iface.internal) {
                            return iface.address;
                        }
                    }
                }
                return 'localhost';
            })(),
        },
        watch: {
            ignored: [
                '**/storage/framework/views/**',
                '**/storage/logs/**',
                '**/vendor/**',
                '**/node_modules/**',
            ],
        },
    },
    esbuild: {
        drop: command === 'build' && mode === 'production' ? ['debugger', 'console'] : [],
    },

    optimizeDeps: {
        include: ['util', 'process', 'buffer', 'events', 'simple-peer'],
    },
    define: {
        global: 'window',
        'process.env': {},
    },
}));
