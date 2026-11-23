import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        // The Laravel plugin is for serving and building assets; Vitest does not need it,
        // and it refuses to start when the CI environment variable is set.
        ...(process.env.VITEST
            ? []
            : [
                  laravel({
                      input: ['resources/css/app.css', 'resources/js/app.ts'],
                      refresh: true,
                  }),
              ]),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
    ],
    resolve: {
        alias: { '@': '/resources/js' },
    },
    server: {
        // Reachable from the host when Vite runs in the node container.
        host: '0.0.0.0',
        port: 5173,
        hmr: { host: 'localhost' },
    },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.spec.ts'],
    },
});
