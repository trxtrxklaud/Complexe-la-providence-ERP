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
        'guardian_first_name',
        'guardian_last_name',
        'mother_name',
        'guardian_phone',
        // العمود موجود في القاعدة ويُقرأ في الوصل (CollectionService::resolveGuardianPayload)
        // ويُتحقَّق منه في StudentController@store، لكنه كان ساقطاً من هنا فيُهمَل
        // بصمت عند الإسناد الجَماعي. الكتابة تبقى محدودة بما تسمح به قواعد التحقق.
        'guardian_email',
        'mother_phone',
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

    /** الأرصدة الافتتاحية المحمولة على هذا التلميذ في السنوات الجديدة. */
    public function openingBalances(): HasMany
    {
        return $this->hasMany(OpeningBalance::class);
    }
}
