<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    protected $fillable = [
        'payment_id',
        'student_fee_id',
        'manual_student_debt_id',
        'opening_balance_id',
        'club_monthly_fee_id',
        'amount_allocated',
    ];

    protected $casts = [
        'amount_allocated' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function studentFee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class);
    }

    public function manualStudentDebt(): BelongsTo
    {
        return $this->belongsTo(ManualStudentDebt::class, 'manual_student_debt_id');
    }

    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(OpeningBalance::class, 'opening_balance_id');
    }

    public function clubMonthlyFee(): BelongsTo
    {
        return $this->belongsTo(ClubMonthlyFee::class, 'club_monthly_fee_id');
    }
}
