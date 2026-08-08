<?php
namespace App\Services;

use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class StudentService
{
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
                    $inferred = $this->inferGenderFromName((string) $student->first_name);
                    $resolvedGender = $inferred ?? 'unknown';
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
     * الاستدلال من الاسم العربي للتلاميذ المستوردين الذين لم يُسجّل جنسهم في قاعدة البيانات.
     */
    private function inferGenderFromName(string $firstName): ?string
    {
        $name = trim($firstName);
        if ($name === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $name);
        $first = $parts[0] ?? '';
        $normalizedFirst = str_replace(['أ', 'إ', 'آ'], 'ا', $first);

        $knownFemales = [
            'امنة', 'اية', 'ميار', 'سيرين', 'ريحان', 'مريم', 'ريم', 'سارة', 'ساره', 'لينا', 'ميرال',
            'نور', 'هبة', 'ياسمين', 'سلمى', 'خديجة', 'فاطمة', 'عائشة', 'زينب', 'نادين', 'شهد',
            'جنى', 'جودي', 'رتاج', 'ريتاج', 'تالين', 'اريج', 'اسراء', 'ايلاف', 'بلقيس', 'تسنيم',
            'حنين', 'داليا', 'دانية', 'رغد', 'روضة', 'زينة', 'سمر', 'سندس', 'شذى', 'شيماء',
            'عبير', 'غادة', 'غفران', 'فرح', 'لمى', 'مارية', 'مروى', 'ملاك', 'منال', 'مها',
            'ناديا', 'ندى', 'نغم', 'نهى', 'هاجر', 'وئام', 'يسر', 'رنيم', 'اميمة', 'الاء', 'اسماء',
            'ايناس', 'احلام', 'امال', 'اماني', 'اميرة', 'انسام', 'انصاف', 'انعام', 'ايمان',
            'بتول', 'بشرى', 'بسمة', 'تقوى', 'جواهر', 'جيهان', 'حسناء', 'حورية', 'خلود',
            'دعا', 'دعاء', 'ذكرى', 'رحمة', 'رحاب', 'رضوى', 'رنا', 'رندة', 'رهف', 'روان', 'رولا',
            'زهراء', 'زهرة', 'سلاف', 'سهام', 'سهيلة', 'سوزان', 'سناء', 'شروق', 'صفاء',
            'ضحى', 'عفاف', 'علا', 'علياء', 'غزلان', 'فاتن', 'فدوى', 'فيروز', 'كوثر', 'لمياء',
            'ليندا', 'ماجدة', 'مرام', 'مروة', 'منى', 'منيرة', 'مي', 'ميادة', 'ميساء', 'ميسون',
            'نجلاء', 'نجوى', 'نوال', 'نورها', 'نورهان', 'هالة', 'هناء', 'هنادي', 'هند', 'وفاء',
            'ولا', 'ولاء', 'يسرى', 'فردوس',
        ];

        $knownMales = [
            'خالد', 'اياد', 'ماجد', 'احمد', 'محمد', 'يوسف', 'امين', 'علي', 'عمر', 'حمزة',
            'بلال', 'انس', 'ريان', 'مهدي', 'وسيم', 'ادم', 'سليم', 'ياسين', 'عزيز', 'خليل',
            'فادي', 'كريم', 'هادي', 'الهادي', 'مالك', 'هارون', 'مصطفى', 'طه', 'وائل', 'زياد',
            'وليد', 'رامي', 'سامي', 'غسان', 'عمار', 'لؤي', 'اسامة', 'شريف', 'فريد', 'منتصر',
            'نضال', 'صابر', 'ضياء', 'عبد', 'سيف', 'فراس', 'ابراهيم', 'اسماعيل', 'ايمن', 'انور',
            'اشرف', 'ايوب', 'بدر', 'باسم', 'بشير', 'تامر', 'توفيق', 'جاسم', 'جابر', 'جلال',
            'جمال', 'حسام', 'حسان', 'حسن', 'حسين', 'حلمي', 'حمد', 'حمدي', 'حيدر',
            'داود', 'ربيع', 'رجب', 'رشيد', 'رضا', 'رمزي', 'زيان', 'سعد', 'سعود', 'سعيد',
            'سفيان', 'سلمان', 'سليمان', 'سمير', 'شادي', 'صالح', 'صلاح', 'طارق', 'عادل',
            'عارف', 'عاصم', 'عاطف', 'عباس', 'عبدالله', 'عبدالرحمن', 'عبدالعزيز', 'عبدالمجيد',
            'عثمان', 'عصام', 'علاء', 'عماد', 'فارس', 'فارق', 'فاروق', 'فاضل', 'فؤاد',
            'فوزي', 'فيصل', 'قصي', 'قيس', 'مازن', 'ماهر', 'مجدي', 'محمود', 'مروان',
            'مزهر', 'مسعود', 'معاذ', 'مقداد', 'منير', 'مهند', 'موسى', 'موفق', 'ناجي',
            'نايف', 'نبيل', 'نجيب', 'نزار', 'نوح', 'نورالدين', 'هاشم', 'هشام', 'هيثم',
            'وجدي', 'وديع', 'وسام', 'ياسر', 'يحيى', 'يعقوب', 'يونس',
        ];

        foreach ($knownFemales as $kf) {
            $normalizedKf = str_replace(['أ', 'إ', 'آ'], 'ا', $kf);
            if ($normalizedFirst === $normalizedKf) {
                return 'female';
            }
        }

        foreach ($knownMales as $km) {
            $normalizedKm = str_replace(['أ', 'إ', 'آ'], 'ا', $km);
            if ($normalizedFirst === $normalizedKm) {
                return 'male';
            }
        }

        if (str_ends_with($first, 'ة') || str_ends_with($first, 'اء')) {
            return 'female';
        }

        return null;
    }
}
