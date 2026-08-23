<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * سطر في الدفتر النقدي المركزي.
 *
 * كل التقارير المالية (المداخيل، المصاريف، الخزينة، الدخل الصافي)
 * تُبنى حصرياً على هذا الجدول، لضمان تطابق الأرقام بين كل الشاشات.
 */
class CashTransaction extends Model
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    // بنود المداخيل
    public const CATEGORY_REGISTRATION_FEE = 'registration_fee';   // معاليم التسجيل

    public const CATEGORY_MONTHLY_FEE = 'monthly_fee';        // معاليم الأشهر

    public const CATEGORY_CLUB_FEE = 'club_fee';           // معاليم النوادي

    public const CATEGORY_INSTALLMENT = 'installment';        // خلاص أقساط

    public const CATEGORY_PRODUCT_SALE = 'product_sale';       // بيع المنتجات

    public const CATEGORY_ADVANCE_REPAYMENT = 'advance_repayment';  // خلاص سلفة

    public const CATEGORY_OTHER_INCOME = 'other_income';       // مداخيل أخرى

    // تحصيل ديون السنوات السابقة: قبض نقدي يعقّب صندوق المدرسة،
    // لكنه ليس مدخولاً للسنة الحالية — لا يدخل في الدخل الصافي ولا في المداخيل.
    public const CATEGORY_PRIOR_YEAR_DEBT = 'prior_year_debt';    // تحصيل متخلّدات سنوات سابقة

    // بنود المصاريف
    public const CATEGORY_SALARY = 'salary';             // الأجور

    public const CATEGORY_EMPLOYEE_ADVANCE = 'employee_advance';   // سلفة

    public const CATEGORY_EXPENSE = 'expense';            // المصاريف

    // خلاص مستحقّات الإطارات القديمة (legacy): خروج نقدي — لا يُستخدم في الحسابات الجديدة
    public const CATEGORY_OLD_LIABILITY_PAYMENT = 'old_liability_payment';  // legacy — خلاص مستحقّات قديمة (out)

    // تحصيل دين عامل/إطار قديم مستحق للمؤسسة: قبض نقدي داخل، لا مدخول للسنة الحالية
    public const CATEGORY_OLD_LIABILITY_COLLECTION = 'old_liability_collection';  // تحصيل دين عامل قديم

    // حركة مستقلة: لا تدخل في الدخل الصافي
    public const CATEGORY_WITHDRAWAL = 'withdrawal';         // سحب من الخزينة

    public const INCOME_CATEGORIES = [
        self::CATEGORY_REGISTRATION_FEE,
        self::CATEGORY_MONTHLY_FEE,
        self::CATEGORY_CLUB_FEE,
        self::CATEGORY_INSTALLMENT,
        self::CATEGORY_PRODUCT_SALE,
        self::CATEGORY_ADVANCE_REPAYMENT,
        self::CATEGORY_OTHER_INCOME,
    ];

    /** بند دين التلميذ القديم فقط (للتلميذ). */
    public const PRIOR_YEAR_DEBT_CATEGORIES = [
        self::CATEGORY_PRIOR_YEAR_DEBT,
    ];

    /** كل بنود تحصيل الديون القديمة الداخلة (تلميذ + عامل): نقد داخل (IN) لا مدخول. */
    public const OLD_DEBT_COLLECTION_CATEGORIES = [
        self::CATEGORY_PRIOR_YEAR_DEBT,
        self::CATEGORY_OLD_LIABILITY_COLLECTION,
    ];

    /** بنود صرف مستحقّات الإطارات القديمة: نقد خارج (legacy OUT) لا يدخل في المقبوضات ولا في مصاريف السنة. */
    public const OLD_LIABILITY_PAYMENT_CATEGORIES = [
        self::CATEGORY_OLD_LIABILITY_PAYMENT,
    ];

    /**
     * كل بنود الدخول النقدي (للملفّات التي تعرض قبض التلميذ بالكامل):
     * المداخيل + تحصيل ديون السنوات السابقة (تلميذ + عامل).
     */
    public const CASH_INFLOW_CATEGORIES = [
        ...self::INCOME_CATEGORIES,
        ...self::OLD_DEBT_COLLECTION_CATEGORIES,
    ];

    public const EXPENSE_CATEGORIES = [
        self::CATEGORY_SALARY,
        self::CATEGORY_EMPLOYEE_ADVANCE,
        self::CATEGORY_EXPENSE,
    ];

    /** التسميات العربية المعتمدة في التقارير. */
    public const CATEGORY_LABELS = [
        self::CATEGORY_REGISTRATION_FEE => 'معاليم التسجيل',
        self::CATEGORY_MONTHLY_FEE => 'معاليم الأشهر',
        self::CATEGORY_CLUB_FEE => 'معاليم النوادي',
        self::CATEGORY_INSTALLMENT => 'خلاص أقساط',
        self::CATEGORY_PRODUCT_SALE => 'بيع المنتجات',
        self::CATEGORY_ADVANCE_REPAYMENT => 'خلاص سلفة',
        self::CATEGORY_OTHER_INCOME => 'مداخيل أخرى',
        self::CATEGORY_PRIOR_YEAR_DEBT => 'تحصيل متخلّدات سنوات سابقة',
        self::CATEGORY_OLD_LIABILITY_COLLECTION => 'تحصيل دين عامل قديم',
        self::CATEGORY_SALARY => 'الأجور',
        self::CATEGORY_EMPLOYEE_ADVANCE => 'سلفة',
        self::CATEGORY_EXPENSE => 'المصاريف',
        self::CATEGORY_OLD_LIABILITY_PAYMENT => 'خلاص مستحقّات قديمة (legacy)',
        self::CATEGORY_WITHDRAWAL => 'سحب من الخزينة',
    ];

    protected $fillable = [
        'transaction_date',
        'direction',
        'category',
        'amount',
        'source_type',
        'source_id',
        'academic_year_id',
        'description',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'cancelled_at' => 'datetime',
    ];

    /** المستند المصدري: Payment | Salary | Expense | EmployeeAdvance | TreasuryWithdrawal */
    public function source(): MorphTo
    {
        return $this->morphTo();
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

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function getLabelAttribute(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? $this->category;
    }

    /** المبلغ بإشارة: موجب للداخل وسالب للخارج. */
    public function getSignedAmountAttribute(): string
    {
        $value = (float) $this->amount;

        return number_format($this->direction === self::DIRECTION_IN ? $value : -$value, 2, '.', '');
    }

    /** الأسطر السارية فقط: تستثني كل ما تمّ إلغاؤه. */
    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }

    public function scopeIncome($query)
    {
        return $query->whereIn('category', self::INCOME_CATEGORIES);
    }

    public function scopeExpense($query)
    {
        return $query->whereIn('category', self::EXPENSE_CATEGORIES);
    }

    public function scopePriorYearDebt($query)
    {
        return $query->whereIn('category', self::PRIOR_YEAR_DEBT_CATEGORIES);
    }

    public function scopeCashInflow($query)
    {
        return $query->whereIn('category', self::CASH_INFLOW_CATEGORIES);
    }

    public function scopeWithdrawals($query)
    {
        return $query->where('category', self::CATEGORY_WITHDRAWAL);
    }

    public function scopeBetweenDates($query, ?string $from, ?string $to)
    {
        if ($from !== null) {
            $query->whereDate('transaction_date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('transaction_date', '<=', $to);
        }

        return $query;
    }
}
