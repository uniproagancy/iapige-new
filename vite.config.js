import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
	server: {
        origin: 'http://127.0.0.1:2307',
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',

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
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
