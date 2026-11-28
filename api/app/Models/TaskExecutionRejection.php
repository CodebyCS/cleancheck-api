<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskExecutionRejection extends Model
{
    protected $fillable = ['task_execution_id', 'rejected_by_user_id', 'reason'];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(TaskExecution::class, 'task_execution_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }
}
