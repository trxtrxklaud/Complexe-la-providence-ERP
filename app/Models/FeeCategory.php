<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeCategory extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'is_recurring',
    ];

    public function feePlans(): HasMany
    {
        return $this->hasMany(FeePlan::class);
    }

    public function clubs(): HasMany
    {
        return $this->hasMany(Club::class);
    }
}
