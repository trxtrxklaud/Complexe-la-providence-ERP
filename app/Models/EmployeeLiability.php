<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * استحقاق قديم لإطار (مستحقات الإطارات) مُدخل يدوياً.
 *
 * المتبقّي يُشتقّ دائماً من سطر الدفتر النقدي المرتبط بهذا الاستحقاق،
 * ويُعاد احتساب السطر من الرواتب والسلف المرتبطة عند الدفع أو الإلغاء.
 */
class EmployeeLiability extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * أنواع الاستحقاقات السارية: دَين أو سلفة غير مسددة. القاعدة التفصيلية
     * حسب تصنيف الإطار في StoreEmployeeLiabilityRequest؛ القيم القديمة
     * (salary/bonus/other) تظهر على سجلات سابقة فقط ولا تُقبل في إدخال جديد.
     */
    public const LIABILITY_TYPES = ['debt', 'advance'];

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

    public function cashTransactions(): MorphMany
    {
        return $this->morphMany(CashTransaction::class, 'source');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** المبلغ المدفوع فعلاً كما هو مثبت في الدفتر النقدي المركزي. */
    public function paid(): float
    {
        if ($this->isCancelled()) {
            return 0.0;
        }

        return round((float) $this->cashTransactions()
            ->where('category', CashTransaction::CATEGORY_OLD_LIABILITY_PAYMENT)
            ->whereNull('cancelled_at')
            ->sum('amount'), 2);
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
