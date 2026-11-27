<?php

namespace App\Modules\Tasks\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskExecution;
use App\Modules\Tasks\Http\Requests\StoreTaskExecutionRequest;
use App\Modules\Tasks\Services\RegisterTaskExecution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskExecutionController extends Controller
{
    public function store(StoreTaskExecutionRequest $request, int $task, RegisterTaskExecution $register): JsonResponse
    {
        $result = $register->execute($task, $request->user(), $request->validated());

        return response()->json(['data' => $this->payload($result['record'])], $result['created'] ? 201 : 200);
    }

    public function index(Request $request, int $task): JsonResponse
    {
        $query = Task::query()->whereKey($task);
        if ($request->user()->hasRole('service_provider')) {
            $providerId = $request->user()->serviceProvider?->id;
            abort_if($providerId === null, 403);
            $query->where('service_provider_id', $providerId);
        } else {
            $query->withTrashed();
        }
        $query->firstOrFail();
        $records = TaskExecution::query()->where('task_id', $task)->with('evidences')
            ->orderByDesc('id')->paginate(20);
        $records->through(fn (TaskExecution $record): array => $this->payload($record));

        return response()->json($records);
    }

    private function payload(TaskExecution $record): array
    {
        return [
            'id' => $record->id,
            'task_id' => $record->task_id,
            'user_id' => $record->user_id,
            'idempotency_key' => $record->idempotency_key,
            'responses' => $record->responses,
            'observations' => $record->observations,
            'evidence_ids' => $record->evidences->pluck('id')->all(),
            'send_state' => $record->send_state,
            'confirmed_at' => $record->confirmed_at?->toISOString(),
        ];
    }
}
