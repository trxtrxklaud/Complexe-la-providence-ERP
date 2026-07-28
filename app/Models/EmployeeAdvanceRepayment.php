<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * ردّ واحد من سلفة إطار، بتاريخه ومبلغه وطريقته.
 */
class EmployeeAdvanceRepayment extends Model
{
    /** ردّ نقدي يدخل الصندوق. */
    public const METHOD_CASH = 'cash';

    /** خصم من راتب الشهر لا يمرّ بالصندوق. */
    public const METHOD_SALARY_DEDUCTION = 'salary_deduction';

    public const METHOD_LABELS = [
        self::METHOD_CASH             => 'نقداً',
        self::METHOD_SALARY_DEDUCTION => 'خصم من الراتب',
    ];

    protected $fillable = [
        'employee_advance_id',
        'employee_id',
        'academic_year_id',
        'amount',
        'repaid_at',
        'method',
        'salary_id',
        'notes',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'repaid_at'    => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function salary(): BelongsTo
    {
        return $this->belongsTo(Salary::class);
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

    /** الردّ النقدي وحده يُسقَط في الدفتر. */
    public function affectsCash(): bool
    {
        return $this->method === self::METHOD_CASH;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }

    public function scopeCash($query)
    {
        return $query->where('method', self::METHOD_CASH);
    }
}
