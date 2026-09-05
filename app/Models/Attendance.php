<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجلّ حضور/غياب يومي لتلميذ في قسم. طبقة الجوال — لا يمسّ منطقاً مالياً. */
class Attendance extends Model
{
    protected $fillable = [
        'enrollment_id',
        'section_id',
        'date',
        'status',
        'note',
        'recorded_by',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
