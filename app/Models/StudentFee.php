<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentFee extends Model
{
    protected $fillable = [
        'enrollment_id',
        'fee_plan_id',
        'fee_type_id',
        'description',
        'amount_due',
        'due_date',
        'status',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function feePlan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class);
    }

    /**
     * نوع الرسم كما اختاره المستخلِص — مصدر تصنيف بند المداخيل في الدفتر.
     */
    public function feeType(): BelongsTo
    {
        return $this->belongsTo(FeeType::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(FeeWaiver::class);
    }

    /**
     * ما خُصّص لهذا الرسم من دفعات غير ملغاة.
     */
    public function allocatedAmount(): float
    {
        return round((float) $this->paymentAllocations()
            ->whereHas('payment', fn ($query) => $query->whereNull('cancelled_at'))
            ->sum('amount_allocated'), 2);
    }

    /**
     * ما تُنوزِل عنه بتنازلات سارية (الملغاة لا تُحتسب).
     */
    public function waivedAmount(): float
    {
        return round((float) $this->waivers()->whereNull('cancelled_at')->sum('amount'), 2);
    }

    /**
     * الدَّين المتبقّي على هذا الرسم — التعريف الوحيد في المنصة.
     *
     * المستحقّ ناقصاً المقبوض ناقصاً المتنازل عنه، ولا ينزل تحت الصفر:
     * الدفع الزائد ليس دَيناً سالباً على المدرسة في هذا السياق.
     */
    public function outstanding(): float
    {
        return round(max(0.0, (float) $this->amount_due - $this->allocatedAmount() - $this->waivedAmount()), 2);
    }
}
