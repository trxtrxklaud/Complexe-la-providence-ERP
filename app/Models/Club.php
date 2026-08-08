<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Club extends Model
{
    protected $fillable = [
        'name',
        'description',
        'fee_category_id',
        'monthly_fee',
        'is_active',
    ];

    protected $casts = [
        'monthly_fee' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ClubSubscription::class);
    }

    public function levels(): BelongsToMany
    {
        return $this->belongsToMany(Level::class, 'club_levels');
    }
}
