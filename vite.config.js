import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';
import os from 'node:os';

export default defineConfig(({ mode, command }) => ({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
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
        drop: command === 'build' && mode === 'production' ? ['console', 'debugger'] : [],
    },
}));
