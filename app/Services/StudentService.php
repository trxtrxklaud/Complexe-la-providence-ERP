<?php
namespace App\Services;

use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class StudentService
{
    private const MALE_NAMES = [
        'محمد', 'أحمد', 'علي', 'عمر', 'يوسف', 'خالد', 'أنور', 'حسين', 'حسن', 'رضا',
        'صلاح', 'فتحي', 'لطفي', 'منصف', 'مراد', 'منذر', 'نزار', 'نبيل', 'هيثم', 'وليد',
        'زياد', 'أمين', 'آدم', 'إبراهيم', 'إسماعيل', 'سليم', 'سامي', 'سمير', 'حاتم', 'حمزة',
        'أسامة', 'بلال', 'صالح', 'طارق', 'عادل', 'فوزي', 'قيس', 'كمال', 'لؤي', 'مهدي',
        'منير', 'ياسين', 'سفيان', 'أيوب', 'أشرف', 'أنيس', 'إلياس', 'بدر', 'بشير', 'توفيق',
        'جمال', 'جهاد', 'حبيب', 'حمدي', 'راشد', 'رامي', 'رياض', 'زكرياء', 'زكريا', 'سعد',
        'سعيد', 'سلامة', 'سليمان', 'شكري', 'طه', 'طاهر', 'عثمان', 'عدنان', 'عزيز', 'عصام',
        'عماد', 'عمار', 'فاضل', 'فرحان', 'فريد', 'قاسم', 'كريم', 'ماهر', 'مجدي', 'محمود',
        'مسعود', 'معز', 'معمر', 'منتصر', 'ناجي', 'نصر', 'نور الدين', 'هشام', 'هلال', 'يعقوب',
        'عبد الله', 'عبدالرحمن', 'عبد العزيز', 'عبد القادر', 'عبد الحميد', 'عبد الرزاق',
        'عبد الكريم', 'عبد السلام', 'عبد', 'عمران', 'سلطان', 'رمضان', 'إحسان', 'فرحان',
        'سهيل', 'فهد', 'سيف', 'مصعب', 'أيمن', 'بسام', 'تامر', 'ثامر', 'جابر', 'جلال',
        'حافظ', 'حسام', 'خليل', 'زياد', 'زيد', 'ساهر', 'سرحان', 'سعود', 'شهاب', 'صابر',
        'عامر', 'عباس', 'عبد الرؤوف', 'عبد الجبار', 'عبد الغفار', 'عبد الغني', 'عبد الفتاح',
        'عبد اللطيف', 'عبد المجيد', 'عبد المنعم', 'عبد الواحد', 'عبد الوهاب', 'عكرمة',
        'علاء', 'غانم', 'غيث', 'فؤاد', 'فيصل', 'كاتب', 'ماجد', 'مالك', 'مبشر', 'متولي',
        'مجاهد', 'محسن', 'مختار', 'مروان', 'معاوية', 'مقداد', 'مكرم', 'منيب', 'منصور',
        'منعم', 'نادر', 'ناصر', 'نضال', 'هاني', 'هواري', 'وليد', 'يحيى', 'عيسى', 'موسى',
        'مصطفى', 'مرتضى', 'طلحة', 'حذيفة', 'أوس', 'بكر', 'تميم', 'خبيب', 'ذياب', 'رافع',
        'سالم', 'سنان', 'شماخ', 'عقبة', 'غالب', 'قتيبة', 'كليب', 'لبيد', 'مازن', 'نوفل',
        'واثق', 'همام', 'أمجد', 'باهر', 'جليل', 'حارث', 'خميس', 'رجب', 'زبير', 'سعد الدين',
        'شمس الدين', 'بدر الدين', 'تاج الدين', 'زين الدين', 'جلال الدين', 'عماد الدين',
        'شريف', 'صديق', 'ضياء', 'عاطف', 'عقيل', 'فتحي', 'فخري', 'قائد', 'كاظم', 'منذر',
    ];

    private const FEMALE_YA_NAMES = [
        'سلمى', 'هدى', 'منى', 'رؤى', 'ليلى', 'سلوى', 'نجوى', 'لمى', 'سهى', 'جنى',
        'تقى', 'ضحى', 'رضوى', 'شذى', 'صفوى', 'لما', 'رنا', 'ثريا', 'سناء', 'حسنة',
    ];

    private const FEMALE_NAMES = [
        'مريم', 'سارة', 'نور', 'ريم', 'آية', 'ليلى', 'هند', 'زينب', 'سعاد', 'هاجر',
        'أمل', 'حنان', 'أماني', 'إيمان', 'نسرين', 'صباح', 'عبير', 'غادة', 'مروة', 'ياسمين',
        'فاطمة', 'خديجة', 'عائشة', 'أمينة', 'سلمى', 'هدى', 'منى', 'رؤى', 'سلوى', 'أسماء',
        'شيماء', 'حوراء', 'زهراء', 'سناء', 'لينا', 'ليان', 'تالين', 'سيرين', 'سيلين', 'رهف',
        'رغد', 'رند', 'جود', 'جنى', 'ملك', 'ملاك', 'براءة', 'تقى', 'تقوى', 'ضحى',
        'سجى', 'رجاء', 'نجاة', 'نجوى', 'منال', 'نادية', 'سامية', 'حياة', 'رقية', 'سكينة',
        'سمية', 'شادية', 'عزة', 'غالية', 'كوثر', 'ميساء', 'ريماس', 'ريتاج', 'شهد', 'دلال',
        'دعاء', 'دينا', 'رنا', 'سحر', 'سماح', 'شهرزاد', 'صفاء', 'عالية', 'غزل', 'فدوى',
        'لبنى', 'لمى', 'ماجدة', 'محجوبة', 'مسعودة', 'مليكة', 'منيرة', 'نائلة', 'نرجس',
        'نعيمة', 'وصال', 'وردة', 'وعد', 'هالة', 'هبة', 'هدية', 'هناء', 'وفاء', 'يسرا',
        'أروى', 'أزهار', 'أميرة', 'إسراء', 'إلهام', 'إيناس', 'آسيا', 'أنفال', 'آلاء',
        'إبتهال', 'أثير', 'أحلام', 'أخوات', 'إسراء', 'أسيل', 'أصالة', 'أضواء', 'آلاء',
        'ابتسام', 'إجلال', 'إحسان', 'أحلام', 'إخلاص', 'أرزاق', 'أزهار', 'إسراء', 'أشواق',
        'إعتدال', 'أفراح', 'أفنان', 'إقبال', 'أماني', 'إمتثال', 'أنامل', 'أنعام', 'أنوار',
        'أوس', 'إيمان', 'باسمة', 'بتول', 'بديعة', 'بركة', 'بشيرة', 'بشري', 'بلقيس', 'بنان',
        'بهية', 'بينة', 'تسبيح', 'تحية', 'ترف', 'تسنيم', 'تغريد', 'تيسير', 'ثريا', 'جارية',
        'جامعة', 'جميلة', 'جنان', 'جهان', 'جواهر', 'حبيبة', 'حجة', 'حديجة', 'حسنة', 'حسينة',
        'حفصة', 'حكيمة', 'حليمة', 'حميدة', 'خديجة', 'خلود', 'خولة', 'دالية', 'درة', 'دعاء',
        'ذكريات', 'راضية', 'رشيدة', 'رقية', 'ريم', 'زاهية', 'زكية', 'زهرة', 'زينب', 'ساجدة',
        'ساهرة', 'سبيحة', 'سعاد', 'سعيدة', 'سفانة', 'سكينة', 'سلام', 'سلاف', 'سلمى', 'سماح',
        'سميرة', 'سمية', 'سهى', 'سودة', 'سوسن', 'سيرين', 'شادية', 'شاذلية', 'شريفة', 'شمس',
        'شهيرة', 'شيخة', 'صابرين', 'صافية', 'صبرية', 'صفية', 'صليحة', 'ضاوية', 'ضحى', 'ضيفة',
        'طاهرة', 'عائشة', 'عبلة', 'عتيقة', 'عجيبة', 'عزيزة', 'عفاف', 'عفراء', 'علياء', 'عمارة',
        'غالية', 'غزالة', 'غفران', 'فائزة', 'فادية', 'فاطمة', 'فاكهة', 'فرحة', 'فضة', 'فوزية',
        'فيحاء', 'قمر', 'كاملة', 'كبيرة', 'كريمة', 'كلثوم', 'كوثر', 'لطيفة', 'لمياء', 'لميس',
        'ليان', 'ليلى', 'ماجدة', 'مارية', 'مايا', 'مباركة', 'مبروكة', 'مبسمة', 'مثنى', 'محرزية',
        'محمدية', 'مختارة', 'مريم', 'مسعودة', 'معالي', 'منال', 'منى', 'منيرة', 'مهدية', 'مونية',
        'مي', 'ميساء', 'نائلة', 'نادية', 'نازلي', 'ناهد', 'نجاح', 'نجاة', 'نجلاء', 'نجية',
        'ندى', 'نزيهة', 'نسرين', 'نشوة', 'نصيرة', 'نضيرة', 'نعمة', 'نعيمة', 'نفيسة', 'نور',
        'نورية', 'نور الهدى', 'هاجر', 'هالة', 'هدى', 'هدية', 'هناء', 'هند', 'هنيدة', 'هنية',
        'هيفاء', 'وافية', 'وردة', 'وسيلة', 'وفاء', 'وضاح', 'وضحة', 'وضيفة', 'وليدة', 'ياسمين',
        'يسرا', 'يمنى', 'يسرى', 'يسن', 'ياسمينا', 'نورا', 'ناريمان', 'نبراس', 'نبيلة', 'نجوى',
    ];

    public function __construct(private PaymentService $paymentService) {}

    public function getStudentsWithCurrentEnrollment(array $filters = [])
    {
        $filterEnrollments = function ($enrollmentQuery) use ($filters) {
            if (! empty($filters['level_id'])) {
                $enrollmentQuery->where('level_id', $filters['level_id']);
            }

            if (! empty($filters['section_id'])) {
                $enrollmentQuery->where('section_id', $filters['section_id']);
            }

            if (! empty($filters['academic_year_id'])) {
                $enrollmentQuery->where('academic_year_id', $filters['academic_year_id']);
            } else {
                $enrollmentQuery->where('status', 'active');
            }

            return $enrollmentQuery;
        };

        $query = Student::with([
            'enrollments' => fn ($enrollmentQuery) =>
                $filterEnrollments($enrollmentQuery)->with(['level', 'section', 'academicYear']),
            'guardians'   => fn($q) => $q->wherePivot('is_primary_contact', true),
        ]);

        if (! empty($filters['search'])) {
            $terms = preg_split('/\s+/u', trim($filters['search']), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($terms as $term) {
                $query->where(fn (Builder $q) =>
                    $q->where('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('student_code', 'like', "%{$term}%")
                );
            }
        }

        if (! empty($filters['phone'])) {
            $phone = $filters['phone'];
            $query->where(fn (Builder $q) =>
                $q->where('guardian_phone', 'like', "%{$phone}%")
                    ->orWhere('mother_phone', 'like', "%{$phone}%")
                    ->orWhereHas('guardians', fn (Builder $guardianQuery) =>
                        $guardianQuery->where('phone', 'like', "%{$phone}%")
                            ->orWhere('mother_phone', 'like', "%{$phone}%")
                    )
            );
        }

        if (! empty($filters['dob'])) {
            $query->whereDate('dob', $filters['dob']);
        }

        if (! empty($filters['student_code'])) {
            $query->where('student_code', 'like', '%'.$filters['student_code'].'%');
        }

        $hasEnrollmentFilter = ! empty($filters['level_id'])
            || ! empty($filters['section_id'])
            || ! empty($filters['academic_year_id']);

        if ($hasEnrollmentFilter) {
            $query->whereHas('enrollments', $filterEnrollments);
        }

        $allStudents = $query->get()
            ->unique(fn (Student $student) => $this->normalizeStudentName($student))
            ->map(function (Student $student) {
                $rawGender = strtolower(trim((string) $student->gender));
                if (in_array($rawGender, ['male', 'm', 'ذكر'], true)) {
                    $resolvedGender = 'male';
                } elseif (in_array($rawGender, ['female', 'f', 'أنثى'], true)) {
                    $resolvedGender = 'female';
                } else {
                    $resolvedGender = $this->inferGenderFromName($student->first_name);
                }
                $student->gender = $resolvedGender;
                return $student;
            });

        $totalCount = $allStudents->count();
        $maleCount = $allStudents->where('gender', 'male')->count();
        $femaleCount = $allStudents->where('gender', 'female')->count();
        $unknownCount = $allStudents->where('gender', 'unknown')->count();

        $selectedGender = strtolower(trim((string) ($filters['gender'] ?? 'all')));

        $filteredStudents = $allStudents->filter(function (Student $student) use ($selectedGender) {
            if ($selectedGender === 'male' || $selectedGender === 'ذكر') {
                return $student->gender === 'male';
            }
            if ($selectedGender === 'female' || $selectedGender === 'أنثى') {
                return $student->gender === 'female';
            }
            if ($selectedGender === 'unknown' || $selectedGender === 'غير محدد') {
                return $student->gender === 'unknown';
            }
            return true;
        });

        $genderWeights = ['male' => 1, 'female' => 2, 'unknown' => 3];

        $sortedStudents = $filteredStudents->sort(function (Student $a, Student $b) use ($genderWeights) {
            $wA = $genderWeights[$a->gender] ?? 4;
            $wB = $genderWeights[$b->gender] ?? 4;

            if ($wA !== $wB) {
                return $wA <=> $wB;
            }

            return strcmp($this->normalizeStudentName($a), $this->normalizeStudentName($b));
        })->values();

        $perPage = min((int) ($filters['per_page'] ?? 20), 100);
        $page = LengthAwarePaginator::resolveCurrentPage();

        $paginator = new LengthAwarePaginator(
            $sortedStudents->forPage($page, $perPage)->values(),
            $sortedStudents->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ]
        );

        $responseArray = $paginator->toArray();
        $responseArray['total_count'] = $totalCount;
        $responseArray['male_count'] = $maleCount;
        $responseArray['female_count'] = $femaleCount;
        $responseArray['unknown_count'] = $unknownCount;
        $responseArray['active_filters'] = [
            'gender' => $selectedGender,
            'level_id' => $filters['level_id'] ?? null,
            'section_id' => $filters['section_id'] ?? null,
            'academic_year_id' => $filters['academic_year_id'] ?? null,
            'search' => $filters['search'] ?? null,
        ];

        return $responseArray;
    }

    public function getStudentById(int $id): ?Student
    {
        return Student::with([
            'enrollments.level',
            'enrollments.section',
            'enrollments.academicYear',
            'guardians',
        ])->find($id);
    }

    public function getStudentBalance(Student $student): float
    {
        return $this->paymentService->getStudentBalance($student->id); // DI بدل app() ✅
    }

    private function normalizeStudentName(Student $student): string
    {
        $name = trim($student->first_name.' '.$student->last_name);
        $name = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $name) ?? $name;
        $name = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $name);

        return mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    /**
     * تخمين الجنس من الاسم العربي عندما يكون الحقل فارغاً:
     * مطابقة كاملة مع القوائم أولاً (تشمل الأسماء المركبة)، ثم قواعد النهايات
     * (تاء مربوطة / ألف مقصورة / ألف+همزة)، ثم مطابقة الكلمة الأولى.
     */
    private function inferGenderFromName(?string $firstName): string
    {
        $name = self::normalizeName($firstName);
        if ($name === '') {
            return 'unknown';
        }

        if (in_array($name, self::normalizedList(self::MALE_NAMES), true)) {
            return 'male';
        }

        if (in_array($name, self::normalizedList(self::FEMALE_NAMES), true)) {
            return 'female';
        }

        if (str_ends_with($name, 'ة') || str_ends_with($name, 'ه') || str_ends_with($name, 'اء')) {
            return 'female';
        }

        if (str_ends_with($name, 'ى') || str_ends_with($name, 'ي')) {
            return in_array($name, self::normalizedList(self::FEMALE_YA_NAMES), true) ? 'female' : 'unknown';
        }

        $firstToken = explode(' ', $name)[0] ?? '';
        if ($firstToken !== '' && $firstToken !== $name) {
            if (in_array($firstToken, self::normalizedList(self::MALE_NAMES), true)) {
                return 'male';
            }
            if (in_array($firstToken, self::normalizedList(self::FEMALE_NAMES), true)) {
                return 'female';
            }
        }

        return 'unknown';
    }

    private static function normalizeName(?string $value): string
    {
        $clean = trim((string) $value);
        $clean = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $clean) ?? $clean;
        $clean = str_replace(['أ', 'إ', 'آ'], 'ا', $clean);

        return mb_strtolower(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
    }

    private static function normalizedList(array $names): array
    {
        return array_map(fn (string $name) => self::normalizeName($name), $names);
    }
}
