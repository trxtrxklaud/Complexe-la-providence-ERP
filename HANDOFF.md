# HANDOFF — ملف تسليم المشروع

> **الملكية:** Complexe La Providence — Prod RH · سيدي بوزيد، تونس. برمجية محفوظة، الاستعمال داخلي للمؤسسة.
>
> **لمن يستلم المشروع:** هذا الملف يشرح المنتج، وما أُنجز، وما تبقّى، ومنهجية العمل المتّفق عليها. اقرأه كاملاً قبل كتابة أي سطر كود. تاريخ آخر تحديث: 2026-07-29.

---

## 1. المؤسسة والمنتج

**Complexe la Providence** — مدرسة خاصة في تونس (ابتدائي + رياض أطفال). المنتج نظام ERP خاص بالمؤسسة يغطّي التلاميذ والتسجيل والاستخلاص والإطارات والأجور والمصاريف والخزينة والتقارير المالية. حلّ محلّ منصّة سابقة اشترتها المدرسة من مزوّد خارجي ولا تملك منها كوداً ولا قاعدة بيانات ولا وثائق؛ لذلك بُني هذا النظام من الصفر ببيانات المؤسسة ومصطلحاتها.

**المستودع:** `trxtrxklaud/Complexe-la-providence-ERP` — الفرع الافتراضي `main`.

### المكدّس التقني

| الطبقة | التقنية |
| --- | --- |
| Backend | Laravel 11 · PHP 8.4 · Sanctum |
| Frontend | React 19 + TypeScript + Vite 6 + Tailwind + lucide-react |
| قاعدة البيانات | SQLite (تطوير) — MySQL لاحقاً في الإنتاج |
| الاختبارات | PHPUnit 10.5 |
| جهاز المستخدم | **Termux على هاتف Android** + حاسوب |

### الواجهة

عربية بالكامل، اتجاه RTL، العملة `د.ت`. لوحة الألوان الثابتة:

```
forest #3B4A36 · deep #2E3B2A · sage #E3EBDB · ink #1F261C
muted  #7C8677 · line #EDF1E8 · bg   #F4F6F1 · layout #E9EEE3
error  #A03434 / خلفية #FDECEC
```

كل الرسائل للمستخدم بالعربية، بما فيها رسائل التحقّق في FormRequests.

---

## 2. قواعد العمل — إلزامية

1. **لا تسجيل (commit) قبل التجربة.** كل تغيير يُختبر أولاً: `php artisan test` و`npm run build`. المستخدم هو المُتحقّق النهائي لأن بيئة النموذج لا تُشغّل PHP.
2. **لا كسر للكود العامل.** التحسينات تكون تراكمية.
3. **خطوة خطوة.** جزء واحد → اختبار → تسجيل → الجزء التالي.
4. **المرجع هو حاجة الإدارة الفعلية.** كل شاشة وكل تقرير يُبنى على ما تستعمله إدارة المدرسة يومياً وبمصطلحاتها، ثم يُحسَّن. لا وظيفة تُضاف لمجرّد أنها موجودة في مكان آخر.
5. **الدفتر النقدي المركزي `cash_transactions` هو المصدر الوحيد للحقيقة المالية.** كل حركة مال (استخلاص، أجر، سلفة، خلاص سلفة، مصروف، سحب) تُسقَط فيه عبر `LedgerService` وحده. لا تحسب أي شاشة أرقامها من جداول العمليات مباشرةً.
6. **احتياطي قاعدة البيانات قبل أي هجرة:**
   ```
   cp database/database.sqlite database/backup-$(date +%m%d-%H%M).sqlite
   ```
7. **ممنوع** `git reset --hard` أو `git checkout -- .` دون فحص `git status` — أتلف ذلك قاعدة بيانات المستخدم مرّة سابقاً.
8. **ممنوع** `php artisan migrate:fresh` على قاعدة الجهاز: تحوي بيانات حقيقية (أكثر من 560 تسجيلاً). جرّبها على نسخة معزولة.

---

## 3. أوامر التشغيل على جهاز المستخدم (Termux)

```bash
cd ~/projects/providence

# سحب التحديثات (استعمل الفرع الذي تعمل عليه)
git pull origin main

# قاعدة البيانات
cp database/database.sqlite database/backup-$(date +%m%d-%H%M).sqlite
php artisan migrate
php artisan db:seed --class=اسم_البذرة

# الاختبار والبناء
php artisan test
npm run build

# التشغيل
pkill -f 'artisan serve'
nohup php artisan serve --host=127.0.0.1 --port=8001 > ~/serve.log 2>&1 &
curl -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8001
```

### مزالق Termux المعروفة

| المشكلة | الحل |
| --- | --- |
| `E: Unable to locate package php-pdo` | Termux يحزم كل الإضافات داخل `php` — لا تُثبّت إضافات منفصلة |
| `vendor/bin/phpunit: Permission denied` | `termux-fix-shebang vendor/bin/*` ثم `chmod +x vendor/bin/*` |
| `bash: !': event not found` | علامة `!` داخل علامتَي اقتباس مزدوجتين — استعمل المفردة أو `set +H` |
| نفاد الذاكرة في composer | `COMPOSER_MEMORY_LIMIT=-1 composer install` |
| `Database file ... does not exist` | `mkdir -p database && touch database/database.sqlite` — الملف غير متتبَّع في git عمداً |
| `Address already in use` | `pkill -f 'artisan serve'` قبل إعادة التشغيل |

**الدخول:** `admin` / `admin@laprovidence.ma` / `Providence2026`.
**تنبيه:** إذا فشل الدخول في المتصفّح ونجح عبر `curl`، فالسبب الملء التلقائي في Chrome — جرّب نافذة تصفّح خفي.

---

## 4. ما أُنجز حتى الآن

### المرحلة الأولى — التدقيق والتقسية (مدموجة في `main`)

مراجعة أمنية كشفت: CORS مفتوح `*`، `APP_DEBUG=true`، الرمز في `localStorage`، تحديد معدّل على الدخول فقط، تجاوز الصلاحيات بمقارنة اسم الدور نصّياً، هجرتان متطابقتان بايت ببايت، صفر اختبارات.

ما أُصلح: حذف الهجرة المكرّرة · إعادة كتابة `config/cors.php` و`.gitignore` · استخراج `ReceiptModal.tsx` · `CollectionService` + `CollectPaymentRequest` · وسيط `CheckPermission` + `config/permissions.php` · هجرات sessions و jobs · `phpunit.xml` + 19 اختباراً · طبقة `resources/js/api/http.ts` الموحّدة.

### المرحلة الثانية — التشغيل الفعلي والإصلاحات الساخنة

مسار الاختبارات في `phpunit.xml` · إعادة بناء جدول `payments` بطريقة تدعمها SQLite · إسقاط استعمال ثابت مهجور في PHP 8.4 · بذرة أنواع المعاليم · حساب الرسوم في صفحة الاستخلاص · إزالة `database/database.sqlite` من التتبّع.

**النتيجة: المنصّة تعمل فعلياً على جهاز المستخدم.** الدخول والاستخلاص وطباعة الوصل مجرَّبة على بيانات حقيقية.

### المرحلة الثالثة — المستويات والأقسام (`feat/classrooms`)

- `LevelController` + `SectionController` مع حمايات حذف (لا يُحذف مستوى أو قسم مرتبط بتسجيلات) ومنع تخفيض السعة تحت العدد المسجَّل.
- `RosterController` — `index` يُرجع قائمة قسم كاملة، `bulkStore` يُنشئ تلاميذ وتسجيلات دفعةً واحدة داخل معاملة، `destroy` يحذف تسجيلاً حذفاً ناعماً.
- `BulkEnrollRequest` — حد أقصى 200 اسم، طول الاسم 2–120.
- `ProvidenceStructureSeeder` — الهيكل الرسمي للمؤسسة: 9 مستويات و38 قسماً (idempotent).
- `AcademicYear2027Seeder` — السنة الدراسية `2026-2027`.
- هجرة `relax_student_required_fields` — تجعل `gender` اختيارياً وتضيف `mother_name`.
- الواجهة: `ClassroomsPage.tsx` · `RosterPage.tsx` · `api/classrooms.ts` · `api/roster.ts`.

> ⚠️ هذا الفرع **لم يُدمج بعد**.

### المرحلة الرابعة — النواة المالية

- **الدفتر النقدي المركزي:** `cash_transactions` + `LedgerService` بمفتاح فريد `(source_type, source_id, category)` يمنع الازدواج، وإلغاءٌ يسحب السطر بدل حذفه.
- **المصاريف:** `expense_categories` + `expenses` + إلغاء بسبب، وتقارير يومية وشهرية وسنوية.
- **الخزينة:** سحوبات بسبب وإلغاء · سجل الحركات · **دفتر اليوميات** بأربعة أنماط (يوم · شهر · أشهر مختارة · مدى حرّ) مع تراكمي وطباعة يوم واحد أو مدى كامل.
- **المداخيل:** حسب التاريخ · حسب التلميذ · حسب القسم · حسب السنة، مع صفحات تفصيلية لكل قسم ولكل تلميذ.
- **الدخل الصافي:** يومي وشهري وسنوي، بتخطيط عمودين (مداخيل يميناً ومصاريف يساراً) قابل للطباعة. السحب لا يُنقِص الدخل الصافي لأنه نقل أموال لا استهلاك.
- **الربط المالي لأنواع المعاليم:** `fee_types.ledger_category` + `student_fees.fee_type_id` + أمر `ledger:repair-fee-types` للتعبئة الرجعية — بعده صار كل استخلاص يصل الخزينة بالبند الصحيح.
- **الإطارات:** سلف وتسبقات (`employee_advances` بنوعين: `advance` تُخصم من الراتب، و`loan` تُردّ) + **ردّيات السلف** (`employee_advance_repayments`) نقداً أو خصماً من الراتب. الردّ النقدي وحده يُسقَط في الدفتر؛ الخصم من الراتب لا يُسجَّل مدخولاً نقدياً لأنه لا مال يدخل الصندوق.
- **الأجور:** دورة كاملة (أجر خام − تسبقة = صافي) مع إلغاء يعكس الدفتر.

---

## 5. خارطة وظائف المنتج

| المجال | الحالة |
| --- | --- |
| لوحة القيادة | ✅ |
| المستخدمون والأدوار والصلاحيات | ✅ |
| التلاميذ والتسجيل | ✅ |
| الاستخلاص وطباعة الوصل | ✅ |
| الإطارات | ✅ |
| الأجور والتسبقات والسلف وردّياتها | ✅ |
| المصاريف وأصنافها وتقاريرها | ✅ |
| الخزينة: السجل والسحوبات ودفتر اليوميات | ✅ |
| المداخيل (تاريخ · تلميذ · قسم · سنة) | ✅ |
| الدخل الصافي (يومي · شهري · سنوي) | ✅ |
| المستويات والأقسام وطباعة القوائم | 🔨 على `feat/classrooms` |
| التخفيضات | ⚠️ داخل الاستخلاص فقط، بلا سطر في الدفتر بعد |
| طريقة الدفع | ⚠️ مُثبَّتة في الكود |
| إعدادات المدرسة | ❌ |
| المتجر المدرسي | ❌ |
| سجل النشاط (audit log) | ❌ |
| التقويم والأحداث | ❌ (غير مطلوب حالياً) |

---

## 6. البنية الحقيقية للمدرسة

38 قسماً موزّعة على 9 مستويات، كما هي معتمدة إدارياً:

| المستوى | الرمز | الأقسام |
| --- | --- | --- |
| روضة | `PRE1` | أميمة · عفاف مح |
| تمهيدي | `PRE2` | عفاف بو · حنان · نجاح |
| تحضيري | `PRE3` | صفاء · أمل · عفاف |
| السنة الأولى … السادسة | `L1`…`L6` | أ · ب · ج · د · ه (لكل مستوى) |

**المغادرون ليسوا قسماً.** المغادرة عندنا حالة تسجيل: `enrollments.status = withdrawn`، فيبقى عدد الأقسام مطابقاً للواقع ويظلّ التلميذ مربوطاً بقسمه الأصلي في التقارير.

**تنبيه على تسميات السنوات:** أي بيانات تصل من خارج النظام قد تحمل تسمية سنة معكوسة (`2026/2025` والمقصود 2025‑2026). تحقّق من `start_date` و`end_date` ولا تعتمد على النصّ.

---

## 7. خطة نقل البيانات المُعتمدة

القرار: **لا استيراد للبيانات المالية القديمة.** ننطلق بسجلّ نظيف.

1. البداية من السنة الدراسية **2026‑2027** بصفحة بيضاء مالياً.
2. **النجاح آلي** في هذه المدرسة: كل تلميذ ينتقل إلى المستوى التالي **بنفس الحرف** — الأولى أ ← الثانية أ، الثانية ب ← الثالثة ب.
3. **السنة الأولى تبقى فارغة** لأن تقسيمها لم يحدث بعد؛ تحضيري هذه السنة ← السنة الأولى العام القادم بعد أن تقسمها المدرسة يدوياً.
4. **السادسة لا تُنقل** — تتخرّج.
5. التلاميذ يُدخلون **بالاسم واللقب فقط**؛ بقية البيانات تُستكمل لاحقاً.
6. **المتخلَّد** (ما لم يسدّده الأولياء) يُدخل يدوياً كأرصدة افتتاحية عند الانطلاق.
7. **سلف الإطارات وتسبقاتها** القائمة تُدخل يدوياً كأرصدة افتتاحية.
8. **سحوبات السنة الماضية لا تُدمج** — تخصّ سجلاً منتهياً.

### ما تبقّى لإكمال هذه الخطة

- **زرّ الترقية الآلية** — ينقل قسماً كاملاً إلى `order + 1` بنفس الحرف، مع استثناء السادسة. (لم يُكتب بعد.)
- **شاشة الأرصدة الافتتاحية** مع علامة `is_opening` تميّزها عن حركات السنة الجارية.
- **تقرير مطابقة** يُثبت أن مجموع الدفتر = مجموع العمليات قبل الانطلاق الرسمي.

---

## 8. مخطّط قاعدة البيانات — الجداول الأساسية

```
academic_years  id · name · start_date · end_date · is_active
levels          id · name · code(unique) · order · description
sections        id · level_id → levels · name · code(unique) · capacity
                unique[level_id, name]
students        id · student_code(unique) · first_name · last_name · dob
                gender(nullable) · guardian_first_name · guardian_last_name
                mother_name · guardian_phone · mother_phone · status
enrollments     id · student_id · academic_year_id · level_id · section_id
                enrollment_date · status(active|withdrawn|graduated|transferred)
                previous_enrollment_id · SoftDeletes
                unique[student_id, academic_year_id]
fee_types       id · name_ar · name_fr · price · ledger_category · is_active
student_fees    id · enrollment_id · fee_type_id · amount · status
payments        id · enrollment_id · amount · method · months · paid_at
                cancelled_at · cancelled_by · cancellation_reason
expenses        id · expense_category_id · amount · spent_at · إلغاء
treasury_withdrawals  id · amount · withdrawn_at · type · إلغاء
employees · salaries
employee_advances            id · employee_id · type(advance|loan) · amount
                             settled_amount · status(pending|partial|settled)
employee_advance_repayments  id · employee_advance_id · amount · repaid_at
                             method(cash|salary_deduction) · إلغاء
cash_transactions            الدفتر المركزي: direction(in|out) · category
                             source_type · source_id · amount · occurred_at
                             unique[source_type, source_id, category]
```

**قاعدة SQLite:** لا يمكن إسقاط عمود له مفتاح أجنبي مباشرةً؛ الحل إعادة بناء الجدول. هذا الخطأ كلّف 19 اختباراً فاشلاً سابقاً. وفي الهجرات الجديدة على SQLite نستعمل `unsignedBigInteger` + فهرس بدل المفاتيح الأجنبية.

---

## 9. الصلاحيات

الصلاحيات المزروعة عشر، كلّها مربوطة بدور `admin` عبر `syncWithoutDetaching`:

`manage_users` · `enroll_student` · `view_students` · `manage_students` · `manage_payments` · `manage_expenses` · `manage_treasury` · `manage_employees` · `manage_salaries` · `view_reports`

الأدوار الفائقة في `config('permissions.super_roles')` تتجاوز الفحص؛ لذلك **حراسة الواجهة لا تظهر إلا لغير المدير** — اختبرها بدور محدود لا بحساب المدير.

> **دَين تقني:** `enroll_student` و`view_students` مزروعتان لكن لا يحرسان أي مسار. إمّا تُستعملا أو تُحذفا بعد مراجعة.

---

## 10. المهامّ المفتوحة — مرتَّبة

### عاجل
1. تجربة `feat/classrooms` على جهاز المستخدم ثم دمجه في `main`.
2. **رقعة الدخول المعلَّقة** — `AuthController::login` يقبل البريد فقط؛ الإدارة اعتادت اسم المستخدم. الرقعة جاهزة ولم تُطبَّق:
   ```php
   'email' => 'required|string',
   $login = trim((string) $request->input('email'));
   ->where(fn($q) => $q->where('email', $login)->orWhere('username', $login))
   ```
   ثم تعديل التسمية في `Login.tsx` إلى «اسم المستخدم أو البريد».
3. **`SalaryController`:** لا يقبل حالياً إلا `type = advance` عند الخصم، فلا يمكن خصم قسط من سلفة (`loan`) في شاشة الأجور. و`cancel()` يصفّر `settled_amount` دون النظر إلى الردّيات المسجَّلة — يجب أن يعكس الردّية المرتبطة بالأجر لا أن يمحو الرصيد.
4. **`academic_year_id` فارغ** في بعض حركات السلف والردّيات، فتسقط من التقارير المفلترة بالسنة. يحتاج تعبئة رجعية.
5. تنظيف بيانات التجربة قبل الانطلاق (سلف وردّيات ومصاريف وأجور اختبارية).

### متوسط
6. سطر دفتر للتخفيضات حتى تُقابل التقارير الورقية سطراً بسطر.
7. CRUD لأصناف المصاريف من الواجهة.
8. سجل نشاط غير قابل للتعديل (`audit_logs`).
9. ترقيم وبحث في القوائم الطويلة.
10. استبدال `window.prompt` في الإلغاء بنافذة سبب مُنظَّمة.
11. ملفات ترجمة `lang/ar` لرسائل التحقّق (بعضها يظهر بالإنجليزية).
12. سياسة دقّة المبالغ: القاعدة بخانتين عشريتين والطباعة بثلاث (المليم) — تُوحَّد.

### دَين تقني
13. تقسيم `CollectionPage.tsx` (كبير جداً).
14. إزالة استعمالات `: any` المتبقّية.
15. تحويل بقية الإجراءات من `Request $request` إلى FormRequests.
16. Node.js في `Dockerfile` + سلسلة CI (لا يوجد `.github/workflows` بعد).
17. توسيع تحديد المعدّل ليشمل أكثر من الدخول.
18. اختبارات للأدوار والتزامن، واختبارات لردّيات السلف.

---

## 11. أخطاء وقعت سابقاً — لا تكرّرها

| الخطأ | الدرس |
| --- | --- |
| إسقاط عمود بمفتاح أجنبي في SQLite | أعد بناء الجدول؛ لا تفترض أن Laravel يتكفّل |
| `git checkout -B main origin/main` أتلف قاعدة البيانات | افحص `git status` وخُذ نسخة احتياطية أولاً |
| رفع ناقص للملفات | تحقّق أن **كل** ملف مستورَد في `App.tsx` موجود فعلاً على الفرع |
| عدّ ناقص لعناصر قائمة مرجعية | اعدد العناصر وقارنها بما يقوله المستخدم |
| `curl -s` أخفى خطأ اتصال | لا تستعمل `-s` عند التشخيص |
| الادّعاء بأن البناء سليم دون تشغيله | المستخدم هو المُتحقّق النهائي |
| `String(value)` في بناء الروابط | أرسل `1`/`0` للقيم المنطقية؛ قاعدة `boolean` في Laravel ترفض `"true"` |
| استعمال أيقونة غير موجودة في `lucide-react` | تحقّق من وجود الأيقونة قبل استيرادها وإلا فشل البناء |
| كتابة حرف عربي خطأ داخل بيانات (اسم صنف مثلاً) | البيانات تُنسخ حرفاً بحرف ثم يُعاد قراءة الملف للتأكّد |

---

## 12. أول ما تفعله عند استلام المشروع

```bash
git clone https://github.com/trxtrxklaud/Complexe-la-providence-ERP.git
cd Complexe-la-providence-ERP
composer install && npm install
cp .env.example .env && php artisan key:generate
mkdir -p database && touch database/database.sqlite
php artisan migrate --seed
php artisan test
npm run build
php artisan serve
```

ثم اقرأ بهذا الترتيب: `routes/api.php` → `app/Services/LedgerService.php` → `app/Models/CashTransaction.php` → `app/Http/Controllers/` → `resources/js/App.tsx` → `resources/js/api/http.ts` → `FIXES.md`.

**اسأل المستخدم دائماً قبل الافتراض.** يعرف مدرسته أكثر من أي وثيقة، وقد صحّح افتراضات خاطئة أكثر من مرّة.
