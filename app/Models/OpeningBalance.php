<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رصيد افتتاحي (Opening Balance) منقول من سنة دراسية سابقة إلى سنة جديدة.
 *
 * لا يُنشأ إلا بإقفال سنة (OpeningBalanceService::closeYear)، ويُحتفظ دائماً
 * بمصدر الدَّين (الرسم الأصلي) وسنته، فلا يُحذف الدَّين القديم ولا يُعامل
 * تحصيله كمدخول للسنة الجديدة.
 *
 * مقدار الرصيد المستحق يُشتقّ من الرسم الأصلي نفسه (الدفعات غير الملغاة
 * ناقصاً التنازلات السارية)، فيبقى صحيحاً حتى بعد أجزاء من السداد.
 */
class OpeningBalance extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'student_id',
        'source_enrollment_id',
        'source_student_fee_id',
        'academic_year_id',
        'amount',
        'status',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** التسجيل الأصلي في السنة التي نشأ فيها الدَّين. */
    public function sourceEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'source_enrollment_id');
    }

    /** الرسم الأصلي — مصدر الدَّين الوحيد الذي لا يُستبدل. */
    public function sourceStudentFee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'source_student_fee_id');
    }

    /** السنة الدراسية التي نُقل إليها الرصيد. */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** التوزيعات المالية المخصصة لهذا الرصيد الافتتاحي تحديداً. */
    public function paymentAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'opening_balance_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** مجموع ما حُصّل فعلاً من الدفعات المخصصة لهذا الرصيد وغير الملغاة. */
    public function collected(): float
    {
        return round((float) $this->paymentAllocations()
            ->whereHas('payment', fn ($q) => $q->whereNull('cancelled_at'))
            ->sum('amount_allocated'), 2);
    }

    /**
     * المتبقّي على هذا الرصيد الافتتاحي — يُشتقّ من الرسم الأصلي حصراً
     * حتى لا ينفصل رقم الرصيد عن رقم الرسم.
     */
    public function outstanding(): float
    {
        return $this->sourceStudentFee?->outstanding() ?? 0.0;
    }

    /** الأسطر السارية فقط. */
    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }
}
