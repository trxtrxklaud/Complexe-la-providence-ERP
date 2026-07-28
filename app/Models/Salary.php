<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الراتب.
 *
 * ثلاثة أرقام لا يجوز خلطها:
 *   gross_amount      الراتب الخام المستحقّ (يظهر في قسيمة الأجر)
 *   advance_deduction ما خُصِم من تسبقات قائمة
 *   amount            الصافي المدفوع نقداً — وهو وحده ما يُسقَط في الدفتر
 *
 * إسقاط الخام في الدفتر مع إسقاط التسبقة يوم منحها يُخرج المال مرّتين،
 * وهذا سبب وجود هذه التفرقة.
 */
class Salary extends Model
{
    protected $fillable = [
        'employee_id', 'academic_year_id',
        'gross_amount', 'advance_deduction', 'amount',
        'period_from', 'period_to', 'paid_at',
        'method', 'reference', 'notes', 'created_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'advance_deduction' => 'decimal:2',
        'amount' => 'decimal:2',
        'period_from' => 'date',
        'period_to' => 'date',
        'paid_at' => 'date',
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

    /** التسبقات التي خُلّصت بهذا الراتب. */
    public function settledAdvances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class, 'settled_by_salary_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
