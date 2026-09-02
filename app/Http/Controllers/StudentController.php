<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransferStudentsRequest;
use App\Models\AcademicYear;
use App\Models\CashTransaction;
use App\Models\Enrollment;
use App\Models\Section;
use App\Models\Student;
use App\Services\AuditService;
use App\Services\EnrollmentService;
use App\Services\RegistrationPaymentService;
use App\Services\StudentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    /**
     * رسائل التحقّق العربية لحقل القسم ومعلوم الترسيم.
     *
     * مكتوبة هنا وليس في lang/ar لأن المشروع لا يزال يعتمد رسائل لارافيل الإنجليزية؛
     * تركها للافتراض يعني أن يرى القابض "The section id field is required."
     */
    private const SECTION_MESSAGES = [
        'section_id.required' => 'القسم إجباري: اختر قسم التلميذ لهذه السنة الدراسية.',
        'section_id.integer' => 'القسم المختار غير صالح.',
        'section_id.exists' => 'القسم المختار غير موجود في قائمة الأقسام.',
        'notes.max' => 'الملاحظات طويلة جداً (1000 حرف كحدّ أقصى).',
        'registration_amount.required' => 'أدخل المبلغ المقبوض.',
        'registration_amount.numeric' => 'مبلغ الترسيم يجب أن يكون رقماً.',
        'registration_amount.min' => 'مبلغ الترسيم يجب أن يكون أكبر من صفر.',
        'payment_method.in' => 'طريقة الدفع غير معروفة.',
        'payment_method.required' => 'اختر طريقة الدفع الموافقة للمبلغ المقبوض.',
        'payment_method.required_with' => 'اختر طريقة الدفع الموافقة للمبلغ المقبوض.',
        'payment_date.required' => 'تاريخ الدفع إجباري مع وجود مبلغ مقبوض.',
        'payment_date.required_with' => 'تاريخ الدفع إجباري مع وجود مبلغ مقبوض.',
        'payment_date.date' => 'تاريخ الدفع غير صالح.',
    ];

    /**
     * قواعد معلوم الترسيم المقبوض لحظة التسجيل.
     *
     * مشتركة بين التسجيل الجديد وتجديد الترسيم عمداً: قاعدتان متفرقتان تنحرفان
     * عند أوّل تعديل، فيقبل مسار ما يرفضه الآخر دون سبب مفهوم للقابض.
     *
     * @return array<string,mixed>
     */
    private static function paymentRules(): array
    {
        return [
            'client_request_id' => ['nullable', 'required_with:registration_amount', 'string', 'max:255'],
            'registration_amount' => ['nullable', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'required_with:registration_amount', 'in:cash,bank_transfer,check,card'],
            'payment_date' => ['nullable', 'required_with:registration_amount', 'date'],
            'payment_notes' => ['nullable', 'string', 'max:1000'],
            'fee_items' => ['nullable', 'array'],
            'fee_items.*.fee_type_id' => ['nullable', 'integer'],
            'fee_items.*.amount' => ['nullable', 'numeric', 'min:0'],
            'fee_items.*.description' => ['nullable', 'string', 'max:255'],
            'fee_items.*.category' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function __construct(
        protected StudentService $studentService,
        protected EnrollmentService $enrollmentService,
        protected RegistrationPaymentService $registrationPaymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $students = $this->studentService->getStudentsWithCurrentEnrollment([
            'search' => $request->get('student_name', $request->get('search')),
            'phone' => $request->get('phone'),
            'dob' => $request->get('birthday'),
            'student_code' => $request->get('cnte'),
            'level_id' => $request->get('level_id'),
            'section_id' => $request->get('level', $request->get('section_id')),
            'academic_year_id' => $request->get('year'),
            'gender' => $request->get('gender', 'all'),
            'per_page' => min((int) $request->get('per_page', 20), 100),
        ]);

        return response()->json($students);
    }

    public function searchOptions(): JsonResponse
    {
        $sections = Section::with('level:id,name')
            ->orderBy('level_id')
            ->orderBy('name')
            ->get(['id', 'level_id', 'name'])
            ->map(fn (Section $section) => [
                'id' => $section->id,
                'level_id' => $section->level_id,
                'label' => trim(($section->level?->name ? $section->level->name.' ' : '').$section->name),
            ]);

        $activeYears = AcademicYear::where('is_active', true)->get(['id', 'name']);
        $activeYear = $activeYears->count() === 1 ? [
            'id' => $activeYears->first()->id,
            'name' => $activeYears->first()->name,
        ] : null;

        return response()->json([
            'levels' => $sections,
            'sections' => $sections,
            'years' => AcademicYear::orderByDesc('start_date')->get(['id', 'name']),
            'active_year' => $activeYear,
        ]);
    }

    public function transferRoster(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
        ]);

        $students = Enrollment::query()
            ->where('academic_year_id', $validated['academic_year_id'])
            ->where('section_id', $validated['section_id'])
            ->where('status', 'active')
            ->with([
                'student.guardians' => fn ($query) => $query->orderByDesc('guardian_student.is_primary_contact'),
            ])
            ->get()
            ->filter(fn (Enrollment $enrollment) => $enrollment->student !== null)
            ->sortBy(fn (Enrollment $enrollment) => $enrollment->student->first_name.' '.$enrollment->student->last_name)
            ->values()
            ->map(function (Enrollment $enrollment) {
                $student = $enrollment->student;
                $guardian = $student->guardians->first();

                return [
                    'id' => $student->id,
                    'student_code' => $student->student_code,
                    'first_name' => $student->first_name,
                    'last_name' => $student->last_name,
                    'dob' => $student->dob?->toDateString(),
                    'gender' => $student->gender,
                    'guardian_name' => trim(implode(' ', array_filter([
                        $guardian?->first_name ?? $student->guardian_first_name,
                        $guardian?->last_name ?? $student->guardian_last_name,
                    ]))),
                    'mother_name' => $student->mother_name,
                    'phone' => $guardian?->phone ?? $student->guardian_phone ?? $student->mother_phone,
                ];
            });

        return response()->json(['students' => $students]);
    }

    public function transfer(TransferStudentsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $transferred = DB::transaction(function () use ($data) {
            $destination = Section::query()->lockForUpdate()->findOrFail($data['destination_section_id']);

            $enrollments = Enrollment::query()
                ->where('academic_year_id', $data['academic_year_id'])
                ->where('section_id', $data['source_section_id'])
                ->where('status', 'active')
                ->whereIn('student_id', $data['student_ids'])
                ->lockForUpdate()
                ->get();

            $matchedStudentIds = $enrollments->pluck('student_id')->unique()->values();

            if ($matchedStudentIds->count() !== count($data['student_ids'])) {
                throw ValidationException::withMessages([
                    'student_ids' => ['بعض التلاميذ المحددين لا ينتمون إلى القسم المصدر في السنة المختارة.'],
                ]);
            }

            $destinationEnrollmentIds = Enrollment::query()
                ->where('academic_year_id', $data['academic_year_id'])
                ->where('section_id', $destination->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->pluck('id');

            if (
                $destination->capacity > 0
                && $destinationEnrollmentIds->count() + $matchedStudentIds->count() > $destination->capacity
            ) {
                throw ValidationException::withMessages([
                    'destination_section_id' => ['لا تتسع سعة القسم الوجهة لكل التلاميذ المحددين.'],
                ]);
            }

            Enrollment::query()
                ->whereKey($enrollments->modelKeys())
                ->update([
                    'level_id' => $destination->level_id,
                    'section_id' => $destination->id,
                ]);

            return $matchedStudentIds->count();
        });

        return response()->json([
            'transferred' => $transferred,
            'message' => "تم نقل {$transferred} تلميذ بنجاح.",
        ]);
    }

    // FIX: Route Model Binding instead of int $id + manual lookup
    /**
     * Photo via authenticated route - private disk, no public URL.
     */
    public function photo(Student $student): StreamedResponse
    {
        $path = (string) $student->photo;

        $missing = ($path === '') || (Storage::disk('local')->exists($path) === false);
        abort_if($missing, 404);

        return Storage::disk('local')->response($path, basename($path), [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function show(Student $student): JsonResponse
    {
        return response()->json(
            $student->load(['enrollments.level', 'enrollments.section', 'enrollments.academicYear', 'guardians'])
        );
    }

    public function paymentHistory(Student $student): JsonResponse
    {
        $payments = $student->payments()
            ->with([
                'enrollment.academicYear:id,name',
                'enrollment.level:id,name',
                'paymentAllocations.studentFee:id,description,amount_due,due_date,status',
            ])
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($payment) => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'payment_date' => $payment->payment_date?->toDateString(),
                'months' => $payment->months ?? [],
                'method' => $payment->method,
                'reference' => $payment->reference,
                'cancelled_at' => $payment->cancelled_at?->toISOString(),
                'cancellation_reason' => $payment->cancellation_reason,
                'enrollment' => $payment->enrollment,
                'allocations' => $payment->paymentAllocations->map(fn ($allocation) => [
                    'amount' => $allocation->amount_allocated,
                    'fee' => $allocation->studentFee,
                ]),
            ]);

        return response()->json($payments);
    }

    /**
     * تسجيل تلميذ جديد + ترسيمه في قسم + قبض معلوم الترسيم، في معاملة واحدة.
     *
     * القسم إجباري والمستوى يُشتقّ منه داخل EnrollmentService؛ level_id يُقبل اختيارايًا
     * للتوافق مع أي مستهلك قديم، ويُرفض إن ناقض القسم بدل أن يُتجاهل بصمت.
     *
     * معلوم الترسيم يُسقط في الدفتر النقدي داخل نفس المعاملة: إمّا أن يُرسّم التلميذ
     * ويدخل ماله الخزينة معاً، أو لا يحدث شيء. لا حالة ثالثة.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'dob' => 'required|date',
            'gender' => 'required|in:male,female',
            'notes' => 'nullable|string|max:1000',
            'guardian_first_name' => 'required|string|max:255',
            'guardian_last_name' => 'required|string|max:255',
            'guardian_phone' => 'required|string|max:20',
            'guardian_email' => 'nullable|email',
            'address' => 'required|string',
            'mother_phone' => 'nullable|string|max:20',
            'mother_email' => 'nullable|email',
            'section_id' => 'required|integer|exists:sections,id',
            'level_id' => 'nullable|exists:levels,id',
            'section_name' => 'nullable|string',
            'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:2048',
        ], self::paymentRules()), self::SECTION_MESSAGES);

        try {
            $result = DB::transaction(function () use ($validated, $request) {
                $enrollment = $this->enrollmentService->enrollStudent(
                    $validated,
                    $request->file('photo')
                );

                $payment = $this->registrationPaymentService->record(
                    $enrollment,
                    $validated,
                    $request->user()?->id,
                );

                return [$enrollment, $payment];
            });

            [$enrollment, $payment] = $result;

            if ($payment) {
                $payment->loadMissing(['paymentAllocations.studentFee']);
            }

            AuditService::log('student.create', 'تسجيل تلميذ جديد: '.trim($validated['first_name'].' '.$validated['last_name']), $enrollment->student, ['enrollment_id' => $enrollment->id]);

            return response()->json([
                'message' => 'تم تسجيل التلميذ بنجاح',
                'enrollment' => $enrollment->load(['student.guardians', 'level', 'section', 'academicYear']),
                'payment' => $payment ? [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'payment_date' => $payment->payment_date?->toDateString(),
                    'method' => $payment->method,
                    'notes' => $payment->notes,
                    'receipt_number' => 'REC-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                    'items' => $payment->paymentAllocations->map(fn ($a) => [
                        'name' => $a->studentFee?->description ?: 'بند',
                        'amount' => (float) $a->amount_allocated,
                    ]),
                ] : null,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'حدث خطأ أثناء التسجيل'], 500);
        }
    }

    // NEW: update student profile only (not enrollment)
    public function update(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'dob' => 'sometimes|date',
            'gender' => 'sometimes|in:male,female',
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive,transferred',
            'photo' => 'nullable|file|mimes:jpeg,jpg,png,webp|max:2048',
        ]);

        if ($request->hasFile('photo')) {
            if ($student->photo) {
                Storage::disk('local')->delete($student->photo);
            }
            $validated['photo'] = $request->file('photo')->store('students/photos', 'local');
        }

        $student->update($validated);

        AuditService::log('student.update', 'تعديل بيانات التلميذ: '.trim($student->first_name.' '.$student->last_name), $student, ['fields' => array_keys($validated)]);

        return response()->json($student->fresh());
    }

    public function destroy(Student $student): JsonResponse
    {
        $hasRelatedRecords = $student->enrollments()->exists()
            || $student->payments()->exists()
            || $student->clubSubscriptions()->exists();

        if ($hasRelatedRecords) {
            return response()->json([
                'message' => 'لا يمكن حذف تلميذ لديه تسجيلات أو سجلات مالية مرتبطة',
            ], 422);
        }

        if ($student->photo) {
            Storage::disk('local')->delete($student->photo);
        }

        $student->delete();

        AuditService::log('student.delete', 'حذف التلميذ: '.trim($student->first_name.' '.$student->last_name), $student);

        return response()->json(null, 204);
    }

    /**
     * NEW: enroll existing student in current academic year.
     *
     * يقبل الطريقين: section_id (المعتمد) أو level_id + section_name (القديم)،
     * حتى لا تنكسر أيّ شاشة لم تُحوّل بعد. reenroll() وحده صار صارماً.
     */
    public function enroll(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => ['nullable', 'integer', 'exists:sections,id'],
            'level_id' => ['required_without:section_id', 'nullable', 'exists:levels,id'],
            'section_name' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], self::SECTION_MESSAGES);

        try {
            $enrollment = $this->enrollmentService->reenrollStudent($student->id, $validated);

            return response()->json([
                'message' => 'تم التسجيل بنجاح',
                'enrollment' => $enrollment->load(['student', 'level', 'section']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'حدث خطأ أثناء التسجيل'], 500);
        }
    }

    /**
     * ترسيم تلميذ قديم — القسم إجباري، ومعلوم التجديد يدخل الخزينة في نفس المعاملة.
     *
     * الدفع ليس خطوة لاحقة اختيارية: تجديد بلا وصل يعني تلميذاً مُرسّماً بلا أثر
     * مالي، ولا يُكتشف إلا بجرد يدوي في آخر السنة. لذلك العمليتان في معاملة واحدة:
     * إمّا ترسيم مع مدخول، أو لا شيء.
     *
     * المبلغ اختياري: تلميذ يُجدّد اليوم ويدفع لاحقاً حالة واقعية، ومنعها يدفع القابض
     * إلى تسجيل مبلغ وهمي ليتجاوز الشاشة — وهذا أفسد للدفتر من فراغ مؤقّت.
     */
    public function reenroll(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate(array_merge([
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], self::paymentRules()), self::SECTION_MESSAGES);

        try {
            $result = DB::transaction(function () use ($student, $validated, $request) {
                $activeYearId = AcademicYear::where('is_active', true)->value('id');

                $enrollment = Enrollment::where('student_id', $student->id)
                    ->where('academic_year_id', $activeYearId)
                    ->where('status', 'active')
                    ->first();

                if ($enrollment) {
                    $hasPaidRegistration = $enrollment->studentFees()
                        ->where('status', 'paid')
                        ->where(function ($q) {
                            $q->whereHas('feeType', fn ($ft) => $ft->where('ledger_category', CashTransaction::CATEGORY_REGISTRATION_FEE))
                              ->orWhere('description', 'like', '%ترسيم%')
                              ->orWhere('description', 'like', '%تسجيل%');
                        })
                        ->exists();

                    $requestedFeeItems = $validated['fee_items'] ?? null;
                    $hasOtherItems = ! empty($requestedFeeItems) && collect($requestedFeeItems)->contains(function ($item) {
                        $desc = $item['description'] ?? '';
                        return ! str_contains($desc, 'ترسيم') && ! str_contains($desc, 'تسجيل');
                    });

                    if ($hasPaidRegistration && ! $hasOtherItems && empty($requestedFeeItems)) {
                        throw new \InvalidArgumentException('الطالب مُرسَّم بالفعل في السنة الدراسية الحالية');
                    }
                } else {
                    $enrollment = $this->enrollmentService->reenrollStudent($student->id, $validated);
                }

                $payload = $validated;
                $payload['payment_notes'] = $validated['payment_notes'] ?? 'معلوم تجديد الترسيم';

                $payment = $this->registrationPaymentService->record(
                    $enrollment,
                    $payload,
                    $request->user()?->id,
                );

                return [$enrollment, $payment];
            });

            [$enrollment, $payment] = $result;

            if ($payment) {
                $payment->loadMissing(['paymentAllocations.studentFee']);
            }

            return response()->json([
                'message' => $payment
                    ? 'تم الترسيم وتسجيل المبلغ في الخزينة بنجاح'
                    : 'تم الترسيم بنجاح',
                'enrollment' => $enrollment->load(['student.guardians', 'level', 'section', 'academicYear']),
                'payment' => $payment ? [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'payment_date' => $payment->payment_date?->toDateString(),
                    'method' => $payment->method,
                    'notes' => $payment->notes,
                    'receipt_number' => 'REC-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                    'items' => $payment->paymentAllocations->map(fn ($a) => [
                        'name' => $a->studentFee?->description ?: 'بند',
                        'amount' => (float) $a->amount_allocated,
                    ]),
                ] : null,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => str_contains($e->getMessage(), 'مُرسَّم بالفعل') ? 'already_enrolled' : null,
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'حدث خطأ أثناء الترسيم'], 500);
        }
    }

    /**
     * قبض معلوم الترسيم لتلميذ مُرسَّم سلفاً في السنة النشطة.
     *
     * سبب وجود هذا المسار: 546 ترسيماً دخلت جدول الترسيمات عبر ترحيل الترقية
     * (2026-07-25) دون أن تمرّ بمسار الدفع، فلا يقابلها سطر في الدفتر النقدي.
     * حارس الازدواج في reenroll() يمنع — عن حقّ — إنشاء ترسيم ثانٍ لهم، فلولا
     * هذا المسار لبقي معلوم 546 تلميذاً غير قابل للقبض إلا بحذف ترسيماتهم.
     *
     * الحارس لم يُمسّ: هنا لا يُنشأ ترسيم إطلاقاً، بل يُقبض المال على القائم منه.
     */
    public function registrationPayment(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'registration_amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'in:cash,bank_transfer,check,card'],
            'payment_date' => ['required', 'date'],
            'payment_notes' => ['nullable', 'string', 'max:1000'],
            'fee_items' => ['nullable', 'array'],
            'fee_items.*.fee_type_id' => ['nullable', 'integer'],
            'fee_items.*.amount' => ['nullable', 'numeric', 'min:0'],
            'fee_items.*.description' => ['nullable', 'string', 'max:255'],
            'fee_items.*.category' => ['nullable', 'string', 'max:50'],
        ], self::SECTION_MESSAGES);

        $academicYear = AcademicYear::where('is_active', true)->first();

        if (! $academicYear) {
            return response()->json([
                'message' => 'لا توجد سنة دراسية نشطة. فعّل السنة الدراسية أولاً.',
            ], 422);
        }

        $enrollment = Enrollment::where('student_id', $student->id)
            ->where('academic_year_id', $academicYear->id)
            ->latest('id')
            ->first();

        if (! $enrollment) {
            return response()->json([
                'message' => 'التلميذ غير مُرسَّم في السنة الدراسية الحالية. رسّمه أوّلاً ثمّ اقبض المعلوم.',
            ], 422);
        }

        $payload = $validated;
        $payload['payment_notes'] = $validated['payment_notes'] ?? 'معلوم تجديد الترسيم';

        try {
            $payment = DB::transaction(fn () => $this->registrationPaymentService->record(
                $enrollment,
                $payload,
                $request->user()?->id,
            ));
        } catch (QueryException $e) {
            report($e);

            // مفتاح المنع المزدوج enrollment-{id}-registration فريد في جدول الدفعات،
            // فتكرار القبض على نفس الترسيم يسقط هنا بدل أن يضاعف المدخول.
            return response()->json([
                'message' => 'سبق قبض معلوم الترسيم لهذا التلميذ في هذه السنة الدراسية.',
            ], 422);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => 'حدث خطأ أثناء تسجيل المبلغ'], 500);
        }

        if (! $payment) {
            return response()->json(['message' => 'لم يُسجَّل أي مبلغ.'], 422);
        }

        $payment->loadMissing(['paymentAllocations.studentFee']);

        return response()->json([
            'message' => 'تم تسجيل المبلغ في الخزينة بنجاح',
            'enrollment' => $enrollment->load(['student.guardians', 'level', 'section', 'academicYear']),
            'payment' => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'payment_date' => $payment->payment_date?->toDateString(),
                'method' => $payment->method,
                'notes' => $payment->notes,
                'receipt_number' => 'REC-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                'items' => $payment->paymentAllocations->map(fn ($a) => [
                    'name' => $a->studentFee?->description ?: 'بند',
                    'amount' => (float) $a->amount_allocated,
                ]),
            ],
        ], 201);
    }
}
