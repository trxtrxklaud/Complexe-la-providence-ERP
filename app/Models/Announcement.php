<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إعلان مدرسة (scope=school) أو قسم (scope=section). طبقة الجوال. */
class Announcement extends Model
{
    public const SCOPE_SCHOOL = 'school';

    public const SCOPE_SECTION = 'section';

    protected $fillable = [
        'author_user_id',
        'scope',
        'section_id',
        'title',
        'body',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }
}
