<?php

namespace App\Modules\Tasks\Services;

use App\Models\Task;
use App\Models\TaskExecution;
use App\Models\TaskExecutionRejection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RejectTaskExecution
{
    public function execute(int $taskId, int $executionId, User $reviewer, string $reason): TaskExecution
    {
        return DB::transaction(function () use ($taskId, $executionId, $reviewer, $reason): TaskExecution {
            $task = Task::query()->whereKey($taskId)->lockForUpdate()->firstOrFail();
            $execution = TaskExecution::query()->whereKey($executionId)
                ->where('task_id', $taskId)->lockForUpdate()->firstOrFail();

            if ($execution->rejection()->exists()) {
                abort(409, 'Esta execução já foi rejeitada.');
            }

            TaskExecutionRejection::query()->create([
                'task_execution_id' => $execution->id,
                'rejected_by_user_id' => $reviewer->id,
                'reason' => $reason,
            ]);

            // Rejection changes only the task status; execution data and assignment stay intact.
            $task->update(['status' => 'rejected']);

            return $execution->load(['evidences', 'rejection']);
        });
    }
}
