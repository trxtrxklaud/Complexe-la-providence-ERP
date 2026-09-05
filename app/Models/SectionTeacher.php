<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ربط المعلّم (employee) بقسم مع مادّة اختيارية. طبقة الجوال — لا يمسّ منطقاً مالياً. */
class SectionTeacher extends Model
{
    protected $table = 'section_teacher';

    protected $fillable = [
        'section_id',
        'employee_id',
        'subject',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
