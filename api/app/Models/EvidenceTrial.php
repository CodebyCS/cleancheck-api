<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceTrial extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'path',
        'kind',
        'mime_type',
        'size_bytes',
        'original_name',
        'device',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
