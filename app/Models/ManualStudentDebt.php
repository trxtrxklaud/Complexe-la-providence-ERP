<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دَين قديم مُدخل يدوياً (بيانات خارجية بلا رسم سابق في النظام).
 *
 * المتبقّي يُشتقّ دائماً من التوزيعات الفعلية للرسم الجسر (source_student_fee_id)
 * ولا يُخزَّن أي رصيد مخبّأ. الدفعات التي تخصّص عليه تمرّ بمسار
 * متخلّدات السنوات السابقة نفسه (prior_year_debt في الدفتر النقدي).
 */
class ManualStudentDebt extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const DEBT_TYPES = ['tuition', 'registration', 'club', 'other'];

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'source_student_fee_id',
        'original_year_label',
        'debt_type',
        'description',
        'original_amount',
        'notes',
        'status',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'original_amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** السنة الدراسية التي نُقل إليها الدَّين. */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** الرسم الجسر تحت تسجيل سنة سابقة — إليه تُشير توزيعات الدفع. */
    public function sourceStudentFee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class, 'source_student_fee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** التوزيعات المالية المخصصة لهذا الدين تحديداً. */
    public function paymentAllocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'manual_student_debt_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** مجموع ما حُصّل فعلاً من الدفعات المخصصة لهذا الدين وغير الملغاة. */
    public function collected(): float
    {
        return round((float) $this->paymentAllocations()
            ->whereHas('payment', fn ($q) => $q->whereNull('cancelled_at'))
            ->sum('amount_allocated'), 2);
    }

    /** المتبقّي على الدَّين — يُشتقّ من التوزيعات الفعلية لا من عمود مخزّن. */
    public function outstanding(): float
    {
        if ($this->isCancelled()) {
            return 0.0;
        }

        return round(max(0, (float) $this->original_amount - $this->collected()), 2);
    }

    /** الأسطر السارية فقط. */
    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }
}
