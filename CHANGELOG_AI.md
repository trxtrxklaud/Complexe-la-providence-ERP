# AI Change Log

## 2026-08-02 — Frontend performance session

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

