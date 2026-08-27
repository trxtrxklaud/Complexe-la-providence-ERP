<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجل عملية واحدة في نظام التدقيق. سجلّ للقراءة فقط عمليّاً: يُنشأ عبر
 * {@see \App\Services\AuditService} ولا يُعدَّل ولا يُحذف من أيّ مسار.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_name',
        'action',
        'model_type',
        'model_id',
        'description',
        'metadata',
        'ip_address',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
