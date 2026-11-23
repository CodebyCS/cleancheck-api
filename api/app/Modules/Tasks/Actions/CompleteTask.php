<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteTask
{
    /**
     * A service provider can only complete tasks assigned to their profile.
     */
    public function forProvider(
        int $taskId,
        int $serviceProviderId,
        int $userId,
    ): Task {
        return DB::transaction(function () use ($taskId, $serviceProviderId, $userId): Task {
            $task = Task::query()
                ->whereKey($taskId)
                ->where('service_provider_id', $serviceProviderId)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->completeTask($task, $userId);
        });
    }

    /**
     * The calling route must be protected for admin/supervisor.
     */
    public function forStaff(int $taskId, int $userId): Task
    {
        return DB::transaction(function () use ($taskId, $userId): Task {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->completeTask($task, $userId);
        });
    }

    private function completeTask(Task $task, int $userId): Task
    {
        // A repeat does not change the person who completed the task or alter the `completed_at` field.
        if ($task->status === 'completed') {
            return $task;
        }

        if ($task->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'status' => 'Apenas tarefas em andamento podem ser concluídas.',
            ]);
        }

        // The conclusion does not require media.
        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => $userId,
        ]);

        return $task->refresh();
    }
}