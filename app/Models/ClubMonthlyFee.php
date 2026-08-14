<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClubMonthlyFee extends Model
{
    public const STATUS_UNPAID  = 'unpaid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID    = 'paid';

    public const STATUS_LABELS = [
        self::STATUS_UNPAID  => 'في انتظار الدفع',
        self::STATUS_PARTIAL => 'في انتظار الدفع',
        self::STATUS_PAID    => 'خلاص كامل',
    ];

    public const STATUS_COLORS = [
        self::STATUS_UNPAID  => 'orange',
        self::STATUS_PARTIAL => 'orange',
        self::STATUS_PAID    => 'green',
    ];

    protected $fillable = [
        'student_id',
        'club_id',
        'academic_year_id',
        'enrollment_id',
        'club_subscription_id',
        'month',
        'amount_due',
        'amount_paid',
        'status',
        'paid_at',
        'method',
        'reference',
        'notes',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'amount_due'   => 'decimal:2',
        'amount_paid'  => 'decimal:2',
        'paid_at'      => 'date',
        'cancelled_at' => 'datetime',
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

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ClubSubscription::class, 'club_subscription_id');
    }

    public function studentFee(): HasOne
    {
        return $this->hasOne(StudentFee::class, 'club_monthly_fee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
