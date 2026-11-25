<?php

namespace App\Modules\Billing\Observers;

use App\Models\Task;
use App\Modules\Billing\Services\RecordServiceEntry;

/**
 * Watches the shared Task model, so the Tasks module does not need to know about billing.
 * Any path that moves a task to completed gets its single service entry.
 * Runs after the transaction commits; RecordServiceEntry is idempotent.
 */
class TaskCompletionObserver
{
    public bool $afterCommit = true;

    public function __construct(private RecordServiceEntry $entries) {}

    public function updated(Task $task): void
    {
        if ($task->wasChanged('status') && $task->status === 'completed') {
            $this->entries->forTask($task->id);
        }
    }
}
