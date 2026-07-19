<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $fillable = [
        'student_code',
        'first_name',
        'last_name',
        'dob',
        'gender',
        'photo',
        'notes',
        'status',
    ];

    protected $casts = [
        'dob' => 'date',
    ];

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'guardian_student')
            ->withPivot('relationship', 'is_primary_contact')
            ->withTimestamps();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function clubSubscriptions(): HasMany
    {
        return $this->hasMany(ClubSubscription::class);
    }
}
