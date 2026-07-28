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
}
