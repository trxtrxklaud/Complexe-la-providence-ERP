import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// ملاحزة: على ويندوز يُنتج host: '0.0.0.0' عنواناً لا يقبله المتصفّح
// فتُحمّل الصفحة دون ملفّات React فتبدو بيضاء بلا رسالة خطأ.
// لفتح الخادم على الشبكة المحلية (الهاتف مثلاً): VITE_DEV_HOST=0.0.0.0 npm run dev
const devHost = process.env.VITE_DEV_HOST || 'localhost';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/main.tsx', 'resources/css/app.css'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    server: {
        host: devHost,
        watch: {
            ignored: ['**/vendor/**', '**/node_modules/**', '**/storage/**'],
        },
    },
});
