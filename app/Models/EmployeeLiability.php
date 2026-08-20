<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * استحقاق قديم لإطار (مستحقات الإطارات) مُدخل يدوياً.
 *
 * المتبقّي يُشتقّ دائماً من الرواتب والسلف الفعلية المرتبطة بهذا الاستحقاق
 * (employee_liability_id على salaries / employee_advances) — لا يُخزَّن أي
 * رصيد مخبّأ. خلاصه يمرّ بالدفتر النقدي كبند مستقل (old_liability_payment).
 */
class EmployeeLiability extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const LIABILITY_TYPES = ['salary', 'advance', 'bonus', 'other'];

    protected $fillable = [
        'employee_id',
        'academic_year_id',
        'original_year_label',
        'liability_type',
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

    /** السنة الدراسية التي نُقل إليها الاستحقاق. */
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

    /** الرواتب التي خلّصت هذا الاستحقاق. */
    public function salaries(): HasMany
    {
        return $this->hasMany(Salary::class, 'employee_liability_id');
    }

    /** السلف التي خلّصت هذا الاستحقاق. */
    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class, 'employee_liability_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** مجموع ما دُفع فعلاً (رواتب وسلف غير ملغاة) مرتبطاً بهذا الاستحقاق. */
    public function paid(): float
    {
        if ($this->isCancelled()) {
            return 0.0;
        }

        $salaries = (float) $this->salaries()
            ->whereNull('cancelled_at')
            ->sum('amount');

        $advances = (float) $this->advances()
            ->whereNull('cancelled_at')
            ->sum('amount');

        return round($salaries + $advances, 2);
    }

    /** المتبقّي — يُشتقّ من المدفوعات الفعلية المرتبطة لا من عمود مخزّن. */
    public function outstanding(): float
    {
        if ($this->isCancelled()) {
            return 0.0;
        }

        return round(max(0, (float) $this->original_amount - $this->paid()), 2);
    }

    /** الأسطر السارية فقط. */
    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }
}
