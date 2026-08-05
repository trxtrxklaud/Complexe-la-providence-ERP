<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تنازل موثّق عن متبقّي رسم.
 *
 * لا يُنقص من amount_due ولا يُعدّ مدخولاً: لم يدخل الخزينة مليم واحد.
 * وظيفته إقفال الدَّين مع إبقاء أثره قابلاً للقراءة والمحاسبة.
 */
class FeeWaiver extends Model
{
    protected $fillable = [
        'student_fee_id',
        'amount',
        'reason',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function studentFee(): BelongsTo
    {
        return $this->belongsTo(StudentFee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * التنازلات السارية وحدها: الملغى لا يخصم من الدَّين.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
