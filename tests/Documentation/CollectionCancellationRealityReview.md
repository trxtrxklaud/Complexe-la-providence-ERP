# مراجعة واقع كود الإلغاء والاسترجاع ومصير الرسوم الشهرية
**ملف التدقيق:** `tests/Documentation/CollectionCancellationRealityReview.md`  
**التاريخ:** 21 سبتمبر 2026  
**المراجع:** Principal Laravel QA Reviewer  
**الهدف:** فحص السلوك الفعلي لكود الإنتاج في [`PaymentController.php`](file:///c:/laragon/www/providence/app/Http/Controllers/PaymentController.php) ومقارنته بالسياسات المصممة واختبارات الإلغاء.

---

## 1. هل يحذف النظام `StudentFee` عند الإلغاء أم يبقيه؟ (الحقيقة البرمجية)

> [!IMPORTANT]
> **اكتشاف معماري جوهري في كود الإنتاج:**  
> نعم، كود الإنتاج الحالي في [`PaymentController::cancel()`](file:///c:/laragon/www/providence/app/Http/Controllers/PaymentController.php#L228-L238) **يقوم بالفعل بحذف سجل `StudentFee`** في حالات محددة وفق الشرط التالي:

```php
if ($fee->fee_plan_id === null
    && $fee->club_monthly_fee_id === null
    && ! $hasOtherActiveAllocations
    && ! $isPriorYearDebt
    && ! $isManualDebtBridge
    && ! $fee->waivers()->exists()
) {
    $fee->delete();
    continue;
}
```

### لماذا صُمم الكود الأصلي على حذف الرسم؟
في فلسفة نظام `Complexe La Providence`:
- رسوم الأشهر (`student_fees`) **لا تُنشأ مسبقاً** في أول السنة لكل الأشهر العشرة، بل تُنشأ لحظياً عند حضور الولي والاستخلاص (`On-Demand Creation`) بقيمة المبلغ المقبوض وبحقل `fee_plan_id = null`.
- إذا أخطأ القابض وألغى الدفعة فوراً، فإن حذف هذا الرسم المؤقت يعيد الشهر إلى الحالة "غير المستخلصة" ويمنع تراكم رسم غير مدفوع في المتخلدات القديمة.

---

## 2. معضلة نصف الشهر مع سياسة حذف الرسم الحالية

هنا تظهر الثغرة الخطيرة التي تستوجب التعديل المعماري الدقيق قبل إطلاق ميزة "نصف الشهر":

### السيناريو أ: إلغاء الجزء الثاني بعد دفع الجزأين
- دفعة 1 (50 د.ت) + دفعة 2 (50 د.ت) على نفس الرسم (100 د.ت).
- عند إلغاء الدفعة 2: يكتشف النظام أن `$hasOtherActiveAllocations = true` (تخصيص الدفعة 1 ما زال سارياً).
- **النتيجة الحالية:** لا يُحذف الرسم، ويتم استدعاء `recalculateStudentFeeStatus()` فيتحول الرسم من `paid` إلى `partial` برصيد متبقٍ 50 د.ت. (سلوك سليم 100%).

### السيناريو ب: إلغاء الجزء الأول قبل دفع الجزء الثاني
- تم استخلاص النصف الأول فقط (50 د.ت)، ثم قرر القابض إلغاءه.
- وفق كود الإنتاج الحالي: `$hasOtherActiveAllocations = false`، والرسم ليس عليه تنازل ولا دين قديم.
- **النتيجة الحالية:** سيقوم `PaymentController` **بحذف سجل `StudentFee` نهائياً**!
- **التقييم المحاسبي:** حذف الرسم في هذه الحالة يعيد الشهر مفتوحاً كلياً دون أي أثر مالي، وهو سليم محاسبياً لأن الشهر لم يُدفع منه شيء في النهاية؛ لكنه يتعارض مع نموذج "الرسم التوليدي المسبق".

---

## 3. التحقق من سلامة دورة الإلغاء في الدفتر والخزينة

أثبتت المراجعة التفصيلية أن دورة الإلغاء الحالية في النظام تحافظ على الثوابت المحاسبية التالية:
1. **لا حذف لسجل الدفعة:** يبقى سجل `payments` محفوظاً وتُعبأ حقوله (`cancelled_at`, `cancelled_by`, `cancellation_reason`) لأغراض التدقيق.
2. **عكس قيد الخزينة بالكامل:** يستدعي الكود `LedgerService::cancelFor($payment)`، مما يضع `cancelled_at = now()` على قيد `cash_transactions`، فيُطرح المبلغ فورياً من رصيد الخزينة والتقارير المالية.
3. **استبعاد التخصيصات:** كل عمليات حساب `outstanding()` و `total_allocated` تستبعد تلقائياً التخصيصات التابعة لدفعات ملغاة (`whereNull('payments.cancelled_at')`).

---

## 4. حقيقة سياسة "الإقفال اليومي ومرور 24 ساعة"

في اختبار `tests/Unit/CancellationAndRefundTest.php`:
```php
public function test_cancellation_after_daily_closure_requires_admin_override(): void
{
    $paymentDate = now()->subDays(2)->toDateString();
    $isPastClosedDay = (strtotime($paymentDate) < strtotime(now()->toDateString()));
    $currentUserRole = 'cashier';
    $requiresAdminOverride = $isPastClosedDay && $currentUserRole === 'cashier';

    if ($requiresAdminOverride) {
        throw new InvalidArgumentException('تم إقفال اليومية المالية لهذه الدفعة؛ يلزم موافقة المدير المالي للإلغاء');
    }
}
```

> [!WARNING]
> **تصحيح هام:**  
> هذا الفحص **غير موجود إطلاقاً في كود الإنتاج الحالي**!  
> في ملف [`PaymentController.php`](file:///c:/laragon/www/providence/app/Http/Controllers/PaymentController.php)، لا يوجد أي قيد زمني على الإلغاء، ويستطيع أي مستخدم يحمل صلاحية `manage_payments` إلغاء أي وصل حتى لو مضت عليه أسابيع.  
> ما ورد في الاختبار كان مجرد **توصية تصميمية رقابية مقترحة**، وتمت محاكاتها بـ `throw` يدوي داخل التيست.

---

## 5. القرارات المعمارية الواجب اعتمادها في فرع التطوير المنفصل

1. **حماية الرسم متعدد الأقساط من الحذف المفاجئ:** إضافة شرط يمنع حذف `StudentFee` إذا كان نوعه يقبل التجزئة، أو إبقاء الحذف فقط في حال عدم وجود أي سداد فعلي معتمد.
2. **برمجة قيد الإقفال اليومي:** نقل شرط منع القابض من الإلغاء بعد مرور يوم العمل إلى `PaymentPolicy` أو `PaymentController::cancel` بدلاً من بقائه فكرة غير منفذة في ملف الاختبار.
