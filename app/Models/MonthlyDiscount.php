<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyDiscount extends Model
{
    public const TYPE_FULL_WAIVER = 'full_waiver';
    public const TYPE_HUMANITARIAN_FIXED = 'humanitarian_fixed';
    public const TYPE_NORMAL_MONTHLY = 'normal_monthly';


    protected $fillable = [
        'enrollment_id',
        'academic_year_id',
        'discount_type',
        'monthly_amount',
        'fee_category',
        'start_month',
        'end_month',
        'reason',
        'notes',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'monthly_amount' => 'decimal:2',
        'cancelled_at'   => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function coversMonth(string $month): bool
    {
        if ($this->isCancelled()) {
            return false;
        }

        return $this->start_month <= $month && $month <= $this->end_month;
    }
}
