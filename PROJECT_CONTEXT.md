# Project Context

## Project overview

`Complexe La Providence ERP` نظام داخلي لإدارة مدرسة خاصة في تونس. يغطي التلاميذ والتسجيل والاستخلاص والموظفين والرواتب والمصاريف والخزينة والتقارير المالية. الواجهة عربية RTL والعملة دينار تونسي.

## Tech stack

- Backend: Laravel 12، PHP 8.2+، Sanctum 4.
- Frontend: React 19، TypeScript 5.8، React Router 7، Vite 6، Tailwind CSS 4، `lucide-react`.
- Development database: SQLite. Production target: MySQL 8.
- Tests: PHPUnit 11 باستخدام SQLite داخل الذاكرة.

## Local run commands

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve --host=127.0.0.1 --port=8001
npm run dev
```

التحقق الأساسي:

```bash
php artisan test
npm run lint
npm run build
```

على Windows قد يلزم استعمال مسار PHP الخاص بـLaragon بدل `php` إذا لم يكن موجودًا في `PATH`.

## Important entry points

- `resources/js/main.tsx`: نقطة دخول React.
- `resources/js/App.tsx`: شجرة المسارات، الحراس، والتحميل الكسول للصفحات.
- `resources/views/app.blade.php`: قالب Laravel الذي يحمّل Vite.
- `vite.config.ts`: إعداد Vite وتقسيم حزم الإنتاج.
- `routes/api.php`: مسارات API وصلاحياتها.
- `resources/js/api/http.ts`: طبقة HTTP والتعامل المركزي مع رمز Sanctum.
- `config/permissions.php`: تعريف الأدوار الخارقة.

## Sensitive areas / do-not-touch zones

لا تعدّل هذه المناطق دون إذن صريح وقراءة كاملة واختبارات مخصصة:

- `LedgerService`
- `CashTransaction`
- `PaymentService`
- `CollectionService`
- `TreasuryDaybookController`
- `FinancialReportController`
- منطق المصادقة والصلاحيات، خصوصًا Sanctum و`AuthController` وFormRequests الخاصة بالمستخدمين.
- `routes/api.php`: تغيير ترتيب أو حراسة المسارات قد يسبب 403 أو يغيّر عقد الواجهة.
- قاعدة SQLite المحلية تحتوي بيانات حقيقية؛ ممنوع `php artisan migrate:fresh`.

ممنوع كذلك `git reset --hard` و`git clean -fd` دون موافقة صريحة.

## Architecture notes

- Laravel يقدم API، وReact SPA تُحمّل من `resources/views/app.blade.php`.
- يجب أن يبقى `@viteReactRefresh` قبل `@vite(...)`. تغيير الترتيب قد يعيد خطأ `@vitejs/plugin-react can't detect preamble`.
- `vite.config.ts` يحتوي `manualChunks` محافظًا:
  - `react-vendor`: React وReact DOM وReact Router.
  - `icons-vendor`: `lucide-react`.
  - `vendor`: بقية حزم `node_modules`.
- `resources/js/App.tsx` يستخدم `React.lazy()` للصفحات الثقيلة أو قليلة الاستخدام، مع `Suspense` fallback واحد.
- تبقى شجرة الإقلاع الأساسية eager: `AuthProvider` و`BrowserRouter` و`Layout` و`Sidebar` و`ProtectedRoute`، إضافة إلى Login وDashboard ومسارات الطلاب الأساسية.
- أول فتح لصفحة lazy يضيف طلب شبكة إضافيًا؛ هذا سلوك طبيعي ومقصود.
- بعد `npm run build` يجب نشر `public/build` و`manifest.json` الناتج معه كوحدة واحدة لتجنب عدم تطابق أسماء الحزم.

## Known constraints

- لا تغيّر أسماء الصفحات أو المسارات أو API contracts أثناء تحسين الأداء.
- لا ترفع `chunkSizeWarningLimit` لإخفاء التحذيرات.
- مخرجات `public/build` توليدية؛ لا تعدّلها يدويًا.
- بعض ملفات التوثيق القديمة، خصوصًا `README.md` و`HANDOFF.md`، قد تذكر Laravel 11 أو معلومات تاريخية؛ الكود و`composer.json` هما المرجع الحالي.
- توجد أخطاء TypeScript سابقة غير مرتبطة بتحسين الأداء؛ راجع نتيجة `npm run lint` ولا تصلح ملفات غير مرتبطة دون تكليف.

## Definition of done

أي تعديل يُعد مكتملًا فقط بعد:

1. قراءة كل ملف قبل تعديله.
2. إبقاء التغيير minimal ومحدودًا بالمطلوب.
3. عدم تغيير منطق الأعمال أو العقود دون إذن.
4. تشغيل الاختبار الأقرب للتغيير.
5. تشغيل `npm run build` لأي تعديل Frontend/Vite.
6. تشغيل `php artisan test` لأي تعديل Backend إن أمكن.
7. التأكد من سلامة `@viteReactRefresh` قبل `@vite(...)` عند لمس الإقلاع.
8. توثيق الأوامر والنتائج والمخاطر والملفات المعدلة بدقة.

