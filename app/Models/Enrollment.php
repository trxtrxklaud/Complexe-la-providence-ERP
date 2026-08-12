<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'student_id', 'academic_year_id', 'level_id',
        'section_id', 'enrollment_date', 'status',
        'previous_enrollment_id', 'notes',
    ];

    protected $casts = ['enrollment_date' => 'date'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
    public function previousEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'previous_enrollment_id');
    }
    public function studentFees(): HasMany
    {
        return $this->hasMany(StudentFee::class);
    }
    public function clubSubscriptions(): HasMany
    {
        return $this->hasMany(ClubSubscription::class);
    }
    public function discounts(): HasMany
    {
        return $this->hasMany(EnrollmentDiscount::class);
    }
    public function monthlyDiscounts(): HasMany
    {
        return $this->hasMany(MonthlyDiscount::class);
    }


    /**
     * التخفيض السنوي السارِي لهذا التسجيل — واحد على الأكثر لكل سنة دراسية.
     * إن لم تُمرّر السنة استُعملت سنة التسجيل نفسها.
     */
    public function activeDiscount(?int $academicYearId = null): ?EnrollmentDiscount
    {
        return $this->discounts()
            ->whereNull('cancelled_at')
            ->where('academic_year_id', $academicYearId ?? $this->academic_year_id)
            ->latest('id')
            ->first();
    }
    // payments() حُذفت — استخدم $student->payments() مباشرة
}
