# تقرير إثبات واقع Schema لعمود `fee_types.code` وتحليل أقفال التزامن

**تاريخ الإعداد:** 2026-09-22  
**الفرع:** `feature/partial-month-collection`  
**الحالة المعمارية الحالية:** `BLOCKED_MISSING_PERSISTENT_TUITION_IDENTIFIER`

---

## 1. واقع الـ Schema لعمود `FeeType.code` (Schema Reality Proof)

تم إجراء تدقيق برمجي ومحاسبي شامل على طبقة قاعدة البيانات (قاعدة SQLite المحلية، وملفات الـ Migrations، ونماذج Eloquent) لتحديد حقيقة وجود وتخزين عمود `code` في جدول `fee_types`.

### 1.1 نتائج الفحص المباشر لقاعدة البيانات (SQLite Inspection)
عند استخراج قائمة الأعمدة الفعلية لجدول `fee_types` من قاعدة البيانات عبر Laravel Schema Facade:
```php
Schema::getColumnListing('fee_types');
```
**النتيجة الفعلية المُثبتة:**
```text
Array
(
    [0] => id
    [1] => name_ar
    [2] => name_fr
    [3] => price
    [4] => is_active
    [5] => created_at
    [6] => updated_at
    [7] => ledger_category
)
```
**العمود `code` غير موجود إطلاقاً في الجدول.**

### 1.2 فحص ملفات التهجير (Migrations Audit)
تاريخياً، توجد 4 ملفات تهجير فقط مرتبطة بـ `fee_types`:
1. `2026_07_23_061437_create_fee_types_table.php`: أنشأ الأعمدة (`id`, `name_ar`, `name_fr`, `price`, `is_active`, `timestamps`).
2. `2026_07_27_100000_add_fee_type_id_to_student_fees.php`: ربط الرسوم بالجدول.
3. `2026_07_27_100100_add_ledger_category_to_fee_types.php`: أضاف فقط عمود `ledger_category` لتحديد تصنيف الدفتر المركزي.
4. `2026_08_31_150000_seed_enrollment_supply_fee_types.php`: أدخل أنواع رسوم الترسيم واللوازم، ولم يضف أي أعمدة.

**الخلاصة:** لا يوجد أي Migration في تاريخ المشروع يضيف عمود `code` لجدول `fee_types`.

### 1.3 حقيقة الحفظ والاسترجاع (Persistence Reality)
- **في بيئة الإنتاج وSQLite:** محاولة تنفيذ استعلام يحتوي على `code` مثل:
  ```sql
  INSERT INTO fee_types (name_ar, code, price) VALUES ('القسط الشهري', 'TUITION', 100);
  ```
  تؤدي حتماً إلى خطأ SQL فادح:
  `SQLSTATE[HY000]: General error: 1 table fee_types has no column named code`.
- **دور `getCodeAttribute()` والذاكرة المؤقتة (`$runtimeCodes`):**
  كانت الترقيعات البرمجية السابقة تعترض عملية الحفظ (`saving`) لتحذف `code` من مصفوفة السمات حتى لا ينهار استعلام SQL، ثم تخزنه في مصفوفة ثابتة بالذاكرة (`static array $runtimeCodes`)، مع تصفيرها في `TestCase::setUp()`.
- **الحكم المعماري:** هذا "توافق مصطنع للاختبار" (Synthetic Test Compatibility). فالذاكرة المؤقتة تختفي بين الطلبات (Requests)، ولا وجود لها على خادم الإنتاج، ولا توفر أي أثر تدقيق (Audit Trail)، وتخلق نجاحاً وهمياً للاختبارات لا يطابق واقع النظام.

---

## 2. القرار المعماري الإلزامي

**الحالة المعمارية:**
```text
BLOCKED_MISSING_PERSISTENT_TUITION_IDENTIFIER
(محظور: غياب المعرّف الدائم والمخزن لرسوم التمدرس)
```

1. **إزالة كافة الحلول التلفيقية:**
   - تم حذف `getCodeAttribute` و `setCodeAttribute` من `FeeType`.
   - تم حذف `$runtimeCodes` و `$tempCode` وخطافات الحفظ المؤقتة.
   - تم حذف `FeeType::clearRuntimeCodes()`.
   - تم حذف أي استدعاء لتصفير الرموز في `tests/TestCase.php`.
2. **منع استخدام بدائل غير آمنة:**
   - عدم استخدام أي `fee_type_id` ثابت (Hardcoded).
   - عدم استخدام أي استنتاج تخميني من `ledger_category`.
3. **عدم تطبيق أي Migration حالياً:**
   - التزاماً بالقيود المشددة لعدم تعديل Schema قاعدة البيانات دون إذن مستقل.
4. **حظر التنفيذ البرمجي لنصف الشهر برعايـة النظام:**
   - تم ضبط `CollectPaymentRequest` و `CollectionService::collect` و `CollectionService::preview` و `validateMonths` لترفض أي طلب يتضمن `first_half` أو `remaining` برسالة عمل واضحة:
     > **«ميزة نصف الشهر غير مهيأة: معرف رسوم التمدرس الدائم غير متوفر.»**

---

## 3. التدقيق المعماري: أول إنشاء للرسم (`StudentFee`) وإدارة التزامن

بناءً على طلب التدقيق الإضافي لحالة عدم وجود `StudentFee` قبل استخلاص النصف الأول (`first_half`):

### 3.1 قفل التسجيل (`Enrollment::lockForUpdate`)
- يبدأ مسار التحصيل في `CollectionService::collect()` داخل معاملة مالية (`DB::transaction`) بتنفيذ:
  ```php
  $enrollment = Enrollment::whereKey($data['enrollment_id'])
      ->lockForUpdate()
      ->firstOrFail();
  ```
- **سلوك InnoDB في MySQL:** هذا الاستعلام يحجز قفلاً حصرياً (`X-Lock`) على صف التسجيل المحدد. أي معاملة متزامنة ثانية تحاول قفل نفس التسجيل تُحجب فوراً وتنتظر انتهاء المعاملة الأولى (`commit` أو `rollback`).

### 3.2 فحص القيود الفريدة (`Unique Constraints`)
- عند فحص تهجير جدول `student_fees` (`2026_07_17_000009_create_student_fees_table.php`):
  ```php
  $table->index(['enrollment_id', 'due_date']);
  $table->index('status');
  ```
- **الملاحظة الحرجة:** الفهرس هو فهرس عادي (`INDEX`) وليس فهرساً فريداً (`UNIQUE`).
- **الأثر الهندسي:** لا توجد حماية على مستوى محرك قاعدة البيانات تمنع إدخال صفين لنفس الشهر والتسجيل إذا لم يُحكم القفل.
- **خط الدفاع الوحيد الحالي:** هو قفل التسجيل `Enrollment::lockForUpdate`. بدون هذا القفل، سيؤدي أي طلبين متزامنين إلى قراءة الجدول معاً، وعدم العثور على رسم سابق، ثم قيام كل طلب بإنشاء `StudentFee` مستقل، مما ينتج عنه ازدواجية الرسوم وتخريب المطابقة المحاسبية.

### 3.3 ما يحدث لطلبين متزامنين ينشئان `first_half`؟
1. **الطلب الأول (T1):** يحصل على قفل `Enrollment`. لا يجد رسماً، فينشئ `StudentFee` بمبلغ الشهر كاملاً وحالة `pending`، ثم يربط به دفعة النصف الأول ويصبح الرسم `partial`، ثم ينهي المعاملة (`commit`).
2. **الطلب الثاني (T2):** ينفك حجبه بعد commit الخاص بـ T1. يقوم بفحص الدفعات والأشهر المدفوعة؛ فيجد أن شهر سبتمبر مسجل كـ `partial` ضمن دفعات التسجيل.
3. يرفض T2 إنشاء دفعة `first_half` ثانية للشهر نفسه ويلغي المعاملة دون إنشاء أي رسم مكرر.

---

## 4. خطة وتحديث اختبارات التزامن على MySQL (`providence_testing`)

يجب أن يغطي اختبار MySQL الفعلي حالتين منفصلتين بالكامل فور تشغيل خادم MySQL المحلي:

| الحالة | السيناريو المالي | السلوك الواجب إثباته |
|---|---|---|
| **الحالة (A): عدم وجود StudentFee مسبقاً** | طلبان متزامنان يستخلصان النصف الأول (`first_half`) لنفس التلميذ وشهر سبتمبر | يجب أن يُنشأ `StudentFee` **واحد فقط** ودفعة واحدة فقط، ويرفض الطلب الثاني تكرار الدفعة أو إنشاء رسم مكرر |
| **الحالة (B): وجود StudentFee بحالة partial** | طلبان متزامنان يستخلصان النصف الثاني (`remaining`) لنفس التلميذ والرسم | يُقفل `StudentFee`، تُسجل دفعة واحدة فقط لتصفير المتبقي، ويُرفض الطلب الثاني بسبب استيفاء الدين (`paid`) وعدم وجود متبقٍ |

*حالة الاختبار المالي الحالي على MySQL:* **`PENDING_MYSQL_CONCURRENCY`** (نظراً لتوقف خادم MySQL المحلي على المنفذ 3306).
