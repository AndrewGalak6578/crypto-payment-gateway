import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import process from 'process';

const devHost = process.env.VITE_DEV_SERVER_HOST || 'localhost';
const devPort = Number(process.env.VITE_DEV_SERVER_PORT) || 5173;
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/merchant-portal/main.js'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        port: devPort,
        strictPort: true,
        hmr: {
            host: '192.168.110.64',
            port: devPort,
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
