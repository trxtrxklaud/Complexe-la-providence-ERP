<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermissionOverride extends Model
{
    public const EFFECT_GRANT = 'grant';
    public const EFFECT_DENY  = 'deny';

    public const VALID_EFFECTS = [
        self::EFFECT_GRANT,
        self::EFFECT_DENY,
    ];

    protected $fillable = [
        'user_id',
        'permission_id',
        'effect',
        'created_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
