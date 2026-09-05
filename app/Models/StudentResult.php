<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نتيجة/عدد تلميذ في مادّة ضمن فترة. لا تظهر للوليّ إلا بعد النشر (published_at). طبقة الجوال. */
class StudentResult extends Model
{
    protected $fillable = [
        'enrollment_id',
        'subject',
        'term',
        'score',
        'max_score',
        'published_at',
        'entered_by',
    ];

    protected $casts = [
        'score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'published_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }
}
