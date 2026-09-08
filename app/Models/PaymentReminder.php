<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سِجلّ تنبيه دفع واحد أُرسل (أو فشل إرساله) لوليّ أمر.
 */
class PaymentReminder extends Model
{
    public const TYPE_FIRST = 'first';

    public const TYPE_MID = 'mid';

    public const TYPE_FINAL = 'final';

    public const TYPES = [self::TYPE_FIRST, self::TYPE_MID, self::TYPE_FINAL];

    protected $fillable = [
        'student_id',
        'enrollment_id',
        'academic_year_id',
        'fee_month',
        'type',
        'phone',
        'sent',
        'failure_reason',
        'sent_at',
    ];

    protected $casts = [
        'sent' => 'boolean',
        'sent_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
