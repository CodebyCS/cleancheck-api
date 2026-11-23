<?php

namespace App\Modules\Tasks\Actions;

use App\Models\Task;
use Illuminate\Validation\ValidationException;

class DeleteTask
{
    private const PROTECTED_STATUSES = ['in_progress', 'completed'];

    public function manual(int $taskId): void
    {
        $task = Task::findOrFail($taskId);

        if (in_array($task->status, self::PROTECTED_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Não é possível apagar uma tarefa em andamento ou concluída.',
            ]);
        }

        $task->delete();
    }
}