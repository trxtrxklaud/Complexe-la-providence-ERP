# مراجعة أمان بيئة الاختبارات وعزل قاعدة البيانات
**ملف التدقيق:** `tests/Documentation/CollectionFeatureTestSafetyReview.md`  
**التاريخ:** 21 سبتمبر 2026  
**المراجع:** Principal Laravel QA Reviewer & Database Concurrency Specialist  
**الحالة:** 🟢 **معزول محلياً (SQLite in-memory)** مع **تنبيهات أمان حرجة** تتطلب إضافة حراس برمجيين.

---

## 1. ما هو `DB_CONNECTION` و `DB_DATABASE` الفعلي وقت الاختبارات؟

- **`DB_CONNECTION` الفعلي:** `sqlite`
- **`DB_DATABASE` الفعلي:** `:memory:`

### الدليل القاطع من سجلات التنفيذ:
أثناء تشغيل اختبار `CollectionFinancialIntegrationTest` قبل ضبط حقل `direction`، أصدر محرك PDO الاستثناء التالي:
```text
QueryException: SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: cash_transactions.direction
(Connection: sqlite, Database: :memory:, SQL: insert into "cash_transactions" ...)
```
وهذا يثبت قطعياً أن التنفيذ جرى بنسبة 100% داخل ذاكرة الرام (RAM) ولم يُرسل أي استعلام إلى محرك MySQL أو خادم الإنتاج.

---

## 2. ما الذي تغير في `phpunit.xml` بالضبط؟ (Git Diff Analysis)

تم فحص سجل التغييرات في `phpunit.xml` عبر `git diff`، وكان التغيير محصوراً فقط في إضافة فئة `Unit` التي كانت ناقصة من تعريف حزم الاختبار:

```diff
--- a/phpunit.xml
+++ b/phpunit.xml
@@ -5,6 +5,9 @@
          colors="true"
          cacheDirectory=".phpunit.cache">
     <testsuites>
+        <testsuite name="Unit">
+            <directory>tests/Unit</directory>
+        </testsuite>
         <testsuite name="Feature">
             <directory>tests/Feature</directory>
         </testsuite>
```

### تقييم التغيير:
- **هل التعديل ضروري؟** نعم، لأن Laravel 12 يتطلب تعريف مسار `tests/Unit` لتشغيل اختبارات الوحدة معاً عند استدعاء `php artisan test`.
- **هل غيّر إعدادات قاعدة البيانات؟** لا؛ إعدادات `DB_CONNECTION=sqlite` و `DB_DATABASE=:memory:` كانت موجودة مسبقاً في أصل الملف السليم (Lines 26-27).
- **هل التغيير آمن للحفظ في Git؟** نعم، هو تعديل قياسي لا يحمل أي أسرار أو مخاطر.

---

## 3. هل يوجد ضمان يمنع تشغيل الاختبارات على `APP_ENV=production`؟

> [!CAUTION]
> **ثغرة أمان مكتشفة:** لا يوجد حالياً في كود `tests/TestCase.php` أو `bootstrap/app.php` حارس برمجي صريح (Assertion Guard) يمنع تشغيل حزم الاختبارات إذا كانت البيئة `production`!

إذا قام أحد مستقبلاً بتنفيذ `php artisan config:cache` على الخادم أو نسي تمرير ملف التكوين، فإن الاختبارات قد تُنفذ ضد قاعدة الإنتاج!

### التوصية الإلزامية لحماية الإنتاج:
يجب تضمين الشرط التالي في دالة `setUp()` داخل [`tests/TestCase.php`](file:///c:/laragon/www/providence/tests/TestCase.php):
```php
protected function setUp(): void
{
    parent::setUp();

    if (app()->environment('production') || config('database.default') === 'mysql' && str_contains(config('database.connections.mysql.database'), 'prod')) {
        throw new \RuntimeException('CRITICAL: Tests must NEVER run in production or against production database!');
    }
}
```

---

## 4. هل قيود المفاتيح الأجنبية (`Foreign Keys`) مفعلة؟

- في ملف [`config/database.php`](file:///c:/laragon/www/providence/config/database.php#L26):
  ```php
  'sqlite' => [
      'driver' => 'sqlite',
      'url' => env('DB_URL'),
      'database' => env('DB_DATABASE', database_path('database.sqlite')),
      'prefix' => '',
      'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
  ],
  ```
- **النتيجة:** نعم، القيود مفعلة افتراضياً (`PRAGMA foreign_keys = ON`). وقد ثبت ذلك عملياً عندما رفض `FeePlan::create()` في الاختبارات غياب `FeeCategory` المرتبط به.

---

## 5. الفوارق الجوهرية بين SQLite in-memory و MySQL الإنتاجي

على الرغم من فائدة SQLite في سرعة الفحص المنطقي، إلا أنه **لا يمكن اعتباره بديلاً لإثبات سلامة الإنتاج** للأسباب التقنية التالية:

| الخاصية | SQLite in-memory | MySQL / MariaDB (الإنتاج) | الأثر على ميزات الاستخلاص |
|---|---|---|---|
| **`SELECT ... FOR UPDATE`** | يُتجاهل نحوياً ولا يقفل الصفوف فعلياً | قفل صفي حقيقي (Pessimistic Row Lock) | SQLite لا يثبت منع التزامن الحقيقي بين جلستي استخلاص متزامنتين. |
| **تعارض المفاتيح الفريدة (`Unique`)** | فحص بسيط في الذاكرة | فحص الفهارس مع Deadlock Detection | سلوك `idempotency_key` تحت ضغط الطلبات يحتاج فحص InnoDB. |
| **مستويات العزل (`Isolation Levels`)** | Serializable افتراضياً للعملية الواحدة | `REPEATABLE READ` افتراضياً | ظهور الدفعات غير المثبتة (Dirty/Phantom Reads) يختلف تماماً. |
| **حقول JSON والدوال التحليلية** | يعامل JSON كنص عادي وله دوال استخراج محدودة | دعم شجري عميق وفهارس افتراضية (Virtual Generated Columns) | حقل `meta` في جدول `payments` يحتاج فحص توافق كامل. |
| **حساسية الأحرف (Collation)** | مقارنة النصوص افتراضياً ثنائية (`BINARY`) | `utf8mb4_unicode_ci` غير حساس لحالة الأحرف | البحث في الأكواد والأسماء يختلف بين المحركين. |

---

## 6. تقييم مخاطر استخدام `.env` الإنتاجي

1. يوجد في المشروع ملف [`.env.testing`](file:///c:/laragon/www/providence/.env.testing) يحتوي على:
   ```ini
   DB_CONNECTION=mysql
   DB_DATABASE=providence_testing
   ```
2. ولكن في `phpunit.xml`، يتم تجاوز ذلك بقيم:
   ```xml
   <env name="DB_CONNECTION" value="sqlite"/>
   <env name="DB_DATABASE" value=":memory:"/>
   ```
3. **المخاطرة القائمة:**
   إذا قام مطور أو أمر CI/CD بتشغيل الاختبارات عبر مسار بديل يتجاهل `phpunit.xml` (مثل تشغيل سكريبت خارجي أو تشغيل `artisan test` مع `config:cache` مسبق)، فسيعتمد النظام على `.env` المحلي، مما قد يوجه العمليات إلى MySQL.
4. **الحل المقترح:** تفريغ الكاش المالي قبل أي اختبار وتثبيت شرط التحقق الصريح في بيئة التشغيل.
