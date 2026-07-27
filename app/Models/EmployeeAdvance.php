<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EmployeeAdvance extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SETTLED = 'settled';

    protected $fillable = [
        'employee_id',
        'academic_year_id',
        'amount',
        'settled_amount',
        'advance_date',
        'method',
        'reason',
        'notes',
        'status',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'settled_amount' => 'decimal:2',
        'advance_date'   => 'date',
        'cancelled_at'   => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function cashTransactions(): MorphMany
    {
        return $this->morphMany(CashTransaction::class, 'source');
    }

    /** المتبقّي غير المسدَّد من السلفة. */
    public function getRemainingAttribute(): string
    {
        return number_format((float) $this->amount - (float) $this->settled_amount, 2, '.', '');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
