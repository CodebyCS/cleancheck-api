<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteTask
{
    public function execute(int $taskId, int $serviceProviderId): Task
    {
        return DB::transaction(function () use ($taskId, $serviceProviderId): Task {
            $task = Task::query()
                ->whereKey($taskId)
                ->where('service_provider_id', $serviceProviderId)
                ->lockForUpdate()
                ->firstOrFail();

            // Safe retry: if the completion was already saved, return the task without changing the timestamp.
            if ($task->status === 'completed') {
                return $task;
            }

            if ($task->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'status' => 'Apenas tarefas em andamento podem ser concluídas.',
                ]);
            }

            // Completion does not depend on photos or videos.
            $task->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return $task->refresh();
        });
    }
}