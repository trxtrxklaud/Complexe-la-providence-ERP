# خطة اختبار الأقفال والتزامن الفعلي على MySQL/MariaDB
**ملف التوثيق:** `tests/Documentation/CollectionFeatureMySqlConcurrencyPlan.md`  
**التاريخ:** 21 سبتمبر 2026  
**المراجع:** Database Concurrency Test Specialist  
**الحالة:** ⚠️ **مخطط معزول لبيئة MySQL Staging / Testing — ممنوع قطعياً تنفيذه على الإنتاج (`providence_prod`)**

---

## 1. ما الذي يختبره SQLite وما الذي يعجز عنه تماماً؟

### ما يختبره SQLite in-memory:
- التحقق من تكامل العمليات داخل المعاملة الواحدة (`DB::transaction`).
- اختبار التراجع الذري (`DB::rollBack()`) عند حدوث أخطاء برمجية في معالجة السجلات.
- التحقق من المعادلات الرياضية وحساب `StudentFee::outstanding()`.

### ما يعجز عنه SQLite تماماً ولا يثبته لكود الإنتاج:
1. **`SELECT ... FOR UPDATE` (Pessimistic Row Locking):** لا يدعم SQLite الأقفال الصفية؛ الاستعلام يُمرر شكلياً دون حجز صفوف حقيقي.
2. **التزامن الحقيقي (Real Concurrency):** تعمل اختبارات PHPUnit في بيئة عملية أحادية (Single PHP Process) ولا تنشئ اتصالات متزامنة تتنافس على نفس الجدول.
3. **Deadlocks & Lock Wait Timeout:** لا يمكن لـ SQLite محاكاة مهلة انتظار القفل (`innodb_lock_wait_timeout`) أو اكتشاف التعارض التبادلي لصفوف InnoDB.
4. **مستويات العزل (Isolation Levels):** لا يطبق SQLite عزل `REPEATABLE READ` أو أقفال الفجوات (Gap Locks / Next-Key Locks) الخاصة بمحرك MySQL.

---

## 2. حقيقة اختبار "الطلبات المتزامنة" الحالي في `IdempotencyAndConcurrencyTest`

في الاختبار `test_two_parallel_requests_cannot_overcollect_same_fee`:
```php
$attemptedAmount = 60.0;
$this->expectException(InvalidArgumentException::class);
if ($attemptedAmount > $outstanding) {
    throw new InvalidArgumentException("المبلغ ({$attemptedAmount}) يتجاوز المتبقي ({$outstanding})");
}
```
> [!WARNING]
> **تدقيق فني صارم:** هذا الاختبار هو مجرد **محاكاة تسلسلية أحادية**، حيث تم رمي الاستثناء يدوياً داخل كود الفحص إذا كان الرقم أكبر من المتبقي. لم تكن هناك عمليتان متزامنتان، ولا اتصالان منفصلان، ولا يوجد أي إثبات على أن كود الإنتاج سيمنع التجاوز إذا ضرب طلبان الخادم في نفس الميلي ثانية!

---

## 3. التصميم المعماري لاختبار التزامن الحقيقي على MySQL (`providence_testing`)

لتأكيد الأمان المالي 100% قبل الإنتاج، يجب تشغيل اختبار تكامل حقيقي عبر بيئة MySQL مستقلة باستخدام اتصالي قاعدة بيانات منفصلين تماماً (`Two Separate PDO Connections`):

```
                   ┌──────────────────────────────────────┐
                   │     MySQL: providence_testing        │
                   │      (InnoDB Engine - Isolated)      │
                   └──────────────────┬───────────────────┘
                                      │
              ┌───────────────────────┴───────────────────────┐
              │                                               │
   [Connection 1: Cashier A]                       [Connection 2: Cashier B]
              │                                               │
  1. BEGIN TRANSACTION                                        │
  2. SELECT * FROM student_fees                               │
     WHERE id = 100 FOR UPDATE;                               │
     (حجز القفل الصفي)                                         │
  3. sleep(2) محاكاة معالجة                                  1. BEGIN TRANSACTION
              │                                    2. SELECT * FROM student_fees
              │                                       WHERE id = 100 FOR UPDATE;
              │                                       ===> [BLOCKED / ينتظر القفل]
  4. دفع 50 د.ت (نصف أول)                                     │
  5. COMMIT & RELEASE LOCK                                    │
              │                                               │
              └──────────────────────────────────────────────>│
                                                   3. استيقاظ واستلام القفل
                                                   4. قراءة المتبقي الفعلي (50 د.ت)
                                                   5. محاولة دفع 60 د.ت
                                                   ===> ترفض العملية لتجاوز المتبقي!
                                                   6. ROLLBACK
```

---

## 4. الشيفرة البرمجية لاختبار التزامن الحقيقي (MySQL Concurrency Test Specification)

يتم تشغيل هذا الاختبار في مسار `tests/Integration/MySqlConcurrencyTest.php` مع تفعيل الاتصال بـ `mysql_testing`:

```php
<?php

namespace Tests\Integration;

use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\StudentFee;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // حارس أمان حرج: التأكد أن الاتصال ليس قاعدة الإنتاج إطلاقاً
        $dbName = config('database.connections.mysql.database');
        if ($dbName === 'providence_prod' || app()->environment('production')) {
            $this->markTestSkipped('CRITICAL SAFETY: Cannot run concurrency test on production database.');
        }
    }

    public function test_two_distinct_connections_prevent_double_collection_via_pessimistic_lock(): void
    {
        // تهيئة الاتصال الثاني المستقل
        config(['database.connections.mysql_second' => config('database.connections.mysql')]);

        $fee = StudentFee::create([
            'enrollment_id' => 1,
            'fee_type_id' => 1,
            'description' => 'قسط سبتمبر — اختبار التزامن',
            'amount_due' => 100.0,
            'due_date' => '2025-09-01',
            'status' => 'pending',
        ]);

        $conn1 = DB::connection('mysql');
        $conn2 = DB::connection('mysql_second');

        // الجلسة الأولى تحجز الصف
        $conn1->beginTransaction();
        $lockedFee1 = $conn1->table('student_fees')
            ->where('id', $fee->id)
            ->lockForUpdate()
            ->first();

        $this->assertNotNull($lockedFee1);

        // محاولة الجلسة الثانية حجز نفس الصف مع timeout سريع (1 ثانية)
        $conn2->beginTransaction();
        $conn2->statement('SET innodb_lock_wait_timeout = 1');

        $lockFailed = false;
        try {
            $conn2->table('student_fees')
                ->where('id', $fee->id)
                ->lockForUpdate()
                ->first();
        } catch (\Illuminate\Database\QueryException $e) {
            // كود خطأ MySQL 1205: Lock wait timeout exceeded
            if (str_contains($e->getMessage(), '1205') || str_contains($e->getMessage(), 'Lock wait timeout')) {
                $lockFailed = true;
            }
        }

        $conn1->rollBack();
        $conn2->rollBack();

        $this->assertTrue($lockFailed, 'نجح اختبار الأقفال: تم حظر الجلسة الثانية ومنعها من قراءة الصف المحجوز بالتوازي.');
    }
}
```

---

## 5. ضوابط تشغيل خطة التزامن (Execution Safety Rules)

1. **البيئة المعتمدة:** حصرياً على بيئة التطوير المحلية (Laragon) أو خادم اختبار منعزل تماماً (Staging VPS).
2. **قاعدة البيانات:** اسم صريح لا يقبل اللبس: `providence_testing`.
3. **التنظيف التلقائي:** يتم تفريغ الجداول أو تنفيذ `migrate:fresh` فقط على قاعدة `providence_testing` بعد انتهاء الحزمة.
4. **المنع القاطع:** لا يجوز إرسال هذا الملف أو تشغيله على خادم الإنتاج `72.60.91.44` تحت أي ظرف.
