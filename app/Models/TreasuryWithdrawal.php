<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TreasuryWithdrawal extends Model
{
    protected $fillable = [
        'amount',
        'withdrawn_at',
        'type',
        'note',
        'academic_year_id',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'withdrawn_at' => 'date',
        'cancelled_at' => 'datetime',
    ];

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

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }
}
