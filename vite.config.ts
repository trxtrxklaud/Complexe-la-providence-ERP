import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// ملاحظة: على ويندوز يُنتج host: '0.0.0.0' عنواناً لا يقبله المتصفّح
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
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) return;

                    if (
                        id.includes('/react/')
                        || id.includes('/react-dom/')
                        || id.includes('/react-router-dom/')
                        || id.includes('/react-router/')
                        // motion (Framer Motion) ينشئ سياقات React عند التحميل، فيجب أن
                        // يُهيّأ في نفس حزمة React لا في vendor — وإلّا فاعتماد دائري
                        // بين الحزمتين يجعل React غير مُعرّف وقت تهيئته (createContext على undefined).
                        || id.includes('/motion/')
                        || id.includes('/motion-dom/')
                        || id.includes('/motion-utils/')
                        || id.includes('/framer-motion/')
                    ) {
                        return 'react-vendor';
                    }

                    if (id.includes('/lucide-react/')) {
                        return 'icons-vendor';
                    }

                    return 'vendor';
                },
            },
        },
    },
    server: {
        host: devHost,
        watch: {
            ignored: ['**/vendor/**', '**/node_modules/**', '**/storage/**'],
        },
    },
});
