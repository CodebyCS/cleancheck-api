<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvidence extends Model
{
    protected $table = 'task_evidences';
    
    protected $fillable = [
        'task_id',
        'uploaded_by_user_id',
        'kind',
        'disk',
        'path',
        'mime_type',
        'size_bytes',
        'original_name',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
