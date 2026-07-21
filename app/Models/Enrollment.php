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
    // payments() حُذفت — استخدم $student->payments() مباشرة
}
