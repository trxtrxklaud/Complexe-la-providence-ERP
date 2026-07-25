# HANDOFF — ملف تسليم المشروع

> **لأي نموذج ذكاء اصطناعي يقرأ هذا:** هذا الملف يشرح المشروع، وما أُنجز، وما تبقّى، ومنهجية العمل المتّفق عليها. اقرأه كاملاً قبل كتابة أي سطر كود. تاريخ آخر تحديث: 2026-07-25.

---

## 1. من نحن وما المشروع

**Complexe la Providence** — مدرسة خاصة في تونس (ابتدائي + رياض أطفال). المالك يبني نظام ERP جديد ليحلّ محلّ منصّة قديمة اشتراها من مزوّد خارجي (`smartbridge.tn`) ولا يملك منها أي كود مصدري ولا قاعدة بيانات ولا وثائق.

**المستودع:** `trxtrxklaud/Complexe-la-providence-ERP` — الفرع الافتراضي `main`.

### المكدّس التقني

| الطبقة | التقنية |
| --- | --- |
| Backend | Laravel 11.55 · PHP 8.4 · Sanctum |
| Frontend | React + TypeScript + Vite 6 + Tailwind + lucide-react |
| قاعدة البيانات | SQLite (تطوير) — MySQL لاحقاً في الإنتاج |
| الاختبارات | PHPUnit 10.5 — 19 اختباراً تمرّ |
| جهاز المستخدم | **Termux على هاتف Android** + حاسوب حديثاً |

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
4. **الاستنساخ قبل التحسين.** ننقل وظائف المنصّة القديمة كما هي أولاً، ثم نحسّنها لاحقاً.
5. **احتياطي قاعدة البيانات قبل أي هجرة:**
   ```
   cp database/database.sqlite database/backup-$(date +%m%d-%H%M).sqlite
   ```
6. **ممنوع** `git reset --hard` أو `git checkout -- .` دون فحص `git status` — أتلف ذلك قاعدة بيانات المستخدم مرّة سابقاً.

---

## 3. أوامر التشغيل على جهاز المستخدم (Termux)

```bash
cd ~/projects/providence

# سحب التحديثات
git pull origin main

# قاعدة البيانات
php artisan migrate
php artisan db:seed --class=اسم_البذرة

# الاختبار والبناء
php artisan test
npm run build

# التشغيل
pkill -f 'artisan serve'
php artisan serve --host=0.0.0.0 --port=8000 > storage/logs/serve.log 2>&1 &
```

### مزالق Termux المعروفة

| المشكلة | الحل |
| --- | --- |
| `E: Unable to locate package php-pdo` | Termux يحزم كل الإضافات داخل `php` — لا تُثبّت إضافات منفصلة |
| `vendor/bin/phpunit: Permission denied` | `termux-fix-shebang vendor/bin/*` ثم `chmod +x vendor/bin/*` |
| `bash: !': event not found` | علامة `!` داخل علامتَي اقتباس مزدوجتين — استعمل المفردة أو `set +H` |
| نفاد الذاكرة في composer | `COMPOSER_MEMORY_LIMIT=-1 composer install` |
| `Database file ... does not exist` | `mkdir -p database && touch database/database.sqlite` — الملف غير متتبَّع في git عمداً |

**الدخول:** `admin` / `admin@laprovidence.ma` / `Providence2026`.
**تنبيه:** إذا فشل الدخول في المتصفّح ونجح عبر `curl`، فالسبب الملء التلقائي في Chrome — جرّب نافذة تصفّح خفي.

---

## 4. ما أُنجز حتى الآن

### المرحلة الأولى — التدقيق والتقسية (مدموجة في `main`)

مراجعة أمنية كشفت: CORS مفتوح `*`، `APP_DEBUG=true`، الرمز في `localStorage`، تحديد معدّل على الدخول فقط، تجاوز الصلاحيات بمقارنة اسم الدور نصّياً، هجرتان متطابقتان بايت ببايت، صفر اختبارات.

ما أُصلح: حذف الهجرة المكرّرة · إعادة كتابة `config/cors.php` و`.gitignore` · استخراج `ReceiptModal.tsx` · `CollectionService` + `CollectPaymentRequest` · وسيط `CheckPermission` + `config/permissions.php` · هجرات sessions و jobs · `phpunit.xml` + 19 اختباراً · طبقة `resources/js/api/http.ts` الموحّدة.

دُمج عبر PR #1. تقييم المشروع قبل العمل: **6.5/10** (المعمارية 8.5 · فهم المجال 9 · الخلفية 7.5 · الواجهة 5.5 · الأمن 5 · الاختبارات 0 · DevOps 6.5).

### المرحلة الثانية — التشغيل الفعلي والإصلاحات الساخنة

أُصلحت مباشرةً على `main`: مسار الاختبارات في `phpunit.xml` · إعادة بناء جدول `payments` بطريقة تدعمها SQLite · إسقاط استعمال ثابت مهجور في PHP 8.4 · بذرة أنواع المعاليم + `القسط الشهري` · حساب الرسوم في صفحة الاستخلاص · إزالة `database/database.sqlite` من التتبّع.

**النتيجة: المنصّة تعمل فعلياً على جهاز المستخدم.** الدخول يعمل، الاستخلاص يعمل، **طباعة الوصل تعمل** (جُرّبت: الدفعة 1، عمر خطّاب `PRV-2026-0102`، 230.00 د.ت).

### المرحلة الثالثة — الفرع الحالي `feat/classrooms`

ثلاثة تسجيلات: البنية والصلاحيات → البذور → القوائم والواجهة.

**الخلفية:**
- `LevelController` + `SectionController` مع حمايات حذف (لا يُحذف مستوى أو قسم مرتبط بتسجيلات) ومنع تخفيض السعة تحت العدد المسجَّل.
- `RosterController` — `index` يُرجع قائمة قسم كاملة، `bulkStore` يُنشئ تلاميذ وتسجيلات دفعةً واحدة داخل معاملة، `destroy` يحذف تسجيلاً حذفاً ناعماً.
- `BulkEnrollRequest` — حد أقصى 200 اسم، طول الاسم 2–120.
- `ProvidenceStructureSeeder` — 9 مستويات و38 قسماً مأخوذة من المنصّة القديمة (idempotent).
- `AcademicYear2027Seeder` — السنة الدراسية `2026-2027`.
- هجرة `relax_student_required_fields` — تجعل `gender` اختيارياً وتضيف `mother_name`.

**الواجهة:** `ClassroomsPage.tsx` (إدارة المستويات والأقسام) · `RosterPage.tsx` (قوائم الأقسام + الطباعة) · `api/classrooms.ts` · `api/roster.ts`.

> ⚠️ هذا الفرع **لم يُدمج بعد** — بانتظار تجربة المستخدم.

---

## 5. منهجية الاستنساخ من المنصّة القديمة عبر صفحات HTML

هذا هو **قلب المشروع** — اقرأه بعناية.

### المشكلة

المنصّة القديمة مُقفلة: لا كود، لا قاعدة بيانات، لا API. تحليل ملف HAR أثبت أنها **PHP مُصيَّرة من الخادم** مع jQuery 3.2.1 و Bootstrap — **صفر طلبات XHR، صفر JSON**. كل صفحة HTML كاملة تصل جاهزة من الخادم.

### الحل المُعتمد والمُثبَت

كل HTML المطلوب موجود داخل الصفحة نفسها. لذلك:

```
1. المستخدم يفتح الصفحة في المنصّة القديمة وهو مسجَّل الدخول
2. Ctrl+S  →  حفظ باسم  →  "صفحة ويب، HTML فقط"
3. يرفع الملف في المحادثة
4. النموذج يحلّله ويستخرج البنية والحقول والأزرار
5. يبني نظيرها في المنصّة الجديدة
6. المستخدم يجرّبها على جهازه
7. تسجيل في git ثم الانتقال للجزء التالي
```

### كيف يُحلَّل ملف HTML

استعمل Python مع BeautifulSoup داخل بيئة العمل:

```python
from bs4 import BeautifulSoup
html = open('/data/old/page.html', encoding='utf-8').read()
soup = BeautifulSoup(html, 'lxml')

# الحقول: أسماء الحقول = أسماء الأعمدة عندهم
for inp in soup.select('input, select, textarea'):
    print(inp.get('name'), inp.get('type'), inp.has_attr('required'))

# القوائم المنسدلة: تكشف البنية الكاملة (المستويات، السنوات، الأدوار)
for opt in soup.select("select[name='level'] option"):
    print(opt.get('value'), opt.get_text(strip=True))

# الجداول: تكشف الأعمدة المعروضة
print([th.get_text(strip=True) for th in soup.select('table thead th')])

# المسارات: تكشف خريطة الوظائف
print({a.get('href') for a in soup.select('a[href]')})
```

**ما يُستخرج من كل صفحة:**

| المُستخرَج | ما يعنيه |
| --- | --- |
| `name` في الحقول | أسماء الأعمدة في قاعدة بياناتهم |
| `required` | القيود الإجبارية |
| `<option>` | البيانات المرجعية الكاملة (الأقسام، السنوات، الأدوار) |
| رؤوس الجداول | الأعمدة التي يهتمّ بها المستخدم فعلاً |
| روابط الشريط الجانبي | خريطة كل وظائف النظام |
| نصوص الأزرار | المصطلحات التي اعتادها موظّفو المدرسة |

### احتياطات

- **الخصوصية:** الصفحات المحفوظة تحوي أسماء أطفال وأرقام هواتف أوليائهم. تُستعمل للتحليل البنيوي فقط ولا تُسجَّل في git.
- **لا تستعمل كلمات سرّ المستخدم لتسجيل دخول آلي.** المستخدم يحفظ الصفحات بنفسه.
- **تحقّق من اكتمال الالتقاط.** حدث خطأ سابق: قُرئت 14 خياراً فقط من قائمة تحوي 41 — والمستخدم صحّح ذلك. اعدد العناصر دائماً وقارن بما يقوله المستخدم.

---

## 6. خريطة الوظائف — القديم مقابل الجديد

| المجال | مسار المنصّة القديمة | حالتنا |
| --- | --- | --- |
| لوحة القيادة | `/dashboard` | ✅ |
| المستخدمون | `/user`, `/user/create`, `/user/edit/{id}` | ✅ |
| التلاميذ | `/student` | ✅ |
| الاستخلاص | `/student/billing` | ✅ أفضل منهم |
| الإطارات | `/employee` | ✅ |
| المستويات والأقسام | `/classroom` | 🔨 على `feat/classrooms` |
| طباعة الأقسام | `/classroom/classroom/list`, `/print` | 🔨 على `feat/classrooms` |
| التخفيضات | `/student/discounts` | ⚠️ داخل الاستخلاص فقط |
| التقويم والأحداث | `/event` | ⚠️ جدول فقط |
| طريقة الدفع | `/school/payment-type` | ⚠️ مُثبَّتة في الكود |
| إعدادات المدرسة | `/school` | ❌ |
| المصاريف | `/expense`, `/expense/type` | ❌ |
| المداخيل (4 تقارير) | `/student/income-by-date`, `/revenue`, `/revenue-classroom`, `/revenue-year` | ❌ |
| الخزينة | `/treasury/history`, `/treasury/withdrawals` | ❌ |
| صافي المداخيل (3) | `/financialreports/net-income/daily`, `/net-revenue-by-month`, `/by-year` | ❌ |
| تقارير المصاريف (3) | `/expense/report/by-date`, `/by-month`, `/by-year` | ❌ |
| المتجر المدرسي | `/school/product` | ❌ |
| السجلات | `/activity` | ❌ |

**الترتيب المتّفق عليه للأجزاء المتبقّية:**
إعدادات المدرسة + طريقة الدفع → المصاريف + إعداداتها → الخزينة والسحب → المداخيل → صافي المداخيل → تقارير المصاريف → التخفيضات → التقويم → المتجر → السجلات.

---

## 7. البنية الحقيقية للمدرسة

مُستخرَجة من القائمة المنسدلة في صفحة طباعة الأقسام — **41 خياراً**: 38 قسماً حقيقياً + 3 أقسام وهمية للمغادرين.

| المستوى | الرمز | الأقسام |
| --- | --- | --- |
| روضة | `PRE1` | أميمة · عفاف مح |
| تمهيدي | `PRE2` | عفاف بو · حنان · نجاح |
| تحضيري | `PRE3` | صفاء · أمل · عفاف |
| السنة الأولى … السادسة | `L1`…`L6` | أ · ب · ج · د · ه (لكل مستوى) |

**«مغادرون 22/23» و«مغادرون 24/25» و«مغادرون 2025/2026» ليست أقساماً** — إنها حيلة عندهم لأرشفة التلاميذ المنسحبين. عندنا تُمثَّل بـ `enrollments.status = withdrawn`.

**تحذير في بياناتهم:** تسميات السنوات معكوسة — `2026/2025` هي في الحقيقة 2025‑2026. لا تنسخ التسمية حرفياً.

---

## 8. خطة نقل البيانات المُعتمدة

المستخدم **رفض** استيراد البيانات القديمة كاملةً. القرار:

1. نبدأ من السنة الدراسية الجديدة **2026‑2027** بصفحة بيضاء.
2. **النجاح آلي** في هذه المدرسة: كل تلميذ ينتقل إلى المستوى التالي **بنفس الحرف** — الأولى أ ← الثانية أ، الثانية ب ← الثالثة ب.
3. **السنة الأولى تبقى فارغة** لأن تقسيمها لم يحدث بعد.
4. **السادسة لا تُنقل** — تتخرّج.
5. التلاميذ يُدخلون **بالاسم واللقب فقط**؛ بقية البيانات (الهاتف، الوليّ، تاريخ الميلاد) تُستكمل لاحقاً.
6. تحضيري هذه السنة ← السنة الأولى العام القادم، بعد أن تقسمها المدرسة يدوياً.

### ما تبقّى لإكمال هذه الخطة

- **مستورد HTML** — يبتلع صفحات `/classroom/classroom/list` المحفوظة ويستخرج الأسماء تلقائياً بدل اللصق اليدوي. (لم يُكتب بعد.)
- **زرّ الترقية الآلية** — ينقل قسماً كاملاً إلى `order + 1` بنفس الحرف، مع استثناء السادسة. (لم يُكتب بعد.)

---

## 9. مخطّط قاعدة البيانات — الجداول الأساسية

```
academic_years  id · name · start_date · end_date · is_active
levels          id · name · code(unique) · order · description
sections        id · level_id → levels · name · code(unique) · capacity
                unique[level_id, name]
students        id · student_code(unique) · first_name · last_name · dob
                gender(nullable) · guardian_first_name · guardian_last_name
                mother_name · guardian_phone · mother_phone · address · status
enrollments     id · student_id · academic_year_id · level_id · section_id
                enrollment_date · status(active|withdrawn|graduated|transferred)
                previous_enrollment_id · SoftDeletes
                unique[student_id, academic_year_id]
fee_types · student_fees · payments · users · roles · employees · salaries
```

**تنبيه معلَّق:** `mother_name` أُضيف في الهجرة لكنّه **لم يُضَف بعد إلى `$fillable` في `app/Models/Student.php`**. أصلح هذا قبل أي كتابة تعتمد عليه.

**قاعدة SQLite:** لا يمكن إسقاط عمود له مفتاح أجنبي مباشرةً. الحل هو إعادة بناء الجدول. هذا الخطأ كلّف 19 اختباراً فاشلاً سابقاً.

---

## 10. الصلاحيات

الصلاحيات المزروعة: `manage_users` · `enroll_student` · `view_students` · `manage_payments` · `view_reports` — كلّها لدور `admin`.

الأدوار الفائقة في `config('permissions.super_roles')` تتجاوز الفحص. الوسيط `permission:اسم_الصلاحية` يُطبَّق على مجموعات المسارات.

> **دَين تقني:** بعض المسارات تستعمل `permission:manage_students` وهي صلاحية **غير مزروعة** — تعمل حالياً فقط لأن المدير يتجاوز الفحص. تحتاج توحيداً.

---

## 11. المهامّ المفتوحة — مرتَّبة

### عاجل
1. تجربة `feat/classrooms` على جهاز المستخدم ثم دمجه في `main`.
2. **رقعة الدخول المعلَّقة** — `AuthController::login` يقبل البريد فقط؛ منصّتهم القديمة تقبل اسم المستخدم. الرقعة جاهزة ولم تُطبَّق:
   ```php
   'email' => 'required|string',
   $login = trim((string) $request->input('email'));
   ->where(fn($q) => $q->where('email', $login)->orWhere('username', $login))
   ```
   ثم تعديل التسمية في `Login.tsx` إلى «اسم المستخدم أو البريد».
3. مستورد HTML للقوائم + زرّ الترقية الآلية.

### متوسط
4. مواصلة استنساخ الأجزاء حسب الترتيب في القسم 6.
5. جعل `email` اختيارياً في `users` (منصّتهم لا توجبه).
6. ترقيم وبحث في قائمة المستخدمين (محدودة الآن بـ 20 دون بحث).
7. `mother_name` إلى `$fillable`.

### تجميلي
8. الوصل يطبع `الطريقة: cash` بدل «نقداً» — استعمل `METHOD_LABELS`.
9. التخفيض يُعرَض `60.00-` — يحتاج `direction: 'ltr'` على الخلية.
10. إضافة هاتف المدرسة إلى الوصل.

### دَين تقني
11. تقسيم `CollectionPage.tsx` (كبير جداً).
12. إزالة 36 استعمالاً لـ `: any`.
13. تحويل 24 إجراءً من `Request $request` إلى FormRequests.
14. Node.js في `Dockerfile`.
15. توسيع تحديد المعدّل ليشمل أكثر من الدخول.

---

## 12. أخطاء وقعت سابقاً — لا تكرّرها

| الخطأ | الدرس |
| --- | --- |
| إسقاط عمود بمفتاح أجنبي في SQLite | أعد بناء الجدول؛ لا تفترض أن Laravel يتكفّل |
| `git checkout -B main origin/main` أتلف قاعدة البيانات | افحص `git status` وخُذ نسخة احتياطية أولاً |
| رفع ناقص للملفات (`push_files`) | تحقّق أن **كل** ملف مستورَد في `App.tsx` موجود فعلاً على الفرع |
| قراءة 14 خياراً من أصل 41 | اعدد العناصر وقارنها بما يقوله المستخدم |
| `curl -s` أخفى خطأ اتصال | لا تستعمل `-s` عند التشخيص |
| الادّعاء بأن البناء سليم | `esbuild --external:*` لا يكشف كل الأعطال — المستخدم هو المُتحقّق |

---

## 13. أول ما تفعله عند استلام المشروع

```bash
git clone https://github.com/trxtrxklaud/Complexe-la-providence-ERP.git
cd Complexe-la-providence-ERP
composer install && npm install
cp .env.example .env && php artisan key:generate
mkdir -p database && touch database/database.sqlite
php artisan migrate:fresh --seed
php artisan test        # يجب أن تمرّ 19 اختباراً
npm run build
php artisan serve
```

ثم اقرأ بهذا الترتيب: `routes/api.php` → `app/Http/Controllers/` → `resources/js/App.tsx` → `resources/js/api/http.ts` → `FIXES.md`.

**اسأل المستخدم دائماً قبل الافتراض.** يعرف مدرسته أكثر من أي وثيقة، وقد صحّح افتراضات خاطئة أكثر من مرّة.
