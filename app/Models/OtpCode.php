<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** رمز تحقّق دخول الوليّ. يُخزَّن مُجزّأً (code_hash). طبقة الجوال. */
class OtpCode extends Model
{
    protected $fillable = [
        'phone',
        'code_hash',
        'attempts',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
