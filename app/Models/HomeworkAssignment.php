<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeworkAssignment extends Model
{
    protected $fillable = [
        'section_id',
        'subject',
        'title',
        'description',
        'due_date',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'published_at' => 'datetime',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at');
    }
}
