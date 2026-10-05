import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    server: {
        origin: 'httsps://dev.iapi.ge',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',

                // the admin panel — layouts/admin.blade.php asks for both
                'resources/dashboard/admin.css',
                'resources/dashboard/admin.js',

                // page entries — pushed from each page view with @vite(...)
                'resources/css/pages/catalog.css',
                'resources/css/pages/product.css',
                'resources/css/pages/checkout.css',
                'resources/css/pages/info.css',
                'resources/js/pages/home.js',
                'resources/js/pages/product.js',
            ],
            refresh: true,
        }),
    ],
});
