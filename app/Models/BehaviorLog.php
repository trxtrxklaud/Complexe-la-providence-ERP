<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviorLog extends Model
{
    protected $fillable = [
        'section_id',
        'enrollment_id',
        'student_id',
        'type',
        'note',
        'recorded_by',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public const TYPE_POSITIVE = 'positive';
    public const TYPE_WARNING = 'warning';
    public const TYPE_ALERT = 'alert';

    public static array $types = [
        self::TYPE_POSITIVE,
        self::TYPE_WARNING,
        self::TYPE_ALERT,
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
