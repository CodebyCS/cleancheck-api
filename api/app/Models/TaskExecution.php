<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TaskExecution extends Model
{
    protected $fillable = [
        'task_id', 'user_id', 'idempotency_key', 'payload_hash',
        'responses', 'observations', 'send_state', 'confirmed_at',
    ];

    protected $hidden = ['payload_hash'];

    protected function casts(): array
    {
        return ['responses' => 'array', 'confirmed_at' => 'immutable_datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function evidences(): BelongsToMany
    {
        return $this->belongsToMany(TaskEvidence::class, 'task_execution_evidence');
    }
}
