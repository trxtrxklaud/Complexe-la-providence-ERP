<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * دفعة تحصيل لدين إطار قديم.
 *
 * كل دفعة مستند مالي مستقل يسقط حركة نقدية وحيدة في cash_transactions.
 */
class OldEmployeeDebtCollection extends Model
{
    protected $table = 'employee_opening_debt_collections';

    protected $fillable = [
        'employee_opening_debt_id',
        'amount',
        'payment_date',
        'method',
        'notes',
        'collected_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function debt(): BelongsTo
    {
        return $this->belongsTo(OldEmployeeDebt::class, 'employee_opening_debt_id');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function cashTransaction(): MorphOne
    {
        return $this->morphOne(CashTransaction::class, 'source');
    }
}