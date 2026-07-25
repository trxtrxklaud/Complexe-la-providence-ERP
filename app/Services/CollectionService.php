<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\FeeType;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\StudentFee;
use Illuminate\Support\Facades\DB;

class CollectionService
{
    private const SCHOOL_MONTHS = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6];

    private const MONTH_NAMES_AR = [
        '01' => 'جانفي', '02' => 'فيفري', '03' => 'مارس',
        '04' => 'أفريل', '05' => 'ماي', '06' => 'جوان',
        '07' => 'جويلية', '08' => 'أوت', '09' => 'سبتمبر',
        '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
    ];

    public function collect(array $data, int $createdBy): array
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $enrollment = Enrollment::with([
                'student.guardians',
                'academicYear',
                'level',
                'section',
            ])->findOrFail($data['enrollment_id']);

            $months = $data['months'];
            sort($months);
            $this->validateMonths($months, $enrollment);

            $monthsLabel = implode(' / ', array_map(
                fn ($m) => (self::MONTH_NAMES_AR[substr($m, 5)] ?? $m) . ' ' . substr($m, 0, 4),
                $months
            ));

            $itemsTotal = array_sum(array_column($data['items'], 'amount'));
            $discount = max(0, (float) ($data['discount'] ?? 0));
            $total = max(0, $itemsTotal - $discount);

            $payment = Payment::create([
                'student_id'    => $data['student_id'],
                'enrollment_id' => $data['enrollment_id'],
                'months'        => $months,
                'amount'        => $total,
                'payment_date'  => $data['payment_date'],
                'method'        => $data['method'],
                'reference'     => $data['reference'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'created_by'    => $createdBy,
            ]);

            $receiptItems = [];

            foreach ($data['items'] as $item) {
                $feeType = FeeType::findOrFail($item['fee_type_id']);

                $studentFee = StudentFee::create([
                    'enrollment_id' => $enrollment->id,
                    'fee_plan_id'   => null,
                    'description'   => $feeType->name_ar . ' — ' . $monthsLabel,
                    'amount_due'    => $item['amount'],
                    'due_date'      => $data['payment_date'],
                    'status'        => 'paid',
                ]);

                PaymentAllocation::create([
                    'payment_id'       => $payment->id,
                    'student_fee_id'   => $studentFee->id,
                    'amount_allocated' => $item['amount'],
                ]);

                $receiptItems[] = [
                    'fee_type_id'   => $feeType->id,
                    'fee_type_name' => $feeType->name_ar,
                    'amount'        => (float) $item['amount'],
                ];
            }

            $guardian = $enrollment->student->guardians
                ->sortByDesc(fn ($g) => $g->pivot->is_primary_contact ?? 0)
                ->first();

            return [
                'payment_id'   => $payment->id,
                'payment_date' => $data['payment_date'],
                'created_at'   => $payment->created_at->toIso8601String(),
                'method'       => $data['method'],
                'reference'    => $payment->reference,
                'notes'        => $payment->notes,
                'months'       => $months,
                'months_label' => $monthsLabel,
                'items_total'  => $itemsTotal,
                'discount'     => $discount,
                'total'        => $total,
                'items'        => $receiptItems,
                'student'      => [
                    'id'           => $enrollment->student->id,
                    'first_name'   => $enrollment->student->first_name,
                    'last_name'    => $enrollment->student->last_name,
                    'student_code' => $enrollment->student->student_code,
                ],
                'enrollment'   => [
                    'id'            => $enrollment->id,
                    'level'         => $enrollment->level?->name,
                    'section'       => $enrollment->section?->name,
                    'academic_year' => $enrollment->academicYear?->name,
                ],
                'guardian'     => $guardian ? [
                    'first_name' => $guardian->first_name,
                    'last_name'  => $guardian->last_name,
                    'phone'      => $guardian->phone,
                ] : null,
                'created_by'   => [
                    'id'   => $createdBy,
                    'code' => auth()->user()?->username ?? (string) $createdBy,
                    'name' => trim((auth()->user()?->first_name ?? '') . ' ' . (auth()->user()?->last_name ?? '')),
                ],
            ];
        });
    }

    public function monthLedger(int $enrollmentId): array
    {
        $payments = Payment::where('enrollment_id', $enrollmentId)
            ->whereNotNull('months')
            ->with(['paymentAllocations.studentFee', 'createdBy:id,first_name,last_name'])
            ->orderBy('payment_date')
            ->get();

        $ledger = [];
        foreach ($payments as $payment) {
            foreach ($payment->months ?? [] as $month) {
                $ledger[$month][] = [
                    'payment_id'   => $payment->id,
                    'payment_date' => $payment->payment_date->format('Y-m-d'),
                    'method'       => $payment->method,
                    'amount'       => $payment->amount,
                    'created_by'   => $payment->createdBy
                        ? $payment->createdBy->first_name . ' ' . $payment->createdBy->last_name
                        : null,
                    'items'        => $payment->paymentAllocations->map(fn ($a) => [
                        'description' => $a->studentFee?->description,
                        'amount'      => $a->amount_allocated,
                    ])->values(),
                ];
            }
        }
        ksort($ledger);
        return $ledger;
    }

    public function getAcademicYearMonths(AcademicYear $year): array
    {
        $startYear = (int) $year->start_date->format('Y');
        $months = [];
        foreach (self::SCHOOL_MONTHS as $m) {
            $y = $m >= 9 ? $startYear : $startYear + 1;
            $months[] = sprintf('%04d-%02d', $y, $m);
        }
        return $months;
    }

    public function getPaidMonths(int $enrollmentId): array
    {
        $paid = [];
        Payment::where('enrollment_id', $enrollmentId)
            ->whereNotNull('months')
            ->pluck('months')
            ->each(function ($months) use (&$paid) {
                foreach ($months as $m) {
                    $paid[] = $m;
                }
            });
        return array_values(array_unique($paid));
    }

    private function validateMonths(array $months, Enrollment $enrollment): void
    {
        $academicYear = $enrollment->academicYear;
        $allMonths = $this->getAcademicYearMonths($academicYear);
        $paidMonths = $this->getPaidMonths($enrollment->id);

        foreach ($months as $m) {
            if (!in_array($m, $allMonths, true)) {
                throw new \InvalidArgumentException(
                    'الشهر ' . $m . ' لا ينتمي إلى السنة الدراسية ' . $academicYear->name
                );
            }
        }

        foreach ($months as $m) {
            if (in_array($m, $paidMonths, true)) {
                $label = self::MONTH_NAMES_AR[substr($m, 5)] ?? $m;
                throw new \InvalidArgumentException('شهر ' . $label . ' تم دفعه مسبقاً');
            }
        }

        $indices = array_map(fn ($m) => array_search($m, $allMonths, true), $months);
        sort($indices);
        for ($i = 1; $i < count($indices); $i++) {
            if ($indices[$i] !== $indices[$i - 1] + 1) {
                throw new \InvalidArgumentException('يجب أن تكون الأشهر المختارة متتالية بدون فجوات');
            }
        }

        $unpaidMonths = array_values(array_filter(
            $allMonths,
            function ($m) use ($paidMonths) {
                return !in_array($m, $paidMonths, true);
            }
        ));

        if (empty($unpaidMonths)) {
            throw new \InvalidArgumentException('جميع أشهر السنة الدراسية مدفوعة');
        }

        if ($months[0] !== $unpaidMonths[0]) {
            $first = self::MONTH_NAMES_AR[substr($unpaidMonths[0], 5)] ?? $unpaidMonths[0];
            throw new \InvalidArgumentException('يجب البدء بدفع شهر ' . $first . ' قبل الشهر المختار');
        }
    }
}
