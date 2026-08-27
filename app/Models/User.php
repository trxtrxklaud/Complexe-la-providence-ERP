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
        'first_name', 'last_name', 'username',
        'email', 'phone', 'password',
        'role_id', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password'  => 'hashed',
        'is_active' => 'boolean',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    public function scopeWithRoleAndPermissions($query)
    {
        return $query->with(['role.permissions', 'permissionOverrides.permission']);
    }

    public function createdPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'created_by');
    }

    /**
     * Check if user has effective permission for the given permission name.
     * Precedence:
     * 1. Direct 'deny' override => false (highest priority)
     * 2. Direct 'grant' override => true
     * 3. Role permission => true
     * 4. Super role check => true
     * 5. Default => false
     */
    public function hasPermissionTo(string $permissionName): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $this->loadMissing([
            'role.permissions',
            'permissionOverrides.permission',
        ]);

        $override = $this->permissionOverrides
            ->firstWhere('permission.name', $permissionName);

        if ($override?->effect === UserPermissionOverride::EFFECT_DENY) {
            return false;
        }

        if ($override?->effect === UserPermissionOverride::EFFECT_GRANT) {
            return true;
        }

        $role = $this->role;

        if ($role?->permissions->contains('name', $permissionName)) {
            return true;
        }

        $superRoles = (array) config('permissions.super_roles', []);

        if ($role && in_array($role->name, $superRoles, true)) {
            return true;
        }

        return false;
    }

    /**
     * Get list of effective permission names for the user.
     *
     * @return array<int, string>
     */
    public function getEffectivePermissionNames(): array
    {
        if (! $this->is_active) {
            return [];
        }

        $this->loadMissing([
            'role.permissions',
            'permissionOverrides.permission',
        ]);

        $rolePermissions = $this->role?->permissions->pluck('name')->toArray() ?? [];

        $superRoles = (array) config('permissions.super_roles', []);
        if ($this->role && in_array($this->role->name, $superRoles, true)) {
            $rolePermissions = Permission::pluck('name')->toArray();
        }

        $effective = array_fill_keys($rolePermissions, true);

        foreach ($this->permissionOverrides as $override) {
            $permName = $override->permission?->name;
            if (! $permName) {
                continue;
            }

            if ($override->effect === UserPermissionOverride::EFFECT_DENY) {
                unset($effective[$permName]);
            } elseif ($override->effect === UserPermissionOverride::EFFECT_GRANT) {
                $effective[$permName] = true;
            }
        }

        return array_values(array_keys($effective));
    }
}
