<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartTask
{
    public function execute(int $taskId, int $serviceProviderId): Task
    {
        return DB::transaction(function () use ($taskId, $serviceProviderId): Task {
            $task = Task::query()
                ->whereKey($taskId)
                ->where('service_provider_id', $serviceProviderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($task->status === 'in_progress') {
                return $task;
            }

            if ($task->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Apenas tarefas pendentes podem ser iniciadas.',
                ]);
            }

            $task->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);

            return $task->refresh();
        });
    }
}