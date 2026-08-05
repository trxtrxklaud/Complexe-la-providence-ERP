<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تخفيض سنوي على معاليم تسجيل التلميذ.
 *
 * سعر مخفّض لا دَين: يُنقص أصل ما يُطالَب به التلميذ للسنة كلّها، مرّة واحدة،
 * ويسري على السنة الدراسية بأكملها ولا يتغيّر شهراً بشهر. سقفه 20% من مجموع المعاليم.
 *
 * لا يُكتب في الدفتر النقدي: لم يدخل مليم ولم يخرج. المتخلّد = (المعاليم − التخفيض) − المقبوض.
 * يُلغى ولا يُحذف؛ فيبقى أثره وأثر إلغائه مقروءاً كبقية وثائق المنصة.
 */
class EnrollmentDiscount extends Model
{
    protected $fillable = [
        'enrollment_id',
        'academic_year_id',
        'amount',
        'percentage',
        'reason',
        'applied_date',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'percentage'   => 'decimal:2',
        'applied_date' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
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
     * التخفيضات السارية وحدها: الملغى لا يُنقص من المستحقّ.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /**
     * مرادف صريح لـ active — يقرأ أوضح في سياق «غير الملغاة».
     */
    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /**
     * تخفيضات سنة دراسية بعينها.
     */
    public function scopeForYear(Builder $query, int $academicYearId): Builder
    {
        return $query->where('academic_year_id', $academicYearId);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * المبلغ الفعّال المطروح من المستحقّ: صفر إن كان التخفيض ملغى.
     * مصدر الحقيقة الوحيد الذي يجب أن تقرأ منه الحسابات والتقارير.
     */
    public function getEffectiveAmount(): float
    {
        return $this->isCancelled() ? 0.0 : round((float) $this->amount, 2);
    }

    /**
     * هل يخصّ هذا التخفيض تسجيلاً بعينه؟ حارس سلامة قبل تطبيقه على حساب.
     */
    public function isForEnrollment(int $enrollmentId): bool
    {
        return (int) $this->enrollment_id === $enrollmentId;
    }
}
