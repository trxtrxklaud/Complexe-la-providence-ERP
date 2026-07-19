<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'username',
        'email',
        'phone',
        'password',
        'role_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Recommended usage in controllers/services:
     * User::withRoleAndPermissions()->find($id)
     *
     * The CheckPermission middleware and frontend rely on $user->role->permissions being loaded.
     */
    public function scopeWithRoleAndPermissions($query)
    {
        return $query->with(['role.permissions']);
    }

    /**
     * Scope to eager load role + permissions (recommended usage).
     */

    public function createdPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'created_by');
    }
}
