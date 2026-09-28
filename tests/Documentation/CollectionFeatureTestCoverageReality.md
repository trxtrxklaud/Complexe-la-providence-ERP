# تقييم واقعية تغطية الاختبارات: مطابقة السلوك الفعلي مقابل المحاكاة التصميمية
**ملف التدقيق:** `tests/Documentation/CollectionFeatureTestCoverageReality.md`  
**التاريخ:** 21 سبتمبر 2026  
**المراجع:** Principal Laravel QA Reviewer  
**الخلاصة الجوهرية:** الاختبارات الحالية تنقسم بدقة إلى قسمين:
1. **اختبارات أثبتت كفاءة كود الإنتاج الحالي** فيما يخص الاستخلاص الكامل، مفتاح منع التكرار، الدفتر النقدي، والتخفيضات/الإعفاءات الحالية.
2. **اختبارات محاكاة تصميمية بحتة (`DESIGN_ONLY_NOT_PRODUCTION_PROOF`)** لجميع ميزات "نصف الشهر" والتحقق المتقاطع، حيث اعتمدت على Helpers داخلية وإنشاء يدوي للسجلات في الذاكرة، لأن كود `CollectionService` الإنتاجي لم تُنفذ فيه الميزة بعد!

---

## 1. المصفوفة الشاملة لواقعية الاختبارات (51 اختباراً)

| الفئة / الاختبار | يستدعي API/Service حقيقي؟ | يستدعي `CollectionService`؟ | يكتب في قاعدة الاختبار؟ | ينشئ Allocation؟ | ينشئ Ledger؟ | يتحقق من CashTransaction؟ | Mock أم Real؟ | التصنيف والفجوة |
|---|---|---|---|---|---|---|---|---|
| **HalfMonthCollectionTest (13)** | | | | | | | | |
| 1. `test_pre1_can_pay_first_half_of_september` | جزئي (`PaymentService`) | ❌ لا (Helper محلي) | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Mock / Manual | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: لم يمر بـ `CollectionService::collect()` |
| 2. `test_pre2_can_pay_first_half_of_june` | جزئي (`PaymentService`) | ❌ لا (Helper محلي) | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Mock / Manual | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF` |
| 3. `test_pre3_can_pay_full_month_after_first_half` | جزئي (`PaymentService`) | ❌ لا (Helper محلي) | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Mock / Manual | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF` |
| 4. `test_non_pre_level_cannot_pay_half_month` | ❌ لا | ❌ لا | ❌ لا | ❌ لا | ❌ لا | ❌ لا | Helper محلي فقط | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: يفحص دالة داخل ملف الاختبار لا كود الخدمة |
| 5. `test_october_cannot_pay_half_month` | ❌ لا | ❌ لا | ❌ لا | ❌ لا | ❌ لا | ❌ لا | Helper محلي فقط | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: يفحص دالة داخل ملف الاختبار |
| 6. `test_first_half_is_calculated_from_net_due` | ❌ لا | ❌ لا | نعم (يدوي) | ❌ لا | ❌ لا | ❌ لا | حساب رياضي بالتيست | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: معادلة رياضية محلية |
| 7. `test_second_payment_uses_actual_remaining_not_fixed_half` | ❌ لا | ❌ لا | نعم (يدوي) | ❌ لا | ❌ لا | ❌ لا | حساب رياضي بالتيست | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: لم يستدعِ `preview()` |
| 8. `test_millime_rounding_is_absorbed_by_final_payment` | ❌ لا | ❌ لا | نعم (يدوي) | ❌ لا | ❌ لا | ❌ لا | حساب رياضي بالتيست | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF` |
| 9. `test_third_partial_payment_is_rejected` | ❌ لا | ❌ لا | نعم (يدوي) | ❌ لا | ❌ لا | ❌ لا | `throw` يدوي بالتيست | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: الشرط والاستثناء مكتوبان داخل الاختبار نفسه! |
| 10. `test_payment_cannot_exceed_outstanding` | ❌ لا | ❌ لا | نعم (يدوي) | ❌ لا | ❌ لا | ❌ لا | `throw` يدوي بالتيست | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF` |
| 11. `test_fee_status_is_partial_after_first_half` | نعم (`PaymentService`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Real Service على بيانات يدوية | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: يثبت فقط دالة إعادة الحساب |
| 12. `test_fee_status_is_paid_after_remaining_settlement` | نعم (`PaymentService`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Real Service على بيانات يدوية | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF` |
| 13. `test_cancelled_first_half_reopens_correct_outstanding` | نعم (`PaymentService`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Real Service على بيانات يدوية | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF` |
| **DiscountLifecycleTest (8)** | | | | | | | | |
| 14. `test_discount_before_payment_changes_server_preview` | نعم (`MonthlyDiscountService`) | نعم (`preview`) | نعم | ❌ لا | ❌ لا | ❌ لا | Real Services | 🟢 `REAL_SERVICE_PASS`: يثبت كود المعاينة والخصم الحالي |
| 15. `test_discount_cannot_exceed_fee_amount` | نعم (`MonthlyDiscountService`) | ❌ لا | نعم | ❌ لا | ❌ لا | ❌ لا | Real Service | 🟢 `REAL_SERVICE_PASS`: يثبت سقف الـ 20% في الخدمة |
| 16. `test_discount_and_waiver_cannot_exceed_amount_due` | نعم (`FeeWaiverService`) | ❌ لا | نعم | ❌ لا | ❌ لا | ❌ لا | Real Service | 🟢 `REAL_SERVICE_PASS`: يثبت منع تجاوز التنازل |
| 17. `test_monthly_discount_cannot_be_edited_after_partial_payment` | ❌ لا | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | `throw` يدوي بالتيست | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: الاختبار يرمي الاستثناء بنفسه! |
| 18. `test_waiver_after_partial_payment_applies_only_to_remaining_balance` | نعم (`FeeWaiverService`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Real Service | 🟢 `REAL_SERVICE_PASS`: يثبت التنازل على المتبقي |
| 19. `test_closed_fee_cannot_receive_discount_without_finance_override` | نعم (`FeeWaiverService`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Real Service | 🟢 `REAL_SERVICE_PASS`: يثبت رفض التنازل لرسم مسدد |
| 20. `test_discount_has_required_reason_notes_and_actor_audit` | نعم (`MonthlyDiscountService`) | ❌ لا | نعم | ❌ لا | ❌ لا | ❌ لا | Real Service | 🟢 `REAL_SERVICE_PASS`: يثبت حقول التدقيق في الخصم |
| 21. `test_preview_is_recalculated_inside_collection_transaction` | نعم (`MonthlyDiscountService`) | نعم (`preview`) | نعم | ❌ لا | ❌ لا | ❌ لا | Real Services | 🟢 `REAL_SERVICE_PASS`: يثبت ديناميكية المعاينة |
| **IdempotencyAndConcurrencyTest (6)** | | | | | | | | |
| 22. `test_duplicate_idempotency_key_returns_existing_payment` | نعم | نعم (`collect`) | نعم | نعم | نعم | نعم | Real Service | 🟢 `REAL_SERVICE_PASS`: يثبت منع تكرار الدفعة |
| 23. `test_duplicate_idempotency_key_does_not_create_second_ledger_entry` | نعم | نعم (`collect`) | نعم | نعم | نعم | نعم | Real Service | 🟢 `REAL_SERVICE_PASS`: يثبت عدم تكرار قيد الخزينة |
| 24. `test_two_parallel_requests_cannot_overcollect_same_fee` | ❌ لا | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | `throw` يدوي بالتيست | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: محاكاة تسلسلية، لا يوجد توازي |
| 25. `test_transaction_rolls_back_when_ledger_recording_fails` | جزئي (`DB::transaction`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | محاكاة استثناء | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: يختبر تراجع الـ DB العام فقط |
| 26. `test_transaction_rolls_back_when_allocation_fails` | جزئي (`DB::transaction`) | ❌ لا | نعم (يدوي) | ❌ لا | ❌ لا | ❌ لا | محاكاة استثناء | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF` |
| 27. `test_locking_covers_fee_allocations_discounts_and_waivers` | جزئي (`lockForUpdate`) | ❌ لا | نعم (يدوي) | ❌ لا | ❌ لا | ❌ لا | استعلام أحادي | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: استعلام في SQLite لا يثبت الحظر |
| **CancellationAndRefundTest (6)** | | | | | | | | |
| 28. `test_cancelling_first_half_reverses_ledger_and_reopens_fee` | نعم (`LedgerService`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | نعم | نعم | Real Services على بيانات يدوية | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: لم يمر بـ `PaymentController::cancel` |
| 29. `test_cancelling_second_half_restores_partial_status` | نعم (`PaymentService`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Real Services على بيانات يدوية | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF` |
| 30. `test_cancelling_full_payment_restores_pending_or_partial` | نعم (`PaymentService`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Real Services على بيانات يدوية | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF` |
| 31. `test_cancelled_payment_is_excluded_from_paid_months` | نعم (`CollectionService`) | نعم (`getPaidMonths`) | نعم (يدوي) | ❌ لا | ❌ لا | ❌ لا | Real Service | 🟢 `PARTIAL_PROOF`: يثبت استبعاد الملغى من الأشهر |
| 32. `test_cancellation_requires_authorized_role` | ❌ لا | ❌ لا | نعم (يدوي) | ❌ لا | ❌ لا | ❌ لا | فحص كائنات فقط | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: لا يفحص Middleware أو Controller |
| 33. `test_cancellation_after_daily_closure_requires_admin_override` | ❌ لا | ❌ لا | ❌ لا | ❌ لا | ❌ لا | ❌ لا | `throw` يدوي بالتيست | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: سياسة غير منفذة في الكود |
| **ChildcareCollectionTest (6)** | | | | | | | | |
| 34 - 39. (جميع اختبارات الحضانة الستة) | ❌ معلق | ❌ معلق | ❌ معلق | ❌ معلق | ❌ معلق | ❌ معلق | Skipped | ⛔ `BLOCKED_PENDING_OWNER_DECISION` |
| **CollectionHalfMonthApiTest (6)** | | | | | | | | |
| 40. `test_api_preview_returns_correct_gross_and_discount` | نعم (HTTP GET) | نعم (عبر API) | نعم | ❌ لا | ❌ لا | ❌ لا | Real End-to-End | 🟢 `REAL_SERVICE_PASS` (للقسط الكامل) |
| 41. `test_api_collect_with_idempotency_header_prevents_duplicate` | نعم (HTTP POST) | نعم (عبر API) | نعم | نعم | نعم | نعم | Real End-to-End | 🟢 `REAL_SERVICE_PASS` (للقسط الكامل) |
| 42. `test_api_collect_rejects_amount_exceeding_max_payable` | نعم (HTTP POST) | نعم (عبر API) | ❌ لا | ❌ لا | ❌ لا | ❌ لا | Real End-to-End | 🟢 `REAL_SERVICE_PASS` |
| 43. `test_api_collect_issues_proper_receipt_structure` | نعم (HTTP POST) | نعم (عبر API) | نعم | نعم | نعم | نعم | Real End-to-End | 🟢 `REAL_SERVICE_PASS` (للقسط الكامل) |
| 44. `test_api_cancellation_of_payment_reverses_ledger_cash` | نعم (HTTP POST) | نعم (عبر API) | نعم | نعم | نعم | نعم | Real End-to-End | 🟢 `REAL_SERVICE_PASS`: يثبت مسار الإلغاء الكامل |
| 45. `test_api_access_control_blocks_unauthorized_users` | نعم (HTTP POST) | نعم (عبر API) | ❌ لا | ❌ لا | ❌ لا | ❌ لا | Real End-to-End | 🟢 `REAL_SERVICE_PASS`: يثبت حماية Sanctum |
| **CollectionFinancialIntegrationTest (6)** | | | | | | | | |
| 46. `test_treasury_balance_increases_by_exact_payment_amount` | نعم | نعم (`collect`) | نعم | نعم | نعم | نعم | Real Service | 🟢 `REAL_SERVICE_PASS` (للقسط الكامل) |
| 47. `test_first_half_reduces_outstanding_by_exact_collected_amount` | نعم (`PaymentService`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Real Service على بيانات يدوية | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: لم يمر بـ `collect()` |
| 48. `test_second_half_settlement_reduces_outstanding_to_exact_zero` | نعم (`PaymentService`) | ❌ لا | نعم (يدوي) | نعم (يدوي) | ❌ لا | ❌ لا | Real Service على بيانات يدوية | ⚠️ `DESIGN_ONLY_NOT_PRODUCTION_PROOF`: لم يمر بـ `collect()` |
| 49. `test_payment_and_allocation_and_cash_transaction_are_in_one_to_one_correspondence` | نعم | نعم (`collect`) | نعم | نعم | نعم | نعم | Real Service | 🟢 `REAL_SERVICE_PASS` (للقسط الكامل) |
| 50. `test_cancelling_payment_reduces_treasury_by_exact_payment_amount` | نعم (`LedgerService`) | نعم (`collect`) | نعم | نعم | نعم | نعم | Real Service | 🟢 `REAL_SERVICE_PASS` |
| 51. `test_collection_does_not_mutate_prior_debts_or_advances_categories` | نعم | نعم (`collect`) | نعم | نعم | نعم | نعم | Real Service | 🟢 `REAL_SERVICE_PASS` |

---

## 2. الإحصائيات الدقيقة للتصنيف:

- **اختبارات تثبت السلوك الفعلي لكود الإنتاج الحالي (`REAL_SERVICE_PASS`):** **19 اختباراً** (تتعلق باستخلاص الأقساط الكاملة، المعاينة، التخفيضات، التنازل، مفتاح منع التكرار، الدفتر النقدي، وحماية المسارات).
- **اختبارات محاكاة تصميمية بحتة (`DESIGN_ONLY_NOT_PRODUCTION_PROOF`):** **26 اختباراً** (كل ما يتعلق بنصف الشهر، التزامن، والسياسات غير المبرمجة).
- **اختبارات معلقة رسمياً بانتظار قرار المالك (`BLOCKED`):** **6 اختبارات** (الحضانة).

> [!IMPORTANT]
> **الخلاصة الفنية القاطعة:**
> لا يوجد حالياً **أي اختبار واحد** يثبت أن استدعاء مسار `POST /api/payments/collect` بنصف شهر سيعمل على كود الإنتاج الحالي، لأن `CollectionService` الحالي يرفض الشهر فوراً في المرة الثانية بالاستثناء `الشهر مستخلص مسبقاً`!
