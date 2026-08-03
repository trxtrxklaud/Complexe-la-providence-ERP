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
 *   advance_deduction ما خُصِم من تسبقات قائمة وأقساط سلف
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

    /**
     * أقساط السلف التي خُصمت بهذا الراتب.
     *
     * التسبقة تُختم بـ settled_by_salary_id لأنّها تُخصم كاملة مرّة واحدة؛
     * أمّا السلفة فتُردّ على أقساط من رواتب شتّى، فلا يمكن ختمها براتب واحد.
     * الرابط بينهما هو سطر الردّ نفسه، وهذه العلاقة تكشفه.
     *
     * لا تُستثنى الملغاة هنا عمداً: قسيمة راتب ملغى يجب أن تُري ما أُلغي معه.
     * من أراد القائم وحده فليستعمل activeRepayments().
     */
    public function repayments(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceRepayment::class, 'salary_id');
    }

    /** أقساط السلف السارية دون الملغاة — وهي وحدها التي تُنقِص الدَين فعلاً. */
    public function activeRepayments(): HasMany
    {
        return $this->repayments()->whereNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
