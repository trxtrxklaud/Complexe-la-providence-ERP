<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    /** تصنيفات الإطارات الستة. */
    public const STAFF_TYPES = [
        'supervisor' => 'قيم / قيمة',
        'worker' => 'عامل',
        'manager' => 'مدير / مديرة',
        'club_animator' => 'منشط / منشطة نوادي',
        'hourly_teacher' => 'معلم / معلمة بالساعة',
        'monthly_teacher' => 'معلم / معلمة بالشهر',
    ];

    /** نوعا الأجر. */
    public const SALARY_TYPES = [
        'monthly' => 'شهري ثابت',
        'hourly' => 'بالساعة',
    ];

    protected $fillable = [
        'first_name', 'last_name', 'phone', 'email',
        'job_title', 'staff_type', 'salary_type', 'hourly_rate', 'monthly_salary',
        'default_salary', 'is_active', 'hire_date', 'notes',
    ];

    protected $casts = [
        'default_salary' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'monthly_salary' => 'decimal:2',
        'is_active' => 'boolean',
        'hire_date' => 'date:Y-m-d',
    ];

    public function salaries(): HasMany
    {
        return $this->hasMany(Salary::class);
    }

    /** سلف الإطار (تسبقة الرواتب). */
    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceRepayment::class);
    }

    /** ساعات العمل اليومية للمعلم الساعي. */
    public function dailyHours(): HasMany
    {
        return $this->hasMany(EmployeeDailyHour::class);
    }

    /** ديون الإطار القديمة (أرصدة افتتاحية تاريخية). */
    public function openingDebts(): HasMany
    {
        return $this->hasMany(OldEmployeeDebt::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    protected static function booted(): void
    {
        // backfill: monthly_salary يُملأ من default_salary إن لم يُرسل صريحاً
        // وكانت له قيمة فعلية (أكبر من صفر).
        static::creating(function (Employee $employee) {
            if (empty($employee->monthly_salary) && (float) ($employee->default_salary ?? 0) > 0) {
                $employee->monthly_salary = $employee->default_salary;
            }
        });
    }
}
