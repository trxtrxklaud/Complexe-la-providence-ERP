<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Club;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Models\StudentFee;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FamilyService
{
    public function __construct(
        protected LedgerService $ledgerService
    ) {}

    /**
     * قائمة العائلات مع تجميع الأبناء وحساب إجمالي المستحقات.
     */
    public function listFamilies(?string $search = null, int $perPage = 25, int $page = 1): array
    {
        $query = Guardian::query()
            ->with(['students.enrollments.section.level', 'students.enrollments.studentFees.paymentAllocations']);

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function (Guardian $g) {
            return $this->formatFamilySummary($g);
        });

        if ($paginator->total() === 0 && ! empty($search)) {
            $phoneStudents = Student::where('guardian_phone', 'like', "%{$search}%")
                ->orWhere('guardian_first_name', 'like', "%{$search}%")
                ->orWhere('guardian_last_name', 'like', "%{$search}%")
                ->with(['enrollments.section.level', 'enrollments.studentFees.paymentAllocations'])
                ->get()
                ->groupBy('guardian_phone');

            $fallbackItems = [];
            foreach ($phoneStudents as $phone => $sts) {
                if (empty($phone)) {
                    continue;
                }
                $first = $sts->first();
                $fallbackItems[] = $this->formatVirtualFamilySummary($phone, $first->guardian_first_name, $first->guardian_last_name, $sts);
            }

            if (! empty($fallbackItems)) {
                return [
                    'data' => $fallbackItems,
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => count($fallbackItems),
                ];
            }
        }

        return [
            'data' => $items,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * جلب تفاصيل عائلة محددة عبر ID رقمي أو معرف هاتف ونصي بشكل آمن ومرن.
     */
    public function getFamilyDetails(string|int $familyId): array
    {
        $guardian = null;
        $details = null;

        // 1. إذا كان المعرف رقمياً (مثل 123)
        if (is_numeric($familyId)) {
            $guardian = Guardian::with([
                'students.enrollments.section.level',
                'students.enrollments.academicYear',
                'students.enrollments.studentFees.feeType',
                'students.enrollments.studentFees.paymentAllocations',
            ])->find((int) $familyId);

            if ($guardian) {
                $details = $this->formatFamilyFullDetails($guardian);
            }
        }

        // 2. إذا كان المعرف نصياً يعتمد على الهاتف (مثل phone_95420350 أو 95420350)
        if (! $details) {
            $phone = str_replace('phone_', '', (string) $familyId);
            $phoneDigits = preg_replace('/\D/', '', $phone);

            if (! empty($phoneDigits)) {
                $guardian = Guardian::with([
                    'students.enrollments.section.level',
                    'students.enrollments.academicYear',
                    'students.enrollments.studentFees.feeType',
                    'students.enrollments.studentFees.paymentAllocations',
                ])->where('phone', 'like', "%{$phoneDigits}%")->first();

                if ($guardian) {
                    $details = $this->formatFamilyFullDetails($guardian);
                } else {
                    $students = Student::where('guardian_phone', 'like', "%{$phoneDigits}%")
                        ->with([
                            'enrollments.section.level',
                            'enrollments.academicYear',
                            'enrollments.studentFees.feeType',
                            'enrollments.studentFees.paymentAllocations',
                        ])->get();

                    if ($students->isNotEmpty()) {
                        $details = $this->formatVirtualFamilyFullDetails($phone, $students);
                    }
                }
            }
        }

        if (! $details) {
            throw new ModelNotFoundException('العائلة غير موجودة');
        }

        $availableClubs = Club::where('is_active', true)
            ->select('id', 'name', 'monthly_fee')
            ->orderBy('name')
            ->get();

        $availableFeeTypes = FeeType::where('is_active', true)
            ->select('id', 'name_ar', 'price', 'ledger_category')
            ->orderBy('name_ar')
            ->get();

        return array_merge($details, [
            'available_clubs' => $availableClubs,
            'available_fee_types' => $availableFeeTypes,
        ]);
    }

    /**
     * تنفيذ التحصيل الجماعي للعائلة في DB transaction واحدة وإصدار payment واحد برقم واحد.
     */
    public function collectFamilyPayment(array $data, int $userId): array
    {
        return DB::transaction(function () use ($data, $userId) {
            $familyId = $data['family_id'];
            $guardian = null;

            if (is_numeric($familyId)) {
                $guardian = Guardian::find((int) $familyId);
            }

            if (! $guardian) {
                $phoneDigits = preg_replace('/\D/', '', (string) $familyId);
                if (! empty($phoneDigits)) {
                    $guardian = Guardian::where('phone', 'like', "%{$phoneDigits}%")->first();
                }
            }

            $guardianName = $guardian
                ? "{$guardian->first_name} {$guardian->last_name}"
                : 'ولي أمر (هاتف '.str_replace('phone_', '', (string) $familyId).')';
            $guardianIdLabel = $guardian?->id ?? $familyId;

            $allocationsInput = $data['allocations'] ?? [];
            $paymentDate = $data['payment_date'] ?? now()->toDateString();
            $method = $data['method'] ?? 'cash';
            $reference = $data['reference'] ?? null;
            $notes = $data['notes'] ?? null;

            if (empty($allocationsInput)) {
                throw new InvalidArgumentException('يجب اختيار بند واحد على الأقل للتحصيل الجماعي');
            }

            $totalAmount = 0;
            $processedAllocations = [];
            $affectedStudents = [];

            foreach ($allocationsInput as $alloc) {
                $feeId = (int) ($alloc['student_fee_id'] ?? 0);
                $amountToPay = (float) $alloc['amount'];

                if ($amountToPay <= 0) {
                    continue;
                }

                $studentFee = null;

                // إذا كان بنداً جديداً تم إنشاؤه في شاشة الاستخلاص (ترسيم جديد أو نادي جديد)
                if ($feeId === 0 && ! empty($alloc['new_item'])) {
                    $newItem = $alloc['new_item'];
                    $stId = (int) $newItem['student_id'];
                    $enrollmentId = (int) $newItem['enrollment_id'];
                    $itemType = $newItem['type'] ?? 'custom';

                    if ($itemType === 'registration') {
                        $feeTypeId = (int) ($newItem['fee_type_id'] ?? 0);
                        $grossAmount = (float) ($newItem['amount_due'] ?? $newItem['amount'] ?? 0);
                        $desc = $newItem['description'] ?? 'معلوم ترسيم';

                        $existingFee = StudentFee::where('enrollment_id', $enrollmentId)
                            ->where(function ($q) use ($feeTypeId) {
                                if ($feeTypeId > 0) {
                                    $q->where('fee_type_id', $feeTypeId);
                                } else {
                                    $q->where('description', 'like', '%ترسيم%');
                                }
                            })->first();

                        if ($existingFee) {
                            if ($existingFee->outstanding() <= 0 || $existingFee->status === 'paid') {
                                throw new InvalidArgumentException('معلوم الترسيم لهذا التلميذ مدفوع مسبقاً ولا يمكن تكرار استخلاصه');
                            }
                            $studentFee = $existingFee;
                        } else {
                            $studentFee = StudentFee::create([
                                'enrollment_id' => $enrollmentId,
                                'fee_type_id' => $feeTypeId ?: null,
                                'description' => $desc,
                                'amount_due' => $grossAmount,
                                'due_date' => $paymentDate,
                                'status' => 'pending',
                            ]);
                        }
                    } elseif ($itemType === 'club') {
                        $clubId = (int) ($newItem['club_id'] ?? 0);
                        $feeTypeId = (int) ($newItem['fee_type_id'] ?? 0);
                        $grossAmount = (float) ($newItem['amount_due'] ?? $newItem['amount'] ?? 0);
                        $desc = $newItem['description'] ?? 'معلوم نادي';

                        if ($clubId > 0) {
                            $activeYearId = AcademicYear::where('is_active', true)->value('id') ?? 1;
                            try {
                                app(ClubService::class)->subscribeStudent($stId, $clubId, $activeYearId, null, null, $enrollmentId);
                            } catch (\Exception $e) {
                                // قد يكون مسجلاً مسبقاً، نواصل العمل
                            }
                        }

                        $existingFee = StudentFee::where('enrollment_id', $enrollmentId)
                            ->where('description', $desc)
                            ->first();

                        if ($existingFee) {
                            if ($existingFee->outstanding() <= 0 || $existingFee->status === 'paid') {
                                throw new InvalidArgumentException("معلوم النادي ({$desc}) مدفوع مسبقاً ولا يمكن تكرار استخلاصه");
                            }
                            $studentFee = $existingFee;
                        } else {
                            $studentFee = StudentFee::create([
                                'enrollment_id' => $enrollmentId,
                                'fee_type_id' => $feeTypeId ?: null,
                                'description' => $desc,
                                'amount_due' => $grossAmount,
                                'due_date' => $paymentDate,
                                'status' => 'pending',
                            ]);
                        }
                    }
                } else {
                    $studentFee = StudentFee::where('id', $feeId)->lockForUpdate()->first();
                }

                if (! $studentFee) {
                    throw new InvalidArgumentException('الرسم المستحق غير موجود');
                }

                $remaining = $studentFee->outstanding();
                if ($remaining <= 0 || $studentFee->status === 'paid') {
                    throw new InvalidArgumentException("البند ({$studentFee->description}) محصل بالكامل مسبقاً ولا يمكن تكرار استخلاصه");
                }

                $actualPay = min($amountToPay, $remaining);
                $stId = $studentFee->student_id ?? $studentFee->enrollment?->student_id;

                $processedAllocations[] = [
                    'studentFee' => $studentFee,
                    'amount' => $actualPay,
                    'student_id' => $stId,
                ];

                $totalAmount += $actualPay;
                if ($stId) {
                    $affectedStudents[$stId] = true;
                }
            }

            if ($totalAmount <= 0) {
                throw new InvalidArgumentException('إجمالي المبلغ المدفوع يجب أن يكون أكبر من صفر');
            }

            $primaryStudentId = array_key_first($affectedStudents) ?? $processedAllocations[0]['student_id'] ?? null;

            // 1. إنشاء سجل Payment موحد واحد برقم واحد payment_id
            $payment = Payment::create([
                'student_id' => $primaryStudentId,
                'enrollment_id' => null,
                'amount' => $totalAmount,
                'payment_date' => $paymentDate,
                'method' => $method,
                'reference' => $reference,
                'notes' => $notes ? "تحصيل جماعي للعائلة #{$guardianIdLabel} - {$notes}" : "تحصيل جماعي لعائلة {$guardianName}",
                'created_by' => $userId,
                'meta' => [
                    'family_id' => $guardianIdLabel,
                    'guardian_name' => $guardianName,
                    'is_collective' => true,
                ],
            ]);

            $receiptItems = [];

            // 2. ربط كل مبلغ بالبند والتلميذ في payment_allocations وتحديث حالة البند
            foreach ($processedAllocations as $item) {
                /** @var StudentFee $fee */
                $fee = $item['studentFee'];
                $pay = $item['amount'];

                PaymentAllocation::create([
                    'payment_id' => $payment->id,
                    'student_fee_id' => $fee->id,
                    'amount_allocated' => $pay,
                ]);

                $fee->refresh();
                $newRemaining = $fee->outstanding();
                $newPaid = $fee->allocatedAmount();

                $newStatus = ($newRemaining <= 0) ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
                $fee->update(['status' => $newStatus]);

                $studentId = $fee->student_id ?? $fee->enrollment?->student_id;
                $student = $studentId ? Student::find($studentId) : null;
                $stName = $student ? "{$student->first_name} {$student->last_name}" : 'تلميذ';

                $receiptItems[] = [
                    'description' => "[{$stName}] ".($fee->description ?? 'رسم مستحق'),
                    'amount' => $pay,
                    'student_name' => $stName,
                ];
            }

            // 3. قيد بالدفتر النقدي المركزي عبر recordPayment: يوزّع المبلغ تلقائياً
            // على بنود المداخيل حسب الرسوم، ويفصل قبض ديون السنوات السابقة
            // (prior_year_debt) عن مدخول السنة الحالية — نفس منطق الاستخلاص المفرد.
            $this->ledgerService->recordPayment($payment);

            $familyDetailsAfter = $this->getFamilyDetails($familyId);

            return [
                'payment_id' => $payment->id,
                'payment_date' => $paymentDate,
                'method' => $method,
                'reference' => $reference,
                'total' => $totalAmount,
                'amount' => $totalAmount,
                'guardian_name' => $guardianName,
                'student_name' => "عائلة {$guardianName}",
                'items' => $receiptItems,
                'remaining_amount' => $familyDetailsAfter['family_remaining_debt'],
                'user_name' => auth()->user()?->first_name.' '.auth()->user()?->last_name,
            ];
        });
    }

    protected function formatFamilySummary(Guardian $g): array
    {
        $students = $g->students;
        $totalDue = 0;
        $totalPaid = 0;
        $totalRemaining = 0;

        $formattedStudents = $students->map(function (Student $st) use (&$totalDue, &$totalPaid, &$totalRemaining) {
            $fees = $st->enrollments->flatMap->studentFees;
            $stDue = $fees->sum(fn ($f) => (float) $f->amount_due);
            $stPaid = $fees->sum(fn ($f) => $f->allocatedAmount());
            $stRemaining = $fees->sum(fn ($f) => $f->outstanding());

            $totalDue += $stDue;
            $totalPaid += $stPaid;
            $totalRemaining += $stRemaining;

            $activeEnrollment = $st->enrollments->sortByDesc('id')->first();
            $section = $activeEnrollment?->section;
            $level = $section?->level;

            return [
                'id' => $st->id,
                'name' => "{$st->first_name} {$st->last_name}",
                'student_code' => $st->student_code,
                'section_name' => $section ? ($level ? "{$level->name} - {$section->name}" : $section->name) : 'غير مسجل',
                'remaining_debt' => $stRemaining,
            ];
        });

        return [
            'id' => $g->id,
            'guardian_name' => "{$g->first_name} {$g->last_name}",
            'phone' => $g->phone,
            'address' => $g->address,
            'students_count' => $students->count(),
            'students' => $formattedStudents,
            'family_total_due' => $totalDue,
            'family_total_paid' => $totalPaid,
            'family_remaining_debt' => $totalRemaining,
        ];
    }

    protected function formatVirtualFamilySummary(string $phone, ?string $fn, ?string $ln, $students): array
    {
        $gName = trim("{$fn} {$ln}") ?: 'ولي أمر';
        $totalDue = 0;
        $totalPaid = 0;
        $totalRemaining = 0;

        $formattedStudents = $students->map(function (Student $st) use (&$totalDue, &$totalPaid, &$totalRemaining) {
            $fees = $st->enrollments->flatMap->studentFees;
            $stDue = $fees->sum(fn ($f) => (float) $f->amount_due);
            $stPaid = $fees->sum(fn ($f) => $f->allocatedAmount());
            $stRemaining = $fees->sum(fn ($f) => $f->outstanding());

            $totalDue += $stDue;
            $totalPaid += $stPaid;
            $totalRemaining += $stRemaining;

            $activeEnrollment = $st->enrollments->sortByDesc('id')->first();
            $section = $activeEnrollment?->section;
            $level = $section?->level;

            return [
                'id' => $st->id,
                'name' => "{$st->first_name} {$st->last_name}",
                'student_code' => $st->student_code,
                'section_name' => $section ? ($level ? "{$level->name} - {$section->name}" : $section->name) : 'غير مسجل',
                'remaining_debt' => $stRemaining,
            ];
        });

        return [
            'id' => 'phone_'.preg_replace('/\D/', '', $phone),
            'guardian_name' => $gName,
            'phone' => $phone,
            'address' => '—',
            'students_count' => $students->count(),
            'students' => $formattedStudents,
            'family_total_due' => $totalDue,
            'family_total_paid' => $totalPaid,
            'family_remaining_debt' => $totalRemaining,
        ];
    }

    protected function formatFamilyFullDetails(Guardian $g): array
    {
        $students = $g->students;
        $familyRemaining = 0;
        $familyPaid = 0;
        $familyDue = 0;

        $studentsData = $students->map(function (Student $st) use (&$familyRemaining, &$familyPaid, &$familyDue) {
            $fees = $st->enrollments->flatMap->studentFees;
            $unpaidFees = $fees->filter(fn ($f) => $f->outstanding() > 0 && $f->status !== 'paid')->values();

            $stRemaining = $fees->sum(fn ($f) => $f->outstanding());
            $stPaid = $fees->sum(fn ($f) => $f->allocatedAmount());
            $stDue = $fees->sum(fn ($f) => (float) $f->amount_due);

            $familyRemaining += $stRemaining;
            $familyPaid += $stPaid;
            $familyDue += $stDue;

            $activeEnrollment = $st->enrollments->sortByDesc('id')->first();
            $section = $activeEnrollment?->section;
            $level = $section?->level;
            $academicYear = $activeEnrollment?->academicYear;

            return [
                'id' => $st->id,
                'first_name' => $st->first_name,
                'last_name' => $st->last_name,
                'name' => "{$st->first_name} {$st->last_name}",
                'student_code' => $st->student_code,
                'section_name' => $section ? ($level ? "{$level->name} - {$section->name}" : $section->name) : 'غير مسجل',
                'academic_year' => $academicYear?->name ?? '2026–2027',
                'enrollment_id' => $activeEnrollment?->id,
                'remaining_debt' => $stRemaining,
                'total_paid' => $stPaid,
                'unpaid_fees' => $unpaidFees->map(fn ($f) => [
                    'id' => $f->id,
                    'fee_type_id' => $f->fee_type_id,
                    'description' => $f->description ?? $f->feeType?->name_ar ?? 'بند مستحق',
                    'month_name' => $f->month_name,
                    'gross_amount' => (float) $f->amount_due,
                    'discount_amount' => (float) $f->waivedAmount(),
                    'paid_amount' => (float) $f->allocatedAmount(),
                    'remaining_amount' => (float) $f->outstanding(),
                    'status' => $f->status,
                ]),
            ];
        });

        return [
            'id' => $g->id,
            'guardian_name' => "{$g->first_name} {$g->last_name}",
            'phone' => $g->phone,
            'email' => $g->email,
            'address' => $g->address,
            'mother_phone' => $g->mother_phone,
            'students_count' => $students->count(),
            'students' => $studentsData,
            'family_total_due' => $familyDue,
            'family_total_paid' => $familyPaid,
            'family_remaining_debt' => $familyRemaining,
        ];
    }

    protected function formatVirtualFamilyFullDetails(string $phone, $students): array
    {
        $first = $students->first();
        $gName = trim(($first->guardian_first_name ?? '').' '.($first->guardian_last_name ?? '')) ?: 'ولي أمر';

        $familyRemaining = 0;
        $familyPaid = 0;
        $familyDue = 0;

        $studentsData = $students->map(function (Student $st) use (&$familyRemaining, &$familyPaid, &$familyDue) {
            $fees = $st->enrollments->flatMap->studentFees;
            $unpaidFees = $fees->filter(fn ($f) => $f->outstanding() > 0 && $f->status !== 'paid')->values();

            $stRemaining = $fees->sum(fn ($f) => $f->outstanding());
            $stPaid = $fees->sum(fn ($f) => $f->allocatedAmount());
            $stDue = $fees->sum(fn ($f) => (float) $f->amount_due);

            $familyRemaining += $stRemaining;
            $familyPaid += $stPaid;
            $familyDue += $stDue;

            $activeEnrollment = $st->enrollments->sortByDesc('id')->first();
            $section = $activeEnrollment?->section;
            $level = $section?->level;
            $academicYear = $activeEnrollment?->academicYear;

            return [
                'id' => $st->id,
                'first_name' => $st->first_name,
                'last_name' => $st->last_name,
                'name' => "{$st->first_name} {$st->last_name}",
                'student_code' => $st->student_code,
                'section_name' => $section ? ($level ? "{$level->name} - {$section->name}" : $section->name) : 'غير مسجل',
                'academic_year' => $academicYear?->name ?? '2026–2027',
                'enrollment_id' => $activeEnrollment?->id,
                'remaining_debt' => $stRemaining,
                'total_paid' => $stPaid,
                'unpaid_fees' => $unpaidFees->map(fn ($f) => [
                    'id' => $f->id,
                    'fee_type_id' => $f->fee_type_id,
                    'description' => $f->description ?? $f->feeType?->name_ar ?? 'بند مستحق',
                    'month_name' => $f->month_name,
                    'gross_amount' => (float) $f->amount_due,
                    'discount_amount' => (float) $f->waivedAmount(),
                    'paid_amount' => (float) $f->allocatedAmount(),
                    'remaining_amount' => (float) $f->outstanding(),
                    'status' => $f->status,
                ]),
            ];
        });

        return [
            'id' => 'phone_'.preg_replace('/\D/', '', $phone),
            'guardian_name' => $gName,
            'phone' => $phone,
            'email' => null,
            'address' => '—',
            'mother_phone' => $first->mother_phone ?? null,
            'students_count' => $students->count(),
            'students' => $studentsData,
            'family_total_due' => $familyDue,
            'family_total_paid' => $familyPaid,
            'family_remaining_debt' => $familyRemaining,
        ];
    }
}
