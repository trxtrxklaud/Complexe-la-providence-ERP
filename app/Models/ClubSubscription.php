<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubSubscription extends Model
{
    protected $fillable = [
        'student_id',
        'club_id',
        'academic_year_id',
        'enrollment_id',
        'start_date',
        'end_date',
        'status',
        'monthly_fee_override',
        'excluded_at',
        'excluded_by',
        'exclusion_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'excluded_at' => 'datetime',
        'monthly_fee_override' => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function excludedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    public function monthlyDiscounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ClubMonthlyDiscount::class, 'club_subscription_id');
    }
}
