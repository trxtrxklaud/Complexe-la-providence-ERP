<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'first_name', 'last_name', 'phone', 'email',
        'job_title', 'default_salary', 'is_active', 'notes',
    ];

    protected $casts = [
        'default_salary' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function salaries(): HasMany
    {
        return $this->hasMany(Salary::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
