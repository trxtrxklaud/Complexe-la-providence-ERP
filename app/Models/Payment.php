<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'student_id',
        'enrollment_id',
        'months',
        'amount',
        'payment_date',
        'method',
        'reference',
        'idempotency_key',
        'notes',
        'meta',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
        'edited_by',
        'edited_at',
        'old_amount',
        'new_amount',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'old_amount'   => 'decimal:2',
        'new_amount'   => 'decimal:2',
        'payment_date' => 'date',
        'months'       => 'array',
        'meta'         => 'array',
        'cancelled_at' => 'datetime',
        'edited_at'    => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function getExceptionalDiscountAmountAttribute(): float
    {
        return (float) ($this->meta['exceptional_discount'] ?? $this->meta['discount'] ?? 0.0);
    }
}
