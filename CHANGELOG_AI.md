# AI Change Log

## 2026-08-24 — Visual & interaction polish layer

### Summary

- طبقة تجميلية/تفاعلية **إضافية بحتة** فوق البنية القائمة (لا إعادة بناء) لرفع الإحساس البصري إلى مستوى SaaS تعليمي، مع صون هوية المدرسة.
- صفر تغيير في النصّ الظاهر أو منطق الأعمال أو الـAPI أو الـroutes أو الـprops أو الـstate؛ ولا مساس بالـbackend أو التدفّقات المالية الحسّاسة.
- استُعمل `motion` (Framer Motion) المثبّت أصلًا — بلا أيّ تبعيات جديدة.

### Modified source files

- `resources/css/app.css` — أساس عامّ: Inter للّاتيني/الأرقام + IBM Plex Sans Arabic؛ `:focus-visible` نحاسي؛ scrollbars رفيعة؛ `tabular-nums` على الجداول؛ `::selection`؛ `.skeleton-shimmer`؛ `.card-interactive` — كلّها داخل `@media (prefers-reduced-motion: no-preference)` وopt-in.
- `resources/views/app.blade.php` — إضافة `Inter` إلى رابط Google Fonts (تراجع آمن دون اتصال). `@viteReactRefresh` باقٍ قبل `@vite`.
- `resources/js/App.tsx` — انتقال دخول بين الموديولات (`motion.div` مفتاحه أوّل مقطع من المسار) + `MotionConfig reducedMotion="user"` + تركيب `ToastProvider`. الـroutes/الحرّاس دون مساس.
- `resources/js/components/Sidebar.tsx` — شريط قابل للطيّ (256⇄76px) محفوظ في `localStorage` + tooltips عبر portal. الروابط/الصلاحيات/الخروج حرفيًا كما هي.
- `resources/js/components/DataSkeleton.tsx` — ترقية `animate-pulse` إلى shimmer بلون الهوية (نفس الـexports/الـprops).
- `resources/js/pages/Dashboard/Dashboard.tsx` — كروت KPI: hover-lift + ظلّ موحّد + دخول متدرّج؛ مودال الديون السابقة: blur + دخول متحرّك. نفس التسميات والقيم والبيانات.
- `resources/js/pages/Users/UsersList.tsx` و`resources/js/pages/FeeTypes/FeeTypesPage.tsx` — خطأ الحذف فقط: `alert()` → `toast.error()` بنفس النصّ.
- `resources/js/pages/Families/FamiliesListPage.tsx` — حالة الفراغ غير الجدولية → `EmptyState` بنفس النصّ.
- `vite.config.ts` — **إصلاح حرج:** نقل حزم `motion`/`motion-dom`/`motion-utils`/`framer-motion` إلى مجموعة `react-vendor`.

### New files

- `resources/js/components/ui/Toast.tsx` — `ToastProvider`/`useToast()`/`<Toaster>` (success/error/info، RTL، portal، إخفاء تلقائي).
- `resources/js/components/EmptyState.tsx` — إليستريشن SVG inline بلون الهوية (آمن دون اتصال).

### Critical fix — motion in the wrong chunk

- **العرض:** شاشة بيضاء عند الإقلاع — `TypeError: Cannot read properties of undefined (reading 'createContext')`.
- **السبب:** اعتماد دائري بين `react-vendor` (عبر `react-router-dom → history`) وحزمة `vendor`؛ و`motion` ينشئ سياقات React وقت تحميل الوحدة، فوقع على React قبل تهيئة حزمته.
- **الحلّ:** إبقاء أيّ مكتبة تلمس React وقت التحميل في `react-vendor`. بعده انكمش `vendor` من ‎133KB إلى ‎3.85KB.

### Important results

- `tsc --noEmit`: نظيف. `vite build`: ناجح دون تحذيرات حدود chunks.
- شاشة الدخول تُصيَّر سليمة؛ قواعد الأساس الستّ حاضرة في CSS المبني.
- أكّد المستخدم أنّ المنصة تعمل جيدًا بعد الإصلاح.

### Intentionally unchanged

- لا تغيير في النصوص/التسميات، ولا routes/API/props/state، ولا منطق مصادقة/صلاحيات/مالية.
- لم تُضف dependencies، ولم يُرفَع `chunkSizeWarningLimit`، ولم تُعدَّل مخرجات `public/build` يدويًا.
- كلّ `alert()`/`confirm()` في التدفّقات المالية باقية سليمة.



### Summary

- حُللت حزمة React الحالية وتبيّن أن `App.tsx` كان يستورد جميع الصفحات مباشرة ضمن الحزمة الرئيسية.
- أضيف تقسيم محافظ لحزم Vite.
- طُبّق التحميل الكسول للصفحات الثقيلة وقليلة الاستخدام دون تغيير routing أو business logic.
- تم التحقق من build ومن سلامة React Refresh والتشغيل المحلي.

### Modified source files

- `vite.config.ts`
  - إضافة `build.rollupOptions.output.manualChunks`.
  - فصل React/Router وLucide وبقية vendor dependencies.
- `resources/js/App.tsx`
  - استبدال imports المباشرة للصفحات الثقيلة بـ`React.lazy()`.
  - إضافة `Suspense` fallback واحد حول شجرة routes.

### Important results

- `npm run build`: ناجح دون تحذير chunk كبير.
- حجم `main` انخفض من `272.15 KB` إلى `38.23 KB`.
- gzip للحزمة الرئيسية انخفض من `51.23 KB` إلى `10.33 KB`.
- ظهرت chunks مستقلة، منها:
  - `react-vendor`: `229.07 KB`.
  - `icons-vendor`: `24.52 KB`.
  - `EmployeesPage`: `32.25 KB`.
  - `RosterPage`: `22.61 KB`.
  - `NewStudentWizard`: `21.27 KB`.
  - `CollectionPage`: `19.81 KB`.

### Verification

- Vite استجاب محليًا على المنفذ `5173`.
- Laravel استجاب محليًا على المنفذ `8001`.
- `resources/views/app.blade.php` ما زال يحتوي `@viteReactRefresh` قبل `@vite(...)`.
- HTML المحلي أكد أن React Refresh يسبق نقطة دخول `resources/js/main.tsx`.

### Intentionally unchanged

- لم تتغير routes أو أسماء الصفحات أو API contracts.
- لم يتغير `resources/views/app.blade.php`.
- لم يتغير منطق المصادقة أو الصلاحيات.
- لم يتغير أي منطق مالي أو Backend business logic.
- لم تُضف مكتبات أو dependencies جديدة.
- لم تُعدّل ملفات `public/build` يدويًا ولم تُضمّن مخرجات build في commits المصدرية.

### Relevant commits

- `2a8fdcbd perf(build): تقسيم حزم الواجهة الأساسية`
- `a213a901 perf(frontend): تحميل الصفحات الثقيلة عند الطلب`

