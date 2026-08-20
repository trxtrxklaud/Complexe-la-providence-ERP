<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ساعات العمل اليومية للمعلم الساعي.
 *
 * يوم واحد لكل معلم (UNIQUE employee_id + work_date) — الصف يُستبدل عند
 * إعادة التسجيل. الغياب صفّ بموجب الساعة 0 والنوع absence، ويُخصم منطقياً
 * في EmployeeHoursService لا في الجدول.
 */
class EmployeeDailyHour extends Model
{
    public const NOTE_TYPES = ['normal', 'absence', 'replacement', 'extra'];

    protected $fillable = [
        'employee_id',
        'work_date',
        'hours',
        'note_type',
        'notes',
        'created_by',
    ];

    protected $casts = [
        // date:Y-m-d — يُخزَّن تاريخ خالص لا طابع زمني، فيبقى البحث بـ work_date دقيقاً.
        'work_date' => 'date:Y-m-d',
        'hours' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}