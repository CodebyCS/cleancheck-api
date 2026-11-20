<?php

namespace App\Modules\Properties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Modules\Properties\Services\TaskCandidates;
use Illuminate\Http\JsonResponse;

class TaskCandidateController extends Controller
{
    // Only open tasks with a scheduled window can be evaluated.
    public function index(int $task, TaskCandidates $candidates): JsonResponse
    {
        $task = Task::query()->findOrFail($task);

        abort_unless(
            in_array($task->status, ['pending', 'in_progress'], true),
            422,
            'Só é possível procurar candidatos para tarefas pendentes ou em curso.',
        );

        abort_if(
            $task->scheduled_start_at === null || $task->scheduled_end_at === null,
            422,
            'A tarefa não tem horário definido.',
        );

        return response()->json(['data' => $candidates->evaluate($task)]);
    }
}
