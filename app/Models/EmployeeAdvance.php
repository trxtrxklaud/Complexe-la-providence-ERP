<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EmployeeAdvance extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_SETTLED = 'settled';

    /** تسبقة: تُخصم كاملة من راتب الشهر نفسه. */
    public const TYPE_ADVANCE = 'advance';

    /** سلفة: تُردّ على مهل دفعات. */
    public const TYPE_LOAN = 'loan';

    public const TYPE_LABELS = [
        self::TYPE_ADVANCE => 'تسبقة',
        self::TYPE_LOAN    => 'سلفة',
    ];

    protected $fillable = [
        'employee_id',
        'academic_year_id',
        'type',
        'amount',
        'settled_amount',
        'advance_date',
        'method',
        'reason',
        'notes',
        'status',
        'is_opening',
        'settled_by_salary_id',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'settled_amount' => 'decimal:2',
        'advance_date'   => 'date',
        'is_opening'     => 'boolean',
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

    /** القائم فعلاً: لم يُخلّص بعد كاملاً. */
    public function scopeOutstanding($query)
    {
        return $query->whereNull('cancelled_at')
            ->whereColumn('settled_amount', '<', 'amount');
    }

    public function scopeAdvances($query)
    {
        return $query->where('type', self::TYPE_ADVANCE);
    }

    public function scopeLoans($query)
    {
        return $query->where('type', self::TYPE_LOAN);
    }
}
