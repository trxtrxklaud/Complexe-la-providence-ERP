<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * دَين قديم لإطار مُدخل يدوياً كـ رصيد افتتاحي تاريخي.
 *
 * قيد إداري تاريخي بحت: لا ينشئ رواتب، ولا سلفاً، ولا قيوداً نقدية،
 * ولا يغيّر دخل التشغيل أو صافي الدخل. الأثر النقدي الوحيد ينشأ حصراً
 * عند تحصيل الدين نقداً عبر CashTransaction داخلة في الخزينة.
 */
class OldEmployeeDebt extends Model
{
    protected $table = 'employee_opening_debts';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const DEBT_TYPES = ['debt', 'other'];

    protected $fillable = [
        'employee_id',
        'academic_year_id',
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

    public function collections(): HasMany
    {
        return $this->hasMany(OldEmployeeDebtCollection::class, 'employee_opening_debt_id');
    }

    public function cashTransactions(): MorphMany
    {
        return $this->morphMany(CashTransaction::class, 'source');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function collectedAmount(): float
    {
        return round((float) $this->collections()->sum('amount'), 2);
    }

    public function outstandingAmount(): float
    {
        if ($this->isCancelled()) {
            return 0.0;
        }

        return round(max(0, (float) $this->original_amount - $this->collectedAmount()), 2);
    }

    public function hasCollections(): bool
    {
        return $this->collectedAmount() > 0;
    }

    public function syncStatus(): void
    {
        if ($this->isCancelled()) {
            return;
        }

        $collected = $this->collectedAmount();
        $original = (float) $this->original_amount;

        $newStatus = match (true) {
            $collected >= $original && $original > 0 => self::STATUS_PAID,
            $collected > 0 => self::STATUS_PARTIAL,
            default => self::STATUS_PENDING,
        };

        if ($this->status !== $newStatus) {
            $this->update(['status' => $newStatus]);
        }
    }

    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }
}
